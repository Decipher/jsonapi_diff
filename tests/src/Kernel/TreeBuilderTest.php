<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi\JsonApiResource\LabelOnlyResourceObject;
use Drupal\jsonapi_diff\Comparison\ChildDiff;
use Drupal\jsonapi_diff\Comparison\EntityDiff;
use Drupal\jsonapi_diff\Comparison\FieldDiff;
use Drupal\jsonapi_diff\Comparison\TreeBuilder;
use Drupal\jsonapi_diff_test\EventSubscriber\ResourceTypeBuildSubscriber;
use Drupal\jsonapi_diff_test\TestAccess;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the comparison tree built from a pair of revisions.
 *
 * A node holds text fields and a paragraph field. One paragraph type holds a
 * text field, the other holds a paragraph field of its own for nesting. Each
 * scenario builds two node revisions and reads the tree.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class TreeBuilderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'serialization',
    'jsonapi',
    'diff',
    'jsonapi_diff',
    'jsonapi_diff_test',
  ];

  /**
   * The builder under test.
   */
  protected TreeBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'diff']);
    // A paragraph inherits its parent's view access, so the reader needs
    // what core asks for to read a published node and its revisions. The
    // denials the tests prove are the paragraph's own.
    $this->setUpCurrentUser([], ['access content', 'view all revisions']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ParagraphsType::create(['id' => 'block', 'label' => 'Block'])->save();
    ParagraphsType::create(['id' => 'group', 'label' => 'Group'])->save();
    ParagraphsType::create(['id' => 'internal', 'label' => 'Internal'])->save();

    $this->createTextField('node', 'article', 'field_text', 'Text');
    $this->createTextField('node', 'article', 'field_lines', 'Lines', -1);
    $this->createTextField('node', 'article', 'field_secret', 'Secret');
    $this->createTextField('node', 'article', ResourceTypeBuildSubscriber::ALIASED_FIELD, 'Alias');
    $this->createTextField('node', 'article', ResourceTypeBuildSubscriber::DISABLED_FIELD, 'Hidden');
    $this->createParagraphField('node', 'article', 'field_blocks', 'Blocks');
    $this->createTextField('paragraph', 'block', 'field_body', 'Body');
    $this->createParagraphField('paragraph', 'group', 'field_items', 'Items');
    $this->createTextField('paragraph', 'internal', 'field_note', 'Note');

    $this->builder = $this->container->get('jsonapi_diff.tree_builder');
  }

  /**
   * A changed text field carries removed and added lines.
   */
  public function testChangedTextField(): void {
    [$left, $right] = $this->revise($this->createNode(['field_text' => 'first draft']), ['field_text' => 'second draft']);

    $diff = $this->builder->build($left, $right);

    $this->assertSame('node', $diff->entityTypeId);
    $this->assertSame('article', $diff->bundle);
    $this->assertSame($left->uuid(), $diff->uuid);
    $this->assertSame((int) $left->getRevisionId(), $diff->leftRevisionId);
    $this->assertSame((int) $right->getRevisionId(), $diff->rightRevisionId);

    $field = $diff->fields['field_text'];
    $this->assertSame('Text', $field->label);
    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame('first draft', $field->left);
    $this->assertSame('second draft', $field->right);
    $this->assertSame([
      ['type' => '-', 'lines' => ['first draft']],
      ['type' => '+', 'lines' => ['second draft']],
    ], $field->ops);

    $this->assertSame(1, $diff->summary['changed']);
    $this->assertSame(0, $diff->summary['added']);
    $this->assertSame(0, $diff->summary['removed']);
    $this->assertSame([], $diff->children);
  }

  /**
   * A multi-value field that gained an item shows the added line.
   */
  public function testMultiValueFieldGainsAnItem(): void {
    [$left, $right] = $this->revise($this->createNode(['field_lines' => ['one']]), ['field_lines' => ['one', 'two']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame("one\ntwo", $field->right);
    $this->assertSame([
      ['type' => '=', 'lines' => ['one']],
      ['type' => '+', 'lines' => ['two']],
    ], $field->ops);
  }

  /**
   * One changed item among unchanged ones is reported on its own.
   *
   * This is the case the whole-field status cannot express. The field is
   * `changed`, and only the item at delta 1 is.
   */
  public function testOneChangedItemAmongUnchangedItems(): void {
    $node = $this->createNode(['field_lines' => ['one', 'two', 'three']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['one', 'two changed', 'three']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame([
      0 => FieldDiff::SAME,
      1 => FieldDiff::CHANGED,
      2 => FieldDiff::SAME,
    ], $this->itemStatuses($field));
    $changed = $field->items[1];
    $this->assertSame(1, $changed->delta);
    $this->assertSame('two', $changed->left);
    $this->assertSame('two changed', $changed->right);
    $this->assertSame([
      ['type' => '-', 'lines' => ['two']],
      ['type' => '+', 'lines' => ['two changed']],
    ], $changed->ops);
    $this->assertSame([['type' => '=', 'lines' => ['three']]], $field->items[2]->ops);
  }

  /**
   * An item the right side gained is added, the rest unchanged.
   */
  public function testMultiValueFieldItemAdded(): void {
    $node = $this->createNode(['field_lines' => ['one', 'two']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['one', 'two', 'three']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame([
      0 => FieldDiff::SAME,
      1 => FieldDiff::SAME,
      2 => FieldDiff::ADDED,
    ], $this->itemStatuses($field));
    $added = $field->items[2];
    $this->assertSame('', $added->left);
    $this->assertSame('three', $added->right);
    $this->assertSame([['type' => '+', 'lines' => ['three']]], $added->ops);
  }

  /**
   * An item the right side dropped from the end is removed.
   */
  public function testMultiValueFieldItemRemoved(): void {
    $node = $this->createNode(['field_lines' => ['one', 'two', 'three']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['one', 'two']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame([
      0 => FieldDiff::SAME,
      1 => FieldDiff::SAME,
      2 => FieldDiff::REMOVED,
    ], $this->itemStatuses($field));
    $removed = $field->items[2];
    $this->assertSame('three', $removed->left);
    $this->assertSame('', $removed->right);
    $this->assertSame([['type' => '-', 'lines' => ['three']]], $removed->ops);
  }

  /**
   * One item changed and another added are two statuses in one field.
   */
  public function testMultiValueFieldItemChangedAndAnotherAdded(): void {
    $node = $this->createNode(['field_lines' => ['one', 'two']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['one changed', 'two', 'three']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame([
      0 => FieldDiff::CHANGED,
      1 => FieldDiff::SAME,
      2 => FieldDiff::ADDED,
    ], $this->itemStatuses($field));
  }

  /**
   * A field of cardinality one carries the item at delta 0.
   *
   * Every field has items, so a client iterates them without asking the
   * field's cardinality first, and delta 0 addresses the value.
   */
  public function testSingleValueFieldCarriesOneItem(): void {
    [$left, $right] = $this->revise($this->createNode(['field_text' => 'first draft']), ['field_text' => 'second draft']);

    $field = $this->builder->build($left, $right)->fields['field_text'];

    $this->assertCount(1, $field->items);
    $item = $field->items[0];
    $this->assertSame(0, $item->delta);
    $this->assertSame(FieldDiff::CHANGED, $item->status);
    $this->assertSame('first draft', $item->left);
    $this->assertSame('second draft', $item->right);
    $this->assertSame($field->ops, $item->ops);
  }

  /**
   * Items align by delta, so dropping the first item shifts the rest.
   *
   * A delta is a position, not an identity. The left side holds three items
   * and the right side holds the third one only. Aligning by position pairs
   * `one` with `three` and reports the two trailing positions as removed. A
   * reader looking for "two items removed from the front" does not get it,
   * because a field item carries nothing to match it on across revisions.
   */
  public function testSidesWithDifferentCountsAlignByPosition(): void {
    $node = $this->createNode(['field_lines' => ['one', 'two', 'three']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['three']]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame(FieldDiff::CHANGED, $field->status);
    $this->assertSame([
      0 => FieldDiff::CHANGED,
      1 => FieldDiff::REMOVED,
      2 => FieldDiff::REMOVED,
    ], $this->itemStatuses($field));
    $this->assertSame('one', $field->items[0]->left);
    $this->assertSame('three', $field->items[0]->right);
  }

  /**
   * An item's operations cover that item's lines and no other item's.
   *
   * Both items hold two lines. The whole field's operations read the four
   * lines as one text. Each item's operations stop at the item.
   */
  public function testItemOpsCoverOneItemOnly(): void {
    $node = $this->createNode(['field_lines' => ["one\ntwo", "three\nfour"]]);
    [$left, $right] = $this->revise($node, ['field_lines' => ["one\ntwo", "three\nfive"]]);

    $field = $this->builder->build($left, $right)->fields['field_lines'];

    $this->assertSame([
      ['type' => '=', 'lines' => ['one', 'two', 'three']],
      ['type' => '-', 'lines' => ['four']],
      ['type' => '+', 'lines' => ['five']],
    ], $field->ops);
    $this->assertSame([['type' => '=', 'lines' => ['one', 'two']]], $field->items[0]->ops);
    $this->assertSame([
      ['type' => '=', 'lines' => ['three']],
      ['type' => '-', 'lines' => ['four']],
      ['type' => '+', 'lines' => ['five']],
    ], $field->items[1]->ops);
    $this->assertSame(FieldDiff::SAME, $field->items[0]->status);
    $this->assertSame(FieldDiff::CHANGED, $field->items[1]->status);
  }

  /**
   * A field denied on one side only is absent from both, with no items.
   *
   * The access rule the module takes itself judges every side. The left
   * side of this field is viewable and the right side is not, so neither
   * side is served and no item leaks the value the reader may see.
   */
  public function testFieldDeniedOnOneSideIsAbsentFromBoth(): void {
    $node = $this->createNode(['field_text' => 'text', 'field_lines' => ['visible one']]);
    [$left, $right] = $this->revise($node, ['field_lines' => ['visible one', TestAccess::RESTRICTED_VALUE]]);
    $this->assertTrue($left->get('field_lines')->access('view'));
    $this->assertFalse($right->get('field_lines')->access('view'));

    $diff = $this->builder->build($left, $right);

    $this->assertArrayNotHasKey('field_lines', $diff->fields);
    $this->assertStringNotContainsString('visible one', $this->values($diff));
    $this->assertSame(count($diff->fields), array_sum($diff->summary));
    $this->assertContains(TestAccess::CACHE_TAG, $diff->cacheability->getCacheTags());
  }

  /**
   * Swapped blocks are moved, and their own fields are the same.
   */
  public function testSwappedBlocksAreMoved(): void {
    $first = $this->createParagraph('block', ['field_body' => 'first']);
    $second = $this->createParagraph('block', ['field_body' => 'second']);
    $node = $this->createNode(['field_blocks' => $this->references($first, $second)]);
    $this->reviseParagraph($first);
    $this->reviseParagraph($second);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($second, $first)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $first_child = $this->childFor($diff, $first);
    $this->assertSame('field_blocks', $first_child->field);
    $this->assertSame(ChildDiff::MOVED, $first_child->status);
    $this->assertSame(0, $first_child->leftDelta);
    $this->assertSame(1, $first_child->rightDelta);
    $this->assertAllFields(FieldDiff::SAME, $first_child->diff);

    $second_child = $this->childFor($diff, $second);
    $this->assertSame(ChildDiff::MOVED, $second_child->status);
    $this->assertSame(1, $second_child->leftDelta);
    $this->assertSame(0, $second_child->rightDelta);
    $this->assertAllFields(FieldDiff::SAME, $second_child->diff);
    $this->assertSame('paragraph', $second_child->diff->entityTypeId);
    $this->assertSame('block', $second_child->diff->bundle);
  }

  /**
   * A block only the right side references is added, with every field added.
   */
  public function testAddedBlock(): void {
    $kept = $this->createParagraph('block', ['field_body' => 'kept']);
    $node = $this->createNode(['field_blocks' => $this->references($kept)]);
    $this->reviseParagraph($kept);
    $added = $this->createParagraph('block', ['field_body' => 'new']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($kept, $added)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $kept_child = $this->childFor($diff, $kept);
    $this->assertSame(ChildDiff::SAME, $kept_child->status);
    $this->assertSame(0, $kept_child->leftDelta);
    $this->assertSame(0, $kept_child->rightDelta);

    $added_child = $this->childFor($diff, $added);
    $this->assertSame(ChildDiff::ADDED, $added_child->status);
    $this->assertNull($added_child->leftDelta);
    $this->assertSame(1, $added_child->rightDelta);
    $this->assertNull($added_child->diff->leftRevisionId);
    $this->assertSame((int) $added->getRevisionId(), $added_child->diff->rightRevisionId);
    $this->assertAllFields(FieldDiff::ADDED, $added_child->diff);
    $body = $added_child->diff->fields['field_body'];
    $this->assertSame('', $body->left);
    $this->assertSame('new', $body->right);
    $this->assertSame([['type' => '+', 'lines' => ['new']]], $body->ops);
  }

  /**
   * A block only the left side references is removed, with every field removed.
   */
  public function testRemovedBlock(): void {
    $kept = $this->createParagraph('block', ['field_body' => 'kept']);
    $removed = $this->createParagraph('block', ['field_body' => 'old']);
    $node = $this->createNode(['field_blocks' => $this->references($kept, $removed)]);
    $this->reviseParagraph($kept);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($kept)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $removed_child = $this->childFor($diff, $removed);
    $this->assertSame(ChildDiff::REMOVED, $removed_child->status);
    $this->assertSame(1, $removed_child->leftDelta);
    $this->assertNull($removed_child->rightDelta);
    $this->assertNull($removed_child->diff->rightRevisionId);
    $this->assertAllFields(FieldDiff::REMOVED, $removed_child->diff);
    $this->assertSame([['type' => '-', 'lines' => ['old']]], $removed_child->diff->fields['field_body']->ops);
  }

  /**
   * A draft that replaced every block reports the one block it edited.
   *
   * The reported failure. A script rebuilt the paragraph field from
   * scratch, so no block kept its id and the id pass matched nothing. The
   * positional pass reads the blocks that hold their place as the same
   * blocks, and the draft reads as one edit rather than a new page.
   */
  public function testReplacedBlocksPairByPosition(): void {
    $before_bodies = ['first block', 'second block', 'third block'];
    $after_bodies = ['first block', 'second block, edited', 'third block'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(3, $diff->children);
    $expected_statuses = [ChildDiff::SAME, ChildDiff::SAME, ChildDiff::SAME];
    $this->assertSame($expected_statuses, $this->childStatuses($diff));
    $expected_matches = [ChildDiff::MATCH_POSITION, ChildDiff::MATCH_POSITION, ChildDiff::MATCH_POSITION];
    $this->assertSame($expected_matches, $this->childMatches($diff));

    $edited = $this->childFor($diff, $before[1]);
    $this->assertSame(1, $edited->leftDelta);
    $this->assertSame(1, $edited->rightDelta);
    $this->assertSame($after[1]->uuid(), $edited->diff->rightUuid);
    $body = $edited->diff->fields['field_body'];
    $this->assertSame(FieldDiff::CHANGED, $body->status);
    $this->assertSame('second block', $body->left);
    $this->assertSame('second block, edited', $body->right);

    $this->assertSame(FieldDiff::SAME, $this->childFor($diff, $before[0])->diff->fields['field_body']->status);
    $this->assertSame(FieldDiff::SAME, $this->childFor($diff, $before[2])->diff->fields['field_body']->status);
    $this->assertSame(1, $diff->treeSummary['changed']);
    $this->assertSame(0, $diff->treeSummary['added']);
    $this->assertSame(0, $diff->treeSummary['removed']);
    $this->assertSame($this->treeFieldCount($diff), array_sum($diff->treeSummary));
  }

  /**
   * A swap of two blocks that kept their ids is still read by id.
   *
   * Both blocks hold a new position, so pairing by position would call
   * each of them the other. The id pass runs first and takes them, and
   * their own fields report nothing changed.
   */
  public function testIdMatchTakesPrecedenceOverPosition(): void {
    $first = $this->createParagraph('block', ['field_body' => 'first block']);
    $second = $this->createParagraph('block', ['field_body' => 'second block']);
    $node = $this->createNode(['field_blocks' => $this->references($first, $second)]);
    $this->reviseParagraph($first);
    $this->reviseParagraph($second);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($second, $first)]);

    $diff = $this->builder->build($left, $right);

    $first_child = $this->childFor($diff, $first);
    $this->assertSame(ChildDiff::MOVED, $first_child->status);
    $this->assertSame(ChildDiff::MATCH_ID, $first_child->match);
    $this->assertNull($first_child->diff->rightUuid);
    $this->assertAllFields(FieldDiff::SAME, $first_child->diff);

    $second_child = $this->childFor($diff, $second);
    $this->assertSame(ChildDiff::MOVED, $second_child->status);
    $this->assertSame(ChildDiff::MATCH_ID, $second_child->match);
    $this->assertAllFields(FieldDiff::SAME, $second_child->diff);
  }

  /**
   * Some blocks match by id and the ones left over match by position.
   */
  public function testIdAndPositionalMatchesInOneField(): void {
    $kept = $this->createParagraph('block', ['field_body' => 'kept block']);
    $replaced = $this->createParagraph('block', ['field_body' => 'replaced block']);
    $node = $this->createNode(['field_blocks' => $this->references($kept, $replaced)]);
    $this->reviseParagraph($kept, ['field_body' => 'kept block, edited']);
    $replacement = $this->createParagraph('block', ['field_body' => 'replaced block, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($kept, $replacement)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $kept_child = $this->childFor($diff, $kept);
    $this->assertSame(ChildDiff::SAME, $kept_child->status);
    $this->assertSame(ChildDiff::MATCH_ID, $kept_child->match);
    $this->assertSame(FieldDiff::CHANGED, $kept_child->diff->fields['field_body']->status);

    $replaced_child = $this->childFor($diff, $replaced);
    $this->assertSame(ChildDiff::SAME, $replaced_child->status);
    $this->assertSame(ChildDiff::MATCH_POSITION, $replaced_child->match);
    $this->assertSame(1, $replaced_child->leftDelta);
    $this->assertSame(1, $replaced_child->rightDelta);
    $this->assertSame($replacement->uuid(), $replaced_child->diff->rightUuid);
    $this->assertSame(FieldDiff::CHANGED, $replaced_child->diff->fields['field_body']->status);
    $this->assertSame(2, $diff->treeSummary['changed']);
  }

  /**
   * A block the draft really added sits beside the replaced ones.
   */
  public function testAddedBlockBesideReplacedBlocks(): void {
    $before_bodies = ['first block', 'second block'];
    $after_bodies = ['first block', 'second block', 'third block'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(3, $diff->children);
    $this->assertSame(ChildDiff::MATCH_POSITION, $this->childFor($diff, $before[0])->match);
    $this->assertSame(ChildDiff::MATCH_POSITION, $this->childFor($diff, $before[1])->match);

    $added = $this->childFor($diff, $after[2]);
    $this->assertSame(ChildDiff::ADDED, $added->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $added->match);
    $this->assertNull($added->leftDelta);
    $this->assertSame(2, $added->rightDelta);
    $this->assertAllFields(FieldDiff::ADDED, $added->diff);
  }

  /**
   * A block the draft really removed sits beside the replaced ones.
   */
  public function testRemovedBlockBesideReplacedBlocks(): void {
    $before_bodies = ['first block', 'second block', 'third block'];
    $after_bodies = ['first block', 'second block'];
    [$left, $right, $before] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(3, $diff->children);
    $this->assertSame(ChildDiff::MATCH_POSITION, $this->childFor($diff, $before[0])->match);
    $this->assertSame(ChildDiff::MATCH_POSITION, $this->childFor($diff, $before[1])->match);

    $removed = $this->childFor($diff, $before[2]);
    $this->assertSame(ChildDiff::REMOVED, $removed->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $removed->match);
    $this->assertSame(2, $removed->leftDelta);
    $this->assertNull($removed->rightDelta);
    $this->assertAllFields(FieldDiff::REMOVED, $removed->diff);
  }

  /**
   * Markup does not count towards the content guard.
   *
   * Diff builds a formatted text value with the field's own plugin, so the
   * value arrives with its HTML. Counting tag names would let two unrelated
   * blocks share the `p` of their wrapper, which on short text is enough to
   * reach the threshold and report them as one block that changed.
   */
  public function testMarkupDoesNotPairUnrelatedBlocks(): void {
    $before_bodies = ['<p class="lead">our history</p>'];
    $after_bodies = ['<p class="lead">contact us</p>'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    // The words a reader sees share nothing, so these are two blocks.
    $this->assertCount(2, $diff->children);
    $this->assertSame(ChildDiff::REMOVED, $this->childFor($diff, $before[0])->status);
    $this->assertSame(ChildDiff::ADDED, $this->childFor($diff, $after[0])->status);
  }

  /**
   * An attribute holding a closing bracket is still not text.
   *
   * Dropping everything between angle brackets with a regular expression
   * stops at the first `>`, which leaves the rest of a quoted attribute
   * behind as if a reader could see it. Two unrelated blocks then share that
   * attribute's words and reach the threshold.
   */
  public function testBracketInAttributeDoesNotPairUnrelatedBlocks(): void {
    $before_bodies = ['<p title=">shared">alpha</p>'];
    $after_bodies = ['<p title=">shared">beta</p>'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $this->assertSame(ChildDiff::REMOVED, $this->childFor($diff, $before[0])->status);
    $this->assertSame(ChildDiff::ADDED, $this->childFor($diff, $after[0])->status);
  }

  /**
   * An encoded entity reads as the character it stands for.
   *
   * The parser decodes the entities, so a value written with `&amp;` gives
   * the same words as one written with a bare ampersand.
   */
  public function testAnEncodedEntityReadsAsItsCharacter(): void {
    $before_bodies = ['<p>rates &amp; charges for the year</p>'];
    $after_bodies = ['<p>rates &amp; charges for the term</p>'];
    [$left, $right, $before] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $this->assertSame(ChildDiff::MATCH_POSITION, $this->childFor($diff, $before[0])->match);
  }

  /**
   * An edit inside markup is still a pair.
   *
   * The guard reads the text, so the tags neither help nor hinder a block
   * that a person would call the same block.
   */
  public function testAnEditInsideMarkupStillPairs(): void {
    $before_bodies = ['<p>the second block</p>'];
    $after_bodies = ['<p>the second block, edited</p>'];
    [$left, $right, $before] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $child = $this->childFor($diff, $before[0]);
    $this->assertSame(ChildDiff::MATCH_POSITION, $child->match);
    $this->assertSame(FieldDiff::CHANGED, $child->diff->fields['field_body']->status);
  }

  /**
   * Two bundles at one position are never paired.
   *
   * The bundles decide what a block is. Two of them are different things,
   * whatever they hold, so the position alone does not make them a pair.
   */
  public function testDifferentBundlesAtOnePositionAreNotPaired(): void {
    $block = $this->createParagraph('block', ['field_body' => 'first block']);
    $node = $this->createNode(['field_blocks' => $this->references($block)]);
    $group = $this->createParagraph('group', ['field_items' => []]);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($group)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $removed = $this->childFor($diff, $block);
    $this->assertSame(ChildDiff::REMOVED, $removed->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $removed->match);
    $added = $this->childFor($diff, $group);
    $this->assertSame(ChildDiff::ADDED, $added->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $added->match);
  }

  /**
   * Unrelated text at one position is left as a removal and an addition.
   */
  public function testUnrelatedContentAtOnePositionIsNotPaired(): void {
    $before_bodies = ['our history began in 1902'];
    $after_bodies = ['contact us using the form below'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $this->assertSame(ChildDiff::REMOVED, $this->childFor($diff, $before[0])->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $this->childFor($diff, $before[0])->match);
    $this->assertSame(ChildDiff::ADDED, $this->childFor($diff, $after[0])->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $this->childFor($diff, $after[0])->match);
  }

  /**
   * Two texts that share half their words are paired.
   *
   * The guard is a Dice coefficient over the words of both sides, and it
   * pairs at 0.5. One word shared out of two on each side is exactly
   * that, so this is the weakest pair the guard accepts.
   */
  public function testContentGuardPairsAtItsThreshold(): void {
    $before_bodies = ['alpha beta'];
    $after_bodies = ['alpha gamma'];
    [$left, $right, $before] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $child = $this->childFor($diff, $before[0]);
    $this->assertSame(ChildDiff::MATCH_POSITION, $child->match);
    $this->assertSame(FieldDiff::CHANGED, $child->diff->fields['field_body']->status);
  }

  /**
   * Two texts that share less than half their words are not paired.
   *
   * One word shared out of three on each side scores a third, under the
   * threshold. The two are reported as a removal and an addition, which
   * is what the content supports.
   */
  public function testContentGuardRefusesBelowItsThreshold(): void {
    $before_bodies = ['alpha beta gamma'];
    $after_bodies = ['alpha delta epsilon'];
    [$left, $right, $before, $after] = $this->replaceBlocks($before_bodies, $after_bodies);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(2, $diff->children);
    $this->assertSame(ChildDiff::REMOVED, $this->childFor($diff, $before[0])->status);
    $this->assertSame(ChildDiff::ADDED, $this->childFor($diff, $after[0])->status);
  }

  /**
   * A child the user may not view is neither paired nor exposed.
   *
   * The left side holds a paragraph the reader may not view. The right
   * side holds a new paragraph of the same bundle at the same position,
   * whose words would pass the guard. The denied paragraph leaves the
   * tree before the positional pass runs, so nothing is paired with it
   * and the new paragraph is reported as added.
   */
  public function testDeniedChildIsNeitherPairedNorExposed(): void {
    $denied = $this->createParagraph('block', ['field_body' => 'shared words plus lantern', 'status' => 0]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($denied)]);
    $replacement = $this->createParagraph('block', ['field_body' => 'shared words plus beacon']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($replacement)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $added = $this->childFor($diff, $replacement);
    $this->assertSame(ChildDiff::ADDED, $added->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $added->match);
    $this->assertNull($added->leftDelta);
    $this->assertNull($added->diff->leftRevisionId);
    $this->assertNotContains($denied->uuid(), $this->uuids($diff));
    $this->assertStringNotContainsString('lantern', $this->values($diff));
  }

  /**
   * A replacement the user may not view is not paired either.
   *
   * The denial is on the right side this time. The block the reader may
   * view is reported as removed, and no value of the denied one reaches
   * the document through the pair.
   */
  public function testDeniedReplacementIsNeitherPairedNorExposed(): void {
    $shown = $this->createParagraph('block', ['field_body' => 'shared words plus beacon']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($shown)]);
    $denied = $this->createParagraph('block', ['field_body' => 'shared words plus lantern', 'status' => 0]);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($denied)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $removed = $this->childFor($diff, $shown);
    $this->assertSame(ChildDiff::REMOVED, $removed->status);
    $this->assertSame(ChildDiff::MATCH_NONE, $removed->match);
    $this->assertNull($removed->rightDelta);
    $this->assertNotContains($denied->uuid(), $this->uuids($diff));
    $this->assertStringNotContainsString('lantern', $this->values($diff));
  }

  /**
   * A paragraph inside a paragraph is a child of a child.
   */
  public function testNestedParagraph(): void {
    $inner = $this->createParagraph('block', ['field_body' => 'inner']);
    $group = $this->createParagraph('group', ['field_items' => $this->references($inner)]);
    $node = $this->createNode(['field_blocks' => $this->references($group)]);
    $this->reviseParagraph($inner, ['field_body' => 'inner changed']);
    $this->reviseParagraph($group, ['field_items' => $this->references($inner)]);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($group)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $group_child = $this->childFor($diff, $group);
    $this->assertSame('field_blocks', $group_child->field);
    $this->assertSame(ChildDiff::SAME, $group_child->status);
    $this->assertSame(0, $group_child->diff->summary['changed']);

    $this->assertCount(1, $group_child->diff->children);
    $inner_child = $this->childFor($group_child->diff, $inner);
    $this->assertSame('field_items', $inner_child->field);
    $this->assertSame(ChildDiff::SAME, $inner_child->status);
    $this->assertSame([], $inner_child->diff->children);
    $this->assertSame(FieldDiff::CHANGED, $inner_child->diff->fields['field_body']->status);
    $this->assertSame(1, $inner_child->diff->summary['changed']);
  }

  /**
   * A field is keyed by the public name the resource type gives it.
   */
  public function testPublicNameAlias(): void {
    $internal = ResourceTypeBuildSubscriber::ALIASED_FIELD;
    [$left, $right] = $this->revise($this->createNode([$internal => 'before']), [$internal => 'after']);
    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('node', 'article');
    $public = $resource_type->getPublicName($internal);
    $this->assertSame(ResourceTypeBuildSubscriber::PUBLIC_NAME, $public);

    $fields = $this->builder->build($left, $right)->fields;

    $this->assertArrayHasKey($public, $fields);
    $this->assertArrayNotHasKey($internal, $fields);
    $this->assertSame(FieldDiff::CHANGED, $fields[$public]->status);
    $this->assertSame('Alias', $fields[$public]->label);
  }

  /**
   * A field JSON:API disables is absent even though Diff compared it.
   */
  public function testDisabledFieldIsAbsent(): void {
    $disabled = ResourceTypeBuildSubscriber::DISABLED_FIELD;
    [$left, $right] = $this->revise($this->createNode([$disabled => 'before']), [$disabled => 'after']);
    $flat = $this->container->get('diff.entity_comparison')->compareRevisions($left, $right);
    $this->assertArrayHasKey($left->id() . ':node.' . $disabled, $flat);

    $diff = $this->builder->build($left, $right);

    $this->assertArrayNotHasKey($disabled, $diff->fields);
    $this->assertSame(0, $diff->summary['changed']);
    $this->assertSame(count($diff->fields), array_sum($diff->summary));
  }

  /**
   * A field the user may not view is absent and not counted.
   */
  public function testRestrictedFieldIsAbsent(): void {
    $node = $this->createNode(['field_text' => 'shown', 'field_secret' => 'hidden']);
    [$left, $right] = $this->revise($node, ['field_text' => 'shown changed', 'field_secret' => 'hidden changed']);

    $diff = $this->builder->build($left, $right);

    $this->assertArrayNotHasKey('field_secret', $diff->fields);
    $this->assertArrayHasKey('field_text', $diff->fields);
    $this->assertSame(1, $diff->summary['changed']);
    $this->assertSame(count($diff->fields), array_sum($diff->summary));
  }

  /**
   * The tree summary reports a change the entity's own summary misses.
   *
   * Nothing on the node changed. One paragraph's text did. The node's own
   * summary is therefore all `same`, which is the case a client reading it
   * to ask "did this page change" gets wrong.
   */
  public function testTreeSummaryReportsWhatTheSummaryMisses(): void {
    $block = $this->createParagraph('block', ['field_body' => 'body']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($block)]);
    $this->reviseParagraph($block, ['field_body' => 'body, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($block)]);

    $diff = $this->builder->build($left, $right);

    $child = $this->childFor($diff, $block);
    $this->assertSame(FieldDiff::CHANGED, $child->diff->fields['field_body']->status);
    $this->assertSame(0, $diff->summary['changed']);
    $this->assertSame(count($diff->fields), $diff->summary['same']);
    $this->assertSame(1, $diff->treeSummary['changed']);
    $this->assertSame($diff->summary['same'] + $child->diff->summary['same'], $diff->treeSummary['same']);
    $this->assertSame(array_sum($diff->summary) + array_sum($child->diff->summary), array_sum($diff->treeSummary));
  }

  /**
   * A change on the node and one in a block are both in the tree summary.
   */
  public function testTreeSummaryCountsTheEntityAndItsChildren(): void {
    $block = $this->createParagraph('block', ['field_body' => 'body']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($block)]);
    $this->reviseParagraph($block, ['field_body' => 'body, edited']);
    [$left, $right] = $this->revise($node, ['field_text' => 'text, edited', 'field_blocks' => $this->references($block)]);

    $diff = $this->builder->build($left, $right);

    $this->assertSame(1, $diff->summary['changed']);
    $this->assertSame(2, $diff->treeSummary['changed']);
  }

  /**
   * The sum reaches a paragraph inside a paragraph.
   */
  public function testTreeSummaryIsRecursive(): void {
    $inner = $this->createParagraph('block', ['field_body' => 'inner']);
    $group = $this->createParagraph('group', ['field_items' => $this->references($inner)]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($group)]);
    $this->reviseParagraph($inner, ['field_body' => 'inner changed']);
    $this->reviseParagraph($group, ['field_items' => $this->references($inner)]);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($group)]);

    $diff = $this->builder->build($left, $right);

    $group_diff = $this->childFor($diff, $group)->diff;
    $inner_diff = $this->childFor($group_diff, $inner)->diff;
    $this->assertSame(1, $inner_diff->summary['changed']);
    $this->assertSame(0, $group_diff->summary['changed']);
    $this->assertSame(1, $group_diff->treeSummary['changed']);
    $this->assertSame(0, $diff->summary['changed']);
    $this->assertSame(1, $diff->treeSummary['changed']);
  }

  /**
   * An entity with no children has the same two summaries.
   */
  public function testTreeSummaryOfLeafIsItsSummary(): void {
    $block = $this->createParagraph('block', ['field_body' => 'body']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($block)]);
    $this->reviseParagraph($block, ['field_body' => 'body, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($block)]);

    $leaf = $this->childFor($this->builder->build($left, $right), $block)->diff;

    $this->assertSame([], $leaf->children);
    $this->assertSame($leaf->summary, $leaf->treeSummary);
  }

  /**
   * An added and a removed block count their fields in the rollup.
   */
  public function testTreeSummaryCountsAddedAndRemovedChildren(): void {
    $kept = $this->createParagraph('block', ['field_body' => 'kept']);
    $removed = $this->createParagraph('block', ['field_body' => 'old']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($kept, $removed)]);
    $this->reviseParagraph($kept);
    $added = $this->createParagraph('block', ['field_body' => 'new']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($kept, $added)]);

    $diff = $this->builder->build($left, $right);

    $added_diff = $this->childFor($diff, $added)->diff;
    $removed_diff = $this->childFor($diff, $removed)->diff;
    $this->assertSame(0, $diff->summary['added']);
    $this->assertSame(0, $diff->summary['removed']);
    $this->assertSame(count($added_diff->fields), $diff->treeSummary['added']);
    $this->assertSame(count($removed_diff->fields), $diff->treeSummary['removed']);
    $this->assertSame(
      $diff->summary['same'] + $this->childFor($diff, $kept)->diff->summary['same'],
      $diff->treeSummary['same'],
    );
  }

  /**
   * A child the user may not view is counted nowhere in the rollup.
   */
  public function testDeniedChildIsNotCountedInTheTreeSummary(): void {
    $shown = $this->createParagraph('block', ['field_body' => 'shown body']);
    $denied = $this->createParagraph('block', ['field_body' => 'denied body', 'status' => 0]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($shown, $denied)]);
    $this->reviseParagraph($shown);
    $this->reviseParagraph($denied, ['field_body' => 'denied body, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($shown, $denied)]);

    $diff = $this->builder->build($left, $right);

    $shown_diff = $this->childFor($diff, $shown)->diff;
    $this->assertCount(1, $diff->children);
    $this->assertSame(0, $diff->treeSummary['changed']);
    $this->assertSame(array_sum($diff->summary) + array_sum($shown_diff->summary), array_sum($diff->treeSummary));
  }

  /**
   * A revision compared with itself has the same everything.
   */
  public function testSameRevision(): void {
    $block = $this->createParagraph('block', ['field_body' => 'body']);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($block)]);
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());

    $diff = $this->builder->build($revision, $revision);

    $this->assertSame($diff->leftRevisionId, $diff->rightRevisionId);
    $this->assertAllFields(FieldDiff::SAME, $diff);
    $this->assertSame([['type' => '=', 'lines' => ['text']]], $diff->fields['field_text']->ops);
    $child = $this->childFor($diff, $block);
    $this->assertSame(ChildDiff::SAME, $child->status);
    $this->assertAllFields(FieldDiff::SAME, $child->diff);
  }

  /**
   * The root carries the tags of every entity in the tree, children their own.
   */
  public function testCacheability(): void {
    $first = $this->createParagraph('block', ['field_body' => 'first']);
    $second = $this->createParagraph('block', ['field_body' => 'second']);
    $node = $this->createNode(['field_blocks' => $this->references($first, $second)]);
    $this->reviseParagraph($first, ['field_body' => 'first changed']);
    $this->reviseParagraph($second);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($first, $second)]);

    $diff = $this->builder->build($left, $right);

    $tags = $diff->cacheability->getCacheTags();
    $this->assertContains('node:' . $node->id(), $tags);
    $this->assertContains('paragraph:' . $first->id(), $tags);
    $this->assertContains('paragraph:' . $second->id(), $tags);
    $this->assertContains('config:diff.plugins', $tags);
    $this->assertContains('config:diff.settings', $tags);

    $first_tags = $this->childFor($diff, $first)->diff->cacheability->getCacheTags();
    $this->assertContains('paragraph:' . $first->id(), $first_tags);
    $this->assertContains('config:diff.plugins', $first_tags);
    $this->assertNotContains('paragraph:' . $second->id(), $first_tags);
    // A paragraph inherits its parent's view access, so the node the access
    // decision read is part of the child's own cacheability.
    $this->assertContains('node:' . $node->id(), $first_tags);
  }

  /**
   * A child the user may not view is absent, and an allowed one stays.
   */
  public function testDeniedChildIsAbsent(): void {
    $shown = $this->createParagraph('block', ['field_body' => 'shown body']);
    $denied = $this->createParagraph('block', ['field_body' => 'denied body', 'status' => 0]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($shown, $denied)]);
    $this->reviseParagraph($shown, ['field_body' => 'shown body, edited']);
    $this->reviseParagraph($denied, ['field_body' => 'denied body, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($shown, $denied)]);

    $diff = $this->builder->build($left, $right);

    $this->assertNotContains($denied->uuid(), $this->uuids($diff));
    $this->assertStringNotContainsString('denied body', $this->values($diff));
    $this->assertCount(1, $diff->children);
    $shown_child = $this->childFor($diff, $shown);
    $this->assertSame(0, $shown_child->leftDelta);
    $this->assertSame(0, $shown_child->rightDelta);
    $this->assertSame(FieldDiff::CHANGED, $shown_child->diff->fields['field_body']->status);
    // The parent counts its own fields only, and the dropped child changed
    // none of them.
    $this->assertSame(0, $diff->summary['changed']);
    $this->assertSame(count($diff->fields), array_sum($diff->summary));
  }

  /**
   * A denied child keeps the delta of the child that is still shown.
   *
   * The deltas locate a child in the parent's field. Dropping the child at
   * delta 0 must not renumber the child at delta 1.
   */
  public function testDroppedChildDoesNotRenumberTheRest(): void {
    $denied = $this->createParagraph('block', ['field_body' => 'denied body', 'status' => 0]);
    $shown = $this->createParagraph('block', ['field_body' => 'shown body']);
    $node = $this->createNode(['field_blocks' => $this->references($denied, $shown)]);
    $this->reviseParagraph($denied);
    $this->reviseParagraph($shown);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($shown, $denied)]);

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $shown_child = $this->childFor($diff, $shown);
    $this->assertSame(1, $shown_child->leftDelta);
    $this->assertSame(0, $shown_child->rightDelta);
    $this->assertSame(ChildDiff::MOVED, $shown_child->status);
  }

  /**
   * A denied child inside an allowed child is absent, the allowed one stays.
   */
  public function testDeniedChildNestedDeeperIsAbsent(): void {
    $denied = $this->createParagraph('block', ['field_body' => 'denied body', 'status' => 0]);
    $group = $this->createParagraph('group', ['field_items' => $this->references($denied)]);
    $node = $this->createNode(['field_blocks' => $this->references($group)]);
    $this->reviseParagraph($denied, ['field_body' => 'denied body, edited']);
    $this->reviseParagraph($group, ['field_items' => $this->references($denied)]);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($group)]);

    $diff = $this->builder->build($left, $right);

    $this->assertNotContains($denied->uuid(), $this->uuids($diff));
    $this->assertStringNotContainsString('denied body', $this->values($diff));
    $this->assertCount(1, $diff->children);
    $group_child = $this->childFor($diff, $group);
    $this->assertSame([], $group_child->diff->children);
  }

  /**
   * A label-only access result is a denial, as it is for the root.
   *
   * An unpublished paragraph that is its own default revision is the case
   * core answers with a label-only resource object: view is denied, the
   * label is not. One node revision compared with itself points both sides
   * at that paragraph revision, so neither side is a hard denial.
   */
  public function testLabelOnlyChildIsDenied(): void {
    $denied = $this->createParagraph('block', ['field_body' => 'denied body', 'status' => 0]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($denied)]);
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());
    $this->assertSame([(int) $denied->getRevisionId()], $this->targetRevisions($revision));
    $checked = $this->container->get('jsonapi_diff_test.entity_access_checker')->getAccessCheckedResourceObject($denied);
    $this->assertInstanceOf(LabelOnlyResourceObject::class, $checked);

    $diff = $this->builder->build($revision, $revision);

    $this->assertSame([], $diff->children);
    $this->assertStringNotContainsString('denied body', $this->values($diff));
  }

  /**
   * A child denied on one side only is absent from both sides.
   */
  public function testChildDeniedOnOneSideIsAbsentFromBoth(): void {
    $child = $this->createParagraph('block', ['field_body' => 'published body']);
    $node = $this->createNode(['field_blocks' => $this->references($child)]);
    $child->set('field_body', 'unpublished body');
    $child->setUnpublished();
    $this->reviseParagraph($child);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($child)]);

    $diff = $this->builder->build($left, $right);

    $this->assertSame([], $diff->children);
    $this->assertStringNotContainsString('published body', $this->values($diff));
  }

  /**
   * A child whose resource type JSON:API disables is absent.
   */
  public function testInternalResourceTypeChildIsAbsent(): void {
    $shown = $this->createParagraph('block', ['field_body' => 'shown body']);
    $internal = $this->createParagraph('internal', ['field_note' => 'internal note']);
    $node = $this->createNode(['field_blocks' => $this->references($shown, $internal)]);
    $this->reviseParagraph($shown);
    $this->reviseParagraph($internal, ['field_note' => 'internal note, edited']);
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references($shown, $internal)]);
    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('paragraph', 'internal');
    $this->assertTrue($resource_type->isInternal());

    $diff = $this->builder->build($left, $right);

    $this->assertCount(1, $diff->children);
    $this->assertNotContains($internal->uuid(), $this->uuids($diff));
    $this->assertStringNotContainsString('internal note', $this->values($diff));
  }

  /**
   * Every access decision behind the tree is in its cacheability.
   *
   * The field access rule of the test module varies by user and carries a
   * tag. The paragraph access handler carries the Paragraphs settings. A
   * response shaped by either has to say so.
   */
  public function testAccessDecisionsAreCacheable(): void {
    $child = $this->createParagraph('block', ['field_body' => 'body']);
    $node = $this->createNode(['field_text' => 'text', 'field_secret' => 'hidden', 'field_blocks' => $this->references($child)]);
    $this->reviseParagraph($child);
    [$left, $right] = $this->revise($node, ['field_text' => 'text changed']);

    $cacheability = $this->builder->build($left, $right)->cacheability;

    $this->assertContains(TestAccess::CACHE_TAG, $cacheability->getCacheTags());
    $this->assertContains('user', $cacheability->getCacheContexts());
    $this->assertContains('config:paragraphs.settings', $cacheability->getCacheTags());
  }

  /**
   * The tree is judged for the account the resolver judged, not the session.
   *
   * The route only ever resolves for the current user today. This pins the
   * account the pair carries, so a caller that passes one cannot have the
   * root judged for that account and the tree for another.
   */
  public function testTreeFollowsTheResolvedAccount(): void {
    // Paragraphs only honours the permission when the site opts in.
    $this->installConfig(['paragraphs']);
    $draft = $this->createParagraph('block', ['field_body' => 'reviewer only body', 'status' => 0]);
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references($draft)]);
    $reviewer = $this->asAccount($this->createUser(['access content', 'view all revisions', 'view unpublished paragraphs']));
    $this->assertTrue($draft->access('view', $reviewer));
    $this->assertFalse($draft->access('view'));
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());

    $pair = $this->container->get('jsonapi_diff.revision_pair_resolver')->resolve($revision, NULL, NULL, $reviewer);
    $tree = $this->builder->build($pair->left, $pair->right, $pair->account);

    $this->assertSame($reviewer->id(), $pair->account?->id());
    $this->assertCount(1, $tree->children);
    $this->assertSame($draft->uuid(), $tree->children[0]->diff->uuid);
    $this->assertStringContainsString('reviewer only body', $this->values($tree));

    // The same pair, built for the session's user, drops the paragraph.
    $session = $this->builder->build($pair->left, $pair->right);
    $this->assertSame([], $session->children);
    $this->assertStringNotContainsString('reviewer only body', $this->values($session));
  }

  /**
   * Narrows a created user to an account.
   *
   * Drupal 10's stubs return User|false from the user creation helpers, and
   * Drupal 11's return UserInterface. The parameter is untyped so the check
   * is neither redundant on 11 nor missing on 10.
   */
  protected function asAccount(mixed $account): UserInterface {
    if (!$account instanceof UserInterface) {
      throw new \LogicException('The user was not created.');
    }
    return $account;
  }

  /**
   * Lists the paragraph revisions a node revision's blocks point at.
   *
   * @return list<int>
   *   The target revision ids, in delta order.
   */
  protected function targetRevisions(NodeInterface $revision): array {
    return array_values(array_map(
      static fn (array $item): int => (int) $item['target_revision_id'],
      $revision->get('field_blocks')->getValue(),
    ));
  }

  /**
   * Creates a node whose draft replaced every block with a new entity.
   *
   * No block keeps its id, which is what a script that rebuilds the field
   * from scratch does. The bodies say what each side holds.
   *
   * @param list<string> $before_bodies
   *   The body of each block the published revision holds.
   * @param list<string> $after_bodies
   *   The body of each block the draft holds.
   *
   * @return array{\Drupal\node\NodeInterface, \Drupal\node\NodeInterface, list<\Drupal\paragraphs\ParagraphInterface>, list<\Drupal\paragraphs\ParagraphInterface>}
   *   The two revisions, then the blocks of each side in delta order.
   */
  protected function replaceBlocks(array $before_bodies, array $after_bodies): array {
    $before = [];
    foreach ($before_bodies as $body) {
      $before[] = $this->createParagraph('block', ['field_body' => $body]);
    }
    $node = $this->createNode(['field_text' => 'text', 'field_blocks' => $this->references(...$before)]);
    $after = [];
    foreach ($after_bodies as $body) {
      $after[] = $this->createParagraph('block', ['field_body' => $body]);
    }
    [$left, $right] = $this->revise($node, ['field_blocks' => $this->references(...$after)]);
    return [$left, $right, $before, $after];
  }

  /**
   * Lists the status of each child of a diff, in order.
   *
   * @return list<string>
   *   The statuses.
   */
  protected function childStatuses(EntityDiff $diff): array {
    return array_map(static fn (ChildDiff $child): string => $child->status, $diff->children);
  }

  /**
   * Lists how each child of a diff was matched, in order.
   *
   * @return list<string>
   *   The match values.
   */
  protected function childMatches(EntityDiff $diff): array {
    return array_map(static fn (ChildDiff $child): string => $child->match, $diff->children);
  }

  /**
   * Counts the fields of a diff and of every diff below it.
   */
  protected function treeFieldCount(EntityDiff $diff): int {
    $count = count($diff->fields);
    foreach ($diff->children as $child) {
      $count += $this->treeFieldCount($child->diff);
    }
    return $count;
  }

  /**
   * Lists the status of each of a field's items, keyed by delta.
   *
   * @return array<int, string>
   *   The statuses.
   */
  protected function itemStatuses(FieldDiff $field): array {
    $statuses = [];
    foreach ($field->items as $item) {
      $statuses[$item->delta] = $item->status;
    }
    return $statuses;
  }

  /**
   * Collects every compared value in a tree, at any depth.
   */
  protected function values(EntityDiff $diff): string {
    $values = [];
    foreach ($diff->fields as $field) {
      $values[] = $field->label;
      $values[] = $field->left;
      $values[] = $field->right;
      foreach ($field->items as $item) {
        $values[] = $item->left;
        $values[] = $item->right;
      }
    }
    foreach ($diff->children as $child) {
      $values[] = $this->values($child->diff);
    }
    return implode("\n", $values);
  }

  /**
   * Collects the UUID of every entity in a tree, at any depth.
   *
   * @return list<string>
   *   The UUIDs.
   */
  protected function uuids(EntityDiff $diff): array {
    $uuids = [$diff->uuid];
    foreach ($diff->children as $child) {
      $uuids = array_merge($uuids, $this->uuids($child->diff));
    }
    return $uuids;
  }

  /**
   * Creates a text field and shows it in the default view display.
   */
  protected function createTextField(string $entity_type_id, string $bundle, string $name, string $label, int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type_id,
      'type' => 'text',
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'label' => $label,
    ])->save();
    $this->showInViewDisplay($entity_type_id, $bundle, $name, 'text_default');
  }

  /**
   * Creates a paragraph reference field and shows it in the view display.
   */
  protected function createParagraphField(string $entity_type_id, string $bundle, string $name, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type_id,
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'label' => $label,
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => NULL],
      ],
    ])->save();
    $this->showInViewDisplay($entity_type_id, $bundle, $name, 'entity_reference_revisions_entity_view');
  }

  /**
   * Adds a field to the default view display so Diff compares it.
   */
  protected function showInViewDisplay(string $entity_type_id, string $bundle, string $name, string $formatter): void {
    $display = EntityViewDisplay::load("$entity_type_id.$bundle.default")
      ?? EntityViewDisplay::create([
        'targetEntityType' => $entity_type_id,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $display->setComponent($name, ['type' => $formatter])->save();
  }

  /**
   * Creates and saves an article with the given field values.
   */
  protected function createNode(array $values): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => 'Article'] + $values);
    $node->save();
    return $node;
  }

  /**
   * Saves a new revision of a node with changed field values.
   *
   * Returns the old and the new revision, both freshly loaded, so neither
   * holds objects the other side changed.
   *
   * @return array{\Drupal\node\NodeInterface, \Drupal\node\NodeInterface}
   *   The left and right revisions.
   */
  protected function revise(NodeInterface $node, array $values): array {
    $left_id = (int) $node->getRevisionId();
    $revision = $this->loadNodeRevision($left_id);
    foreach ($values as $name => $value) {
      $revision->set($name, $value);
    }
    $revision->setNewRevision(TRUE);
    $revision->save();
    return [$this->loadNodeRevision($left_id), $this->loadNodeRevision((int) $revision->getRevisionId())];
  }

  /**
   * Loads one node revision.
   */
  protected function loadNodeRevision(int $revision_id): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $revision = $storage->loadRevision($revision_id);
    $this->assertInstanceOf(NodeInterface::class, $revision);
    return $revision;
  }

  /**
   * Creates and saves a paragraph of a type with the given field values.
   */
  protected function createParagraph(string $type, array $values): ParagraphInterface {
    $paragraph = Paragraph::create(['type' => $type] + $values);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Saves a new revision of a paragraph, as the node form does on each save.
   */
  protected function reviseParagraph(ParagraphInterface $paragraph, array $values = []): ParagraphInterface {
    foreach ($values as $name => $value) {
      $paragraph->set($name, $value);
    }
    $paragraph->setNewRevision(TRUE);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Builds the items of a paragraph field pointing at the given revisions.
   *
   * @return list<array{target_id: int, target_revision_id: int}>
   *   The field items.
   */
  protected function references(ParagraphInterface ...$paragraphs): array {
    return array_values(array_map(static fn (ParagraphInterface $paragraph): array => [
      'target_id' => (int) $paragraph->id(),
      'target_revision_id' => (int) $paragraph->getRevisionId(),
    ], $paragraphs));
  }

  /**
   * Finds the child of a diff for one entity.
   */
  protected function childFor(EntityDiff $diff, ParagraphInterface $paragraph): ChildDiff {
    foreach ($diff->children as $child) {
      if ($child->diff->uuid === $paragraph->uuid()) {
        return $child;
      }
    }
    $this->fail('No child for paragraph ' . $paragraph->id());
  }

  /**
   * Asserts that every field of a diff has one status.
   */
  protected function assertAllFields(string $status, EntityDiff $diff): void {
    $this->assertNotEmpty($diff->fields);
    foreach ($diff->fields as $name => $field) {
      $this->assertSame($status, $field->status, "Field $name");
    }
    $this->assertSame(count($diff->fields), $diff->summary[$status]);
  }

}
