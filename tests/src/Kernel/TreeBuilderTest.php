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

    $this->createTextField('node', 'article', 'field_text', 'Text');
    $this->createTextField('node', 'article', 'field_lines', 'Lines', -1);
    $this->createTextField('node', 'article', 'field_secret', 'Secret');
    $this->createTextField('node', 'article', ResourceTypeBuildSubscriber::ALIASED_FIELD, 'Alias');
    $this->createTextField('node', 'article', ResourceTypeBuildSubscriber::DISABLED_FIELD, 'Hidden');
    $this->createParagraphField('node', 'article', 'field_blocks', 'Blocks');
    $this->createTextField('paragraph', 'block', 'field_body', 'Body');
    $this->createParagraphField('paragraph', 'group', 'field_items', 'Items');

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
   * Collects every compared value in a tree, at any depth.
   */
  protected function values(EntityDiff $diff): string {
    $values = [];
    foreach ($diff->fields as $field) {
      $values[] = $field->label;
      $values[] = $field->left;
      $values[] = $field->right;
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
