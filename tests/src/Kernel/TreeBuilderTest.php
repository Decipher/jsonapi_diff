<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi_diff\Comparison\ChildDiff;
use Drupal\jsonapi_diff\Comparison\EntityDiff;
use Drupal\jsonapi_diff\Comparison\FieldDiff;
use Drupal\jsonapi_diff\Comparison\TreeBuilder;
use Drupal\jsonapi_diff_test\EventSubscriber\ResourceTypeBuildSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
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
    $this->installConfig(['system', 'node', 'diff']);

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
    $left = $this->createNode(['field_text' => 'first draft']);
    $right = $this->saveNewRevision($left, ['field_text' => 'second draft']);

    $diff = $this->builder->build($left, $right);

    $this->assertSame('node', $diff->entityTypeId);
    $this->assertSame('article', $diff->bundle);
    $this->assertSame($left->uuid(), $diff->uuid);
    $this->assertSame((int) $left->getRevisionId(), $diff->leftRevisionId);
    $this->assertSame((int) $right->getRevisionId(), $diff->rightRevisionId);

    $field = $diff->fields["field_text"];
    $this->assertInstanceOf(FieldDiff::class, $field);
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
   * The returned entity is the new revision. The given entity keeps the old
   * one, so the pair can be compared.
   */
  protected function saveNewRevision(NodeInterface $node, array $values): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $revision = $storage->loadRevision($node->getRevisionId());
    $this->assertInstanceOf(NodeInterface::class, $revision);
    foreach ($values as $name => $value) {
      $revision->set($name, $value);
    }
    $revision->setNewRevision(TRUE);
    $revision->save();
    return $revision;
  }

}
