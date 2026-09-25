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
use Drupal\Core\Session\AccountInterface;
use Drupal\diff\DiffBuilderManager;
use Drupal\diff\DiffEntityParser;
use Drupal\diff\FieldReferenceInterface;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi_diff\Access\EntityViewCheck;

/**
 * Builds the comparison tree for a pair of revisions.
 *
 * The values are the Diff module's. Each side is read once through Diff's
 * entity parser, which applies the field type builder plugins, the rules
 * that decide which fields are compared at all, and its own recursion
 * through reference fields. The result is a flat list of fields keyed by
 * entity and field, with one string per delta.
 *
 * The parser is read rather than Diff's entity comparison service, which
 * joins every delta of a field into one newline-separated string before a
 * caller sees it. Reading the parser keeps the deltas, so a field reports a
 * status per item as well as one for the whole field.
 *
 * This class walks the reference fields itself to recover the structure
 * Diff discards, attributes each flat entry to its entity, and turns the
 * compared strings into line operations.
 */
final readonly class TreeBuilder {

  /**
   * Cache tags of the Diff configuration that decides what is compared.
   */
  private const array CONFIG_CACHE_TAGS = ['config:diff.plugins', 'config:diff.settings'];

  public function __construct(
    private DiffEntityParser $diffEntityParser,
    private DiffBuilderManager $diffBuilderManager,
    private ResourceTypeRepositoryInterface $resourceTypeRepository,
    private EntityViewCheck $entityViewCheck,
  ) {}

  /**
   * Builds the tree for two revisions of the same entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $left
   *   The left revision.
   * @param \Drupal\Core\Entity\ContentEntityInterface $right
   *   The right revision.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the two revisions were judged for, or NULL for the
   *   current user. Every entity and field below them is judged for the
   *   same account, so one account decides the whole document.
   *
   * @return \Drupal\jsonapi_diff\Comparison\EntityDiff
   *   The root diff. Its cacheability covers the whole tree.
   */
  public function build(ContentEntityInterface $left, ContentEntityInterface $right, ?AccountInterface $account = NULL): EntityDiff {
    return $this->buildEntity($left, $right, $this->compare($left, $right), new CacheableMetadata(), TRUE, $account);
  }

  /**
   * Reads both sides and pairs their fields by key and by delta.
   *
   * The keys are the parser's, `{entity id}:{entity type}.{field name}`,
   * for every entity in the tree. A field only one side holds keeps its
   * key and gets no values on the other side. The order is the left side's
   * fields, then the fields only the right side has, which is the order
   * Diff's own comparison produces.
   *
   * @return array<string, array{label: string, left: array<int, string>, right: array<int, string>}>
   *   The per-delta values of both sides, keyed by entity and field.
   */
  private function compare(ContentEntityInterface $left, ContentEntityInterface $right): array {
    $left_values = $this->diffEntityParser->parseEntity($left);
    $right_values = $this->diffEntityParser->parseEntity($right);

    $flat = [];
    foreach ($left_values + $right_values as $key => $build) {
      $flat[$key] = [
        'label' => (string) ($build['label'] ?? ''),
        'left' => isset($left_values[$key]) ? $this->itemValues($left_values[$key]) : [],
        'right' => isset($right_values[$key]) ? $this->itemValues($right_values[$key]) : [],
      ];
    }
    return $flat;
  }

  /**
   * Reduces one field's parsed build to one string per delta.
   *
   * A builder plugin indexes its output by delta and gives each delta
   * either a string or a list of strings. The image plugin gives an array
   * holding a render array for the thumbnail beside the value. A delta a
   * plugin left out, such as an empty item, has no entry, so the deltas
   * are not always a run from zero.
   *
   * @param array<int|string, mixed> $build
   *   One field's entry in the parser's result, including its label.
   *
   * @return array<int, string>
   *   The value of each delta, in delta order.
   *
   * @see \Drupal\diff\DiffEntityComparison::combineFields()
   */
  private function itemValues(array $build): array {
    $values = [];
    foreach ($build as $delta => $value) {
      if (!is_int($delta)) {
        continue;
      }
      if (is_array($value) && isset($value['#thumbnail'])) {
        $value = $value['data'] ?? '';
      }
      $values[$delta] = is_array($value) ? implode("\n", $value) : (string) $value;
    }
    ksort($values);
    return $values;
  }

  /**
   * Builds the diff of one entity, present on one or both sides.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $left
   *   The left revision, or null when the left side lacks the entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $right
   *   The right revision, or null when the right side lacks the entity.
   * @param array<string, array{label: string, left: array<int, string>, right: array<int, string>}> $flat
   *   The whole flat result of the root comparison.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   The root's cacheability, which collects the whole tree.
   * @param bool $is_root
   *   TRUE for the root entity, whose cacheability is the collector itself.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account every access decision is taken for.
   */
  private function buildEntity(?ContentEntityInterface $left, ?ContentEntityInterface $right, array $flat, CacheableMetadata $collector, bool $is_root, ?AccountInterface $account): EntityDiff {
    $entity = $left ?? $right;
    assert($entity instanceof ContentEntityInterface);
    $resource_type = $this->resourceTypeRepository->get($entity->getEntityTypeId(), $entity->bundle());

    $cacheability = (new CacheableMetadata())->addCacheTags(self::CONFIG_CACHE_TAGS);
    foreach ([$left, $right] as $side) {
      if ($side instanceof ContentEntityInterface) {
        $cacheability->addCacheableDependency($side);
      }
    }
    $collector->addCacheableDependency($cacheability);

    $fields = [];
    $prefix = $entity->id() . ':' . $entity->getEntityTypeId() . '.';
    foreach ($flat as $key => $entry) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      $name = substr($key, strlen($prefix));
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      if (!$this->fieldIsViewable($left, $right, $name, $collector, $account)) {
        continue;
      }
      $definition = $entity->getFieldDefinition($name);
      $label = $definition !== NULL ? (string) $definition->getLabel() : $entry['label'];
      $field = $this->buildField(
        $label,
        $entry['left'],
        $entry['right'],
        !$left instanceof ContentEntityInterface,
        !$right instanceof ContentEntityInterface,
      );
      $fields[$resource_type->getPublicName($name)] = $field;
    }
    $counts = array_count_values(array_map(static fn (FieldDiff $field): string => $field->status, $fields));
    $summary = [
      FieldDiff::ADDED => $counts[FieldDiff::ADDED] ?? 0,
      FieldDiff::REMOVED => $counts[FieldDiff::REMOVED] ?? 0,
      FieldDiff::CHANGED => $counts[FieldDiff::CHANGED] ?? 0,
      FieldDiff::SAME => $counts[FieldDiff::SAME] ?? 0,
    ];

    $children = $this->buildChildren($left, $right, $flat, $resource_type, $collector, $account);

    return new EntityDiff(
      $entity->getEntityTypeId(),
      $entity->bundle(),
      (string) $entity->uuid(),
      $left instanceof ContentEntityInterface ? (int) $left->getRevisionId() : NULL,
      $right instanceof ContentEntityInterface ? (int) $right->getRevisionId() : NULL,
      $fields,
      $summary,
      $children,
      $is_root ? $collector : $cacheability,
    );
  }

  /**
   * Decides whether a field may be viewed on every side that holds it.
   *
   * Diff's parser already drops a field the user may not view, but it
   * returns no cacheability, so the decision is taken again here and
   * collected. A field denied on one side is dropped from both, so a
   * denial is never worked around by reading the other side.
   */
  private function fieldIsViewable(?ContentEntityInterface $left, ?ContentEntityInterface $right, string $name, CacheableMetadata $collector, ?AccountInterface $account): bool {
    $viewable = FALSE;
    foreach ([$left, $right] as $side) {
      if (!$side instanceof ContentEntityInterface || !$side->hasField($name)) {
        continue;
      }
      $access = $side->get($name)->access('view', $account, TRUE);
      $collector->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        return FALSE;
      }
      $viewable = TRUE;
    }
    return $viewable;
  }

  /**
   * Turns one flat entry into a field diff, per item and as a whole.
   *
   * The whole-field values are the per-delta values joined with a newline,
   * which is the string Diff's own comparison service hands a caller. The
   * items are the same comparison made per delta.
   *
   * @param string $label
   *   The field label.
   * @param array<int, string> $left_items
   *   The left value of each delta.
   * @param array<int, string> $right_items
   *   The right value of each delta.
   * @param bool $left_absent
   *   TRUE when the entity itself is missing on the left.
   * @param bool $right_absent
   *   TRUE when the entity itself is missing on the right.
   */
  private function buildField(string $label, array $left_items, array $right_items, bool $left_absent, bool $right_absent): FieldDiff {
    $deltas = array_keys($left_items + $right_items);
    sort($deltas);
    $items = [];
    foreach ($deltas as $delta) {
      $item_left = $left_items[$delta] ?? '';
      $item_right = $right_items[$delta] ?? '';
      $items[] = new ItemDiff(
        $delta,
        $this->statusOf($item_left, $item_right, $left_absent, $right_absent),
        $item_left,
        $item_right,
        $this->buildOps($item_left, $item_right),
      );
    }

    $left = implode("\n", $left_items);
    $right = implode("\n", $right_items);
    return new FieldDiff(
      $label,
      $this->statusOfItems($items, $left, $right, $left_absent, $right_absent),
      $left,
      $right,
      $this->buildOps($left, $right),
      $items,
    );
  }

  /**
   * Decides the status of one comparison of two strings.
   *
   * Diff gives an empty string for a side that has no value. An empty side
   * is therefore an absent side, the same as when the entity itself is
   * missing on that side.
   */
  private function statusOf(string $left, string $right, bool $left_absent, bool $right_absent): string {
    if ($left_absent) {
      return FieldDiff::ADDED;
    }
    if ($right_absent) {
      return FieldDiff::REMOVED;
    }
    if ($left === $right) {
      return FieldDiff::SAME;
    }
    if ($left === '') {
      return FieldDiff::ADDED;
    }
    if ($right === '') {
      return FieldDiff::REMOVED;
    }
    return FieldDiff::CHANGED;
  }

  /**
   * Decides the status of a whole field from the statuses of its items.
   *
   * One status for every item is that status. Anything else is a change,
   * because some of the field changed and some of it did not. The two can
   * then never disagree: a field reported as `same` has no item that is
   * not, and a field reported as `changed` has at least one item that is
   * not `same`.
   *
   * A field with no items at all is judged on its joined values, which is
   * the only thing left to judge it on.
   *
   * @param list<\Drupal\jsonapi_diff\Comparison\ItemDiff> $items
   *   The items of the field.
   * @param string $left
   *   The joined left value.
   * @param string $right
   *   The joined right value.
   * @param bool $left_absent
   *   TRUE when the entity itself is missing on the left.
   * @param bool $right_absent
   *   TRUE when the entity itself is missing on the right.
   */
  private function statusOfItems(array $items, string $left, string $right, bool $left_absent, bool $right_absent): string {
    if ($items === []) {
      return $this->statusOf($left, $right, $left_absent, $right_absent);
    }
    $statuses = array_values(array_unique(array_map(static fn (ItemDiff $item): string => $item->status, $items)));
    return count($statuses) === 1 ? $statuses[0] : FieldDiff::CHANGED;
  }

  /**
   * Computes line operations between two strings with core's Diff component.
   *
   * @return array<int, array{type: string, lines: list<string>}>
   *   The operations in order.
   */
  private function buildOps(string $left, string $right): array {
    $diff = new Diff($this->lines($left), $this->lines($right));
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
  private function lines(string $value): array {
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
   * first, in delta order, then children only the right side has. A child
   * the user may not view on one side is dropped from both, so an entity is
   * never reported as added or removed because access to it changed.
   *
   * @return list<\Drupal\jsonapi_diff\Comparison\ChildDiff>
   *   The children.
   */
  private function buildChildren(?ContentEntityInterface $left, ?ContentEntityInterface $right, array $flat, ResourceType $resource_type, CacheableMetadata $collector, ?AccountInterface $account): array {
    $entity = $left ?? $right;
    assert($entity instanceof ContentEntityInterface);
    $children = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      if (!$this->diffBuilderManager->showDiff($definition->getFieldStorageDefinition())) {
        continue;
      }
      $plugin = $this->diffBuilderManager->createInstanceForFieldDefinition($definition);
      if (!$plugin instanceof FieldReferenceInterface) {
        continue;
      }
      $denied = [];
      $left_children = $this->childrenOfSide($plugin, $left, $name, $collector, $denied, $account);
      $right_children = $this->childrenOfSide($plugin, $right, $name, $collector, $denied, $account);
      if ($left_children === NULL || $right_children === NULL) {
        continue;
      }
      $left_children = array_diff_key($left_children, $denied);
      $right_children = array_diff_key($right_children, $denied);
      $public_name = $resource_type->getPublicName($name);

      foreach ($left_children as $id => [$left_delta, $left_child]) {
        if (isset($right_children[$id])) {
          [$right_delta, $right_child] = $right_children[$id];
          $status = $left_delta === $right_delta ? ChildDiff::SAME : ChildDiff::MOVED;
          $children[] = new ChildDiff($public_name, $left_delta, $right_delta, $status, $this->buildEntity($left_child, $right_child, $flat, $collector, FALSE, $account));
        }
        else {
          $children[] = new ChildDiff($public_name, $left_delta, NULL, ChildDiff::REMOVED, $this->buildEntity($left_child, NULL, $flat, $collector, FALSE, $account));
        }
      }
      foreach (array_diff_key($right_children, $left_children) as [$right_delta, $right_child]) {
        $children[] = new ChildDiff($public_name, NULL, $right_delta, ChildDiff::ADDED, $this->buildEntity(NULL, $right_child, $flat, $collector, FALSE, $account));
      }
    }
    return $children;
  }

  /**
   * Lists the entities one side references through a field.
   *
   * Every candidate is access checked here, where it is produced, so an
   * entity the user may not view never enters the tree and cannot be
   * attributed a field value anywhere. Its id is recorded, so the other
   * side drops it too.
   *
   * @param \Drupal\diff\FieldReferenceInterface $plugin
   *   The Diff builder plugin of the reference field.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $side
   *   The revision of this side, or NULL when the side lacks the entity.
   * @param string $name
   *   The field name.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   Collects the cacheability of every access decision taken here.
   * @param array<int|string, true> $denied
   *   Ids of the children no side may serve. Added to by this method.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account every access decision is taken for.
   *
   * @return array<int|string, array{int, \Drupal\Core\Entity\ContentEntityInterface}>|null
   *   Delta and entity, keyed by entity id, in delta order. NULL when the
   *   field itself may not be viewed, which drops it from both sides.
   */
  private function childrenOfSide(FieldReferenceInterface $plugin, ?ContentEntityInterface $side, string $name, CacheableMetadata $collector, array &$denied, ?AccountInterface $account): ?array {
    if (!$side instanceof ContentEntityInterface || !$side->hasField($name)) {
      return [];
    }
    $items = $side->get($name);
    $access = $items->access('view', $account, TRUE);
    $collector->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return NULL;
    }
    $children = [];
    foreach ($plugin->getEntitiesToDiff($items) as $delta => $child) {
      if (!$child instanceof ContentEntityInterface) {
        continue;
      }
      if (!$this->isServable($child, $collector, $account)) {
        $denied[$child->id()] = TRUE;
        continue;
      }
      $children[$child->id()] = [(int) $delta, $child];
    }
    return $children;
  }

  /**
   * Decides whether one child entity may appear in the document.
   *
   * A resource type the site does not expose is skipped, as the root is on
   * its own route. The rest is the access rule the compared revisions are
   * judged by.
   */
  private function isServable(ContentEntityInterface $child, CacheableMetadata $collector, ?AccountInterface $account): bool {
    $resource_type = $this->resourceTypeRepository->get($child->getEntityTypeId(), $child->bundle());
    if (!$resource_type instanceof ResourceType || $resource_type->isInternal()) {
      return FALSE;
    }
    return $this->entityViewCheck->isViewable($child, $account, $collector);
  }

}
