<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Traits;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\UserInterface;

/**
 * Builds the content the resource tests compare.
 *
 * An article holds a text field and a paragraph field. A block paragraph
 * holds a text field. Every field is placed in the default view display,
 * because Diff compares nothing it would not display.
 */
trait DiffContentTrait {

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
   * Creates the article and block types with their fields.
   */
  protected function createDiffContentTypes(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ParagraphsType::create(['id' => 'block', 'label' => 'Block'])->save();
    $this->createTextField('node', 'article', 'field_text', 'Text');
    $this->createParagraphField('node', 'article', 'field_blocks', 'Blocks');
    $this->createTextField('paragraph', 'block', 'field_body', 'Body');
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
   * Creates and saves a published article with the given field values.
   */
  protected function createArticle(array $values): NodeInterface {
    $node = Node::create($values + ['type' => 'article', 'title' => 'Article', 'status' => NodeInterface::PUBLISHED]);
    $node->save();
    return $node;
  }

  /**
   * Saves a draft of a node: a newer, unpublished, non-default revision.
   *
   * This is the shape a working copy has without content moderation. The
   * node returned is the default revision, as the route loads it.
   */
  protected function draft(NodeInterface $node, array $values): NodeInterface {
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());
    foreach ($values as $name => $value) {
      $revision->set($name, $value);
    }
    $revision->setNewRevision(TRUE);
    $revision->isDefaultRevision(FALSE);
    $revision->setUnpublished();
    $revision->save();
    return $this->loadNodeRevision((int) $node->getRevisionId());
  }

  /**
   * Saves a new default revision of a node with changed field values.
   */
  protected function revise(NodeInterface $node, array $values): NodeInterface {
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());
    foreach ($values as $name => $value) {
      $revision->set($name, $value);
    }
    $revision->setNewRevision(TRUE);
    $revision->save();
    return $revision;
  }

  /**
   * Maps each block id to the revision a node revision's field points at.
   *
   * @return array<int, int>
   *   Revision ids keyed by paragraph id.
   */
  protected function blockRevisions(NodeInterface $revision): array {
    $targets = [];
    foreach ($revision->get('field_blocks')->getValue() as $item) {
      $targets[(int) $item['target_id']] = (int) $item['target_revision_id'];
    }
    return $targets;
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
   * Creates and saves a block paragraph with a body.
   */
  protected function createBlock(string $body): ParagraphInterface {
    $paragraph = Paragraph::create(['type' => 'block', 'field_body' => $body]);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Saves a new revision of a paragraph, as the node form does on each save.
   */
  protected function reviseBlock(ParagraphInterface $paragraph, ?string $body = NULL): ParagraphInterface {
    if ($body !== NULL) {
      $paragraph->set('field_body', $body);
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
   * Creates the demo scenario: three blocks, then a draft that reshapes them.
   *
   * The draft edits the first block, swaps the second and third, and adds a
   * fourth. Every kept block gets a new revision, as Drupal's own form does.
   *
   * Saving the draft gives every referenced block another revision, as
   * entity_reference_revisions does for any new host revision. The block
   * objects returned are stale on the right side. Read the node revisions
   * for the revision ids each side points at.
   *
   * @return array{\Drupal\node\NodeInterface, list<\Drupal\paragraphs\ParagraphInterface>}
   *   The node's default revision and the four blocks in draft order.
   */
  protected function createReshapedArticle(array $node_values = []): array {
    $first = $this->createBlock('first block');
    $second = $this->createBlock('second block');
    $third = $this->createBlock('third block');
    $node = $this->createArticle(['field_text' => 'published text', 'field_blocks' => $this->references($first, $second, $third)] + $node_values);

    $this->reviseBlock($first, 'first block, edited');
    $this->reviseBlock($second);
    $this->reviseBlock($third);
    $fourth = $this->createBlock('fourth block');
    $node = $this->draft($node, ['field_text' => 'draft text', 'field_blocks' => $this->references($first, $third, $second, $fourth)]);

    return [$node, [$first, $third, $second, $fourth]];
  }

}
