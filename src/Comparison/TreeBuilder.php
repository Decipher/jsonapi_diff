<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Diff\Engine\DiffOpAdd;
use Drupal\Component\Diff\Engine\DiffOpChange;
use Drupal\Component\Diff\Engine\DiffOpCopy;
use Drupal\Component\Diff\Engine\DiffOpDelete;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\diff\DiffBuilderManager;
use Drupal\diff\DiffEntityComparison;
use Drupal\diff\FieldReferenceInterface;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;

/**
 * Builds the comparison tree for a pair of revisions.
 *
 * The comparison is the Diff module's. It compares the root pair once and
 * returns every field in the tree as a flat list keyed by entity and field.
 * This class walks the reference fields itself to recover the structure Diff
 * discards, attributes each flat entry to its entity, and turns the compared
 * strings into line operations.
 */
final class TreeBuilder {

  /**
   * Cache tags of the Diff configuration that decides what is compared.
   */
  private const array CONFIG_CACHE_TAGS = ['config:diff.plugins', 'config:diff.settings'];

  public function __construct(
    private readonly DiffEntityComparison $entityComparison,
    private readonly DiffBuilderManager $builderManager,
    private readonly ResourceTypeRepositoryInterface $resourceTypeRepository,
  ) {}

  /**
   * Builds the tree for two revisions of the same entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $left
   *   The left revision.
   * @param \Drupal\Core\Entity\ContentEntityInterface $right
   *   The right revision.
   *
   * @return \Drupal\jsonapi_diff\Comparison\EntityDiff
   *   The root diff. Its cacheability covers the whole tree.
   */
  public function build(ContentEntityInterface $left, ContentEntityInterface $right): EntityDiff {
    $flat = $this->entityComparison->compareRevisions($left, $right);
    return $this->buildEntity($left, $right, $flat, new CacheableMetadata(), TRUE);
  }

  /**
   * Builds the diff of one entity, present on one or both sides.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $left
   *   The left revision, or null when the left side lacks the entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $right
   *   The right revision, or null when the right side lacks the entity.
   * @param array<string, array<string, mixed>> $flat
   *   The whole flat result of the root comparison.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   The root's cacheability, which collects the whole tree.
   * @param bool $is_root
   *   TRUE for the root entity, whose cacheability is the collector itself.
   */
  private function buildEntity(?ContentEntityInterface $left, ?ContentEntityInterface $right, array $flat, CacheableMetadata $collector, bool $is_root): EntityDiff {
    $entity = $left ?? $right;
    assert($entity instanceof ContentEntityInterface);
    $resource_type = $this->resourceTypeRepository->get($entity->getEntityTypeId(), $entity->bundle());

    $cacheability = (new CacheableMetadata())->addCacheTags(self::CONFIG_CACHE_TAGS);
    foreach ([$left, $right] as $side) {
      if ($side !== NULL) {
        $cacheability->addCacheableDependency($side);
      }
    }
    $collector->addCacheableDependency($cacheability);

    $fields = [];
    $summary = [
      FieldDiff::ADDED => 0,
      FieldDiff::REMOVED => 0,
      FieldDiff::CHANGED => 0,
      FieldDiff::SAME => 0,
    ];
    $prefix = $entity->id() . ':' . $entity->getEntityTypeId() . '.';
    foreach ($flat as $key => $entry) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      $name = substr($key, strlen($prefix));
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      $definition = $entity->getFieldDefinition($name);
      $label = $definition !== NULL ? (string) $definition->getLabel() : (string) $entry['#name'];
      $field = $this->buildField(
        $label,
        (string) $entry['#data']['#left'],
        (string) $entry['#data']['#right'],
        $left === NULL,
        $right === NULL,
      );
      $fields[$resource_type->getPublicName($name)] = $field;
      $summary[$field->status]++;
    }

    $children = $this->buildChildren($left, $right, $flat, $resource_type, $collector);

    return new EntityDiff(
      $entity->getEntityTypeId(),
      $entity->bundle(),
      (string) $entity->uuid(),
      $left !== NULL ? (int) $left->getRevisionId() : NULL,
      $right !== NULL ? (int) $right->getRevisionId() : NULL,
      $fields,
      $summary,
      $children,
      $is_root ? $collector : $cacheability,
    );
  }

  /**
   * Turns one flat entry into a field diff with line operations.
   *
   * Diff gives an empty string for a side that has no value. An empty side
   * is therefore an absent side, the same as when the entity itself is
   * missing on that side.
   */
  private function buildField(string $label, string $left, string $right, bool $left_absent, bool $right_absent): FieldDiff {
    if ($left_absent) {
      $status = FieldDiff::ADDED;
    }
    elseif ($right_absent) {
      $status = FieldDiff::REMOVED;
    }
    elseif ($left === $right) {
      $status = FieldDiff::SAME;
    }
    elseif ($left === '') {
      $status = FieldDiff::ADDED;
    }
    elseif ($right === '') {
      $status = FieldDiff::REMOVED;
    }
    else {
      $status = FieldDiff::CHANGED;
    }
    return new FieldDiff($label, $status, $left, $right, $this->buildOps($left, $right));
  }

  /**
   * Computes line operations between two strings with core's Diff component.
   *
   * @return array<int, array{type: string, lines: list<string>}>
   *   The operations in order.
   */
  private function buildOps(string $left, string $right): array {
    $diff = new Diff(self::lines($left), self::lines($right));
    $ops = [];
    foreach ($diff->getEdits() as $edit) {
      if ($edit instanceof DiffOpCopy) {
        $ops[] = ['type' => '=', 'lines' => array_values((array) $edit->orig)];
      }
      elseif ($edit instanceof DiffOpDelete) {
        $ops[] = ['type' => '-', 'lines' => array_values((array) $edit->orig)];
      }
      elseif ($edit instanceof DiffOpAdd) {
        $ops[] = ['type' => '+', 'lines' => array_values((array) $edit->closing)];
      }
      elseif ($edit instanceof DiffOpChange) {
        $ops[] = ['type' => '-', 'lines' => array_values((array) $edit->orig)];
        $ops[] = ['type' => '+', 'lines' => array_values((array) $edit->closing)];
      }
    }
    return $ops;
  }

  /**
   * Splits a value into lines. An empty value has no lines.
   *
   * @return list<string>
   *   The lines.
   */
  private static function lines(string $value): array {
    return $value === '' ? [] : explode("\n", $value);
  }

  /**
   * Walks the reference fields Diff recursed into and matches the children.
   *
   * A field is walked under the same rules Diff's parser applies: it must be
   * one Diff shows, the user must be able to view it, and its builder plugin
   * must provide entities to diff. That keeps the structure in step with the
   * flat result.
   *
   * Children are matched across sides by entity id. Left children come
   * first, in delta order, then children only the right side has.
   *
   * @return list<\Drupal\jsonapi_diff\Comparison\ChildDiff>
   *   The children.
   */
  private function buildChildren(?ContentEntityInterface $left, ?ContentEntityInterface $right, array $flat, ResourceType $resource_type, CacheableMetadata $collector): array {
    $entity = $left ?? $right;
    assert($entity instanceof ContentEntityInterface);
    $children = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      if (!$this->builderManager->showDiff($definition->getFieldStorageDefinition())) {
        continue;
      }
      $plugin = $this->builderManager->createInstanceForFieldDefinition($definition);
      if (!$plugin instanceof FieldReferenceInterface) {
        continue;
      }
      $left_children = $this->childrenOfSide($plugin, $left, $name);
      $right_children = $this->childrenOfSide($plugin, $right, $name);
      $public_name = $resource_type->getPublicName($name);

      foreach ($left_children as $id => [$left_delta, $left_child]) {
        if (isset($right_children[$id])) {
          [$right_delta, $right_child] = $right_children[$id];
          $status = $left_delta === $right_delta ? ChildDiff::SAME : ChildDiff::MOVED;
          $children[] = new ChildDiff($public_name, $left_delta, $right_delta, $status, $this->buildEntity($left_child, $right_child, $flat, $collector, FALSE));
        }
        else {
          $children[] = new ChildDiff($public_name, $left_delta, NULL, ChildDiff::REMOVED, $this->buildEntity($left_child, NULL, $flat, $collector, FALSE));
        }
      }
      foreach (array_diff_key($right_children, $left_children) as [$right_delta, $right_child]) {
        $children[] = new ChildDiff($public_name, NULL, $right_delta, ChildDiff::ADDED, $this->buildEntity(NULL, $right_child, $flat, $collector, FALSE));
      }
    }
    return $children;
  }

  /**
   * Lists the entities one side references through a field.
   *
   * @return array<int|string, array{int, \Drupal\Core\Entity\ContentEntityInterface}>
   *   Delta and entity, keyed by entity id, in delta order.
   */
  private function childrenOfSide(FieldReferenceInterface $plugin, ?ContentEntityInterface $side, string $name): array {
    if ($side === NULL || !$side->hasField($name)) {
      return [];
    }
    $items = $side->get($name);
    if (!$items->access('view')) {
      return [];
    }
    $children = [];
    foreach ($plugin->getEntitiesToDiff($items) as $delta => $child) {
      if ($child instanceof ContentEntityInterface) {
        $children[$child->id()] = [(int) $delta, $child];
      }
    }
    return $children;
  }

}
