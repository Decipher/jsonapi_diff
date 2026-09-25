<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Diff\Engine\DiffOpAdd;
use Drupal\Component\Diff\Engine\DiffOpChange;
use Drupal\Component\Diff\Engine\DiffOpCopy;
use Drupal\Component\Diff\Engine\DiffOpDelete;
use Drupal\Component\Utility\Html;
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

  /**
   * The lowest word similarity that still pairs two children by position.
   *
   * A Dice coefficient of 0.5 is the point where two texts share as many
   * words as they do not. Below it, calling the pair one block that
   * changed claims more than the content supports, so the two are left as
   * an honest removal and addition.
   */
  private const float SIMILARITY_THRESHOLD = 0.5;

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
   * Reads both sides and keeps each side's fields under its own keys.
   *
   * The keys are the parser's, `{entity id}:{entity type}.{field name}`,
   * for every entity in the tree. The two sides are kept apart rather than
   * merged, because a comparison does not always hold the same entity on
   * both sides. A pair the positional pass made holds two entities, so its
   * left values sit under one entity's keys and its right values under
   * another's.
   *
   * @return array{left: array<string, array{label: string, values: array<int, string>}>, right: array<string, array{label: string, values: array<int, string>}>}
   *   The per-delta values of each side, keyed by entity and field.
   */
  private function compare(ContentEntityInterface $left, ContentEntityInterface $right): array {
    return [
      'left' => $this->sideValues($this->diffEntityParser->parseEntity($left)),
      'right' => $this->sideValues($this->diffEntityParser->parseEntity($right)),
    ];
  }

  /**
   * Reduces one side's parsed result to a label and values per field.
   *
   * @param array<string, array<int|string, mixed>> $parsed
   *   The parser's result for one revision.
   *
   * @return array<string, array{label: string, values: array<int, string>}>
   *   The label and per-delta values, keyed by entity and field.
   */
  private function sideValues(array $parsed): array {
    $side = [];
    foreach ($parsed as $key => $build) {
      $side[$key] = [
        'label' => (string) ($build['label'] ?? ''),
        'values' => $this->itemValues($build),
      ];
    }
    return $side;
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
   * @param array{left: array<string, array{label: string, values: array<int, string>}>, right: array<string, array{label: string, values: array<int, string>}>} $flat
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
    $left_prefix = $left instanceof ContentEntityInterface ? $this->prefixOf($left) : NULL;
    $right_prefix = $right instanceof ContentEntityInterface ? $this->prefixOf($right) : NULL;
    $names = $this->namesUnder($flat['left'], $left_prefix) + $this->namesUnder($flat['right'], $right_prefix);
    foreach (array_keys($names) as $name) {
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      if (!$this->fieldIsViewable($left, $right, $name, $collector, $account)) {
        continue;
      }
      $left_entry = $left_prefix === NULL ? NULL : ($flat['left'][$left_prefix . $name] ?? NULL);
      $right_entry = $right_prefix === NULL ? NULL : ($flat['right'][$right_prefix . $name] ?? NULL);
      $definition = $entity->getFieldDefinition($name);
      $label = $definition !== NULL
        ? (string) $definition->getLabel()
        : ($left_entry['label'] ?? $right_entry['label'] ?? '');
      $field = $this->buildField(
        $label,
        $left_entry['values'] ?? [],
        $right_entry['values'] ?? [],
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
      $right instanceof ContentEntityInterface && $right->uuid() !== $entity->uuid() ? (string) $right->uuid() : NULL,
    );
  }

  /**
   * Builds the key prefix the parser gives one entity's fields.
   */
  private function prefixOf(ContentEntityInterface $entity): string {
    return $entity->id() . ':' . $entity->getEntityTypeId() . '.';
  }

  /**
   * Lists the field names one side's values hold under a key prefix.
   *
   * @param array<string, array{label: string, values: array<int, string>}> $side
   *   One side's parsed values, keyed by entity and field.
   * @param string|null $prefix
   *   The key prefix of the entity, or NULL when that side lacks it.
   *
   * @return array<string, true>
   *   The field names, in the order the parser produced them.
   */
  private function namesUnder(array $side, ?string $prefix): array {
    if ($prefix === NULL) {
      return [];
    }
    $names = [];
    foreach (array_keys($side) as $key) {
      if (str_starts_with($key, $prefix)) {
        $names[substr($key, strlen($prefix))] = TRUE;
      }
    }
    return $names;
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
   * Children are matched across sides by entity id first. The children no
   * id matched are then paired by position, which reads a draft that
   * replaced its blocks with new entities as an edit rather than as a
   * page replacement. Each child records which pass found it.
   *
   * Left children come first, in delta order, then children only the right
   * side has. A child the user may not view on one side is dropped from
   * both before either pass runs, so an entity is never reported as added
   * or removed because access to it changed, and a denied entity is never
   * paired with anything.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $left
   *   The left revision of the parent, or NULL when it has none.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $right
   *   The right revision of the parent, or NULL when it has none.
   * @param array{left: array<string, array{label: string, values: array<int, string>}>, right: array<string, array{label: string, values: array<int, string>}>} $flat
   *   The whole flat result of the root comparison.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type of the parent.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   Collects the cacheability of every access decision taken here.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account every access decision is taken for.
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
      $pairs = $this->pairByPosition(
        array_diff_key($left_children, $right_children),
        array_diff_key($right_children, $left_children),
        $flat,
        $collector,
        $account,
      );
      $claimed = array_flip($pairs);

      foreach ($left_children as $id => [$left_delta, $left_child]) {
        if (isset($right_children[$id])) {
          [$right_delta, $right_child] = $right_children[$id];
          $status = $left_delta === $right_delta ? ChildDiff::SAME : ChildDiff::MOVED;
          $children[] = new ChildDiff($public_name, $left_delta, $right_delta, $status, ChildDiff::MATCH_ID, $this->buildEntity($left_child, $right_child, $flat, $collector, FALSE, $account));
        }
        elseif (isset($pairs[$id])) {
          // A positional pair sits at one delta on both sides, so its
          // status is always `same`. What changed is inside the pair.
          [$right_delta, $right_child] = $right_children[$pairs[$id]];
          $children[] = new ChildDiff($public_name, $left_delta, $right_delta, ChildDiff::SAME, ChildDiff::MATCH_POSITION, $this->buildEntity($left_child, $right_child, $flat, $collector, FALSE, $account));
        }
        else {
          $children[] = new ChildDiff($public_name, $left_delta, NULL, ChildDiff::REMOVED, ChildDiff::MATCH_NONE, $this->buildEntity($left_child, NULL, $flat, $collector, FALSE, $account));
        }
      }
      foreach (array_diff_key($right_children, $left_children, $claimed) as [$right_delta, $right_child]) {
        $children[] = new ChildDiff($public_name, NULL, $right_delta, ChildDiff::ADDED, ChildDiff::MATCH_NONE, $this->buildEntity(NULL, $right_child, $flat, $collector, FALSE, $account));
      }
    }
    return $children;
  }

  /**
   * Pairs the children no id matched by the position they hold.
   *
   * Two children are a pair when they sit at the same delta of the same
   * reference field, are of the same entity type and bundle, and hold
   * content the guard accepts as two versions of one block. The delta is
   * the one the field gives, not a position within the leftovers, so a
   * child an id matched keeps its slot and the children around it are not
   * renumbered into a pairing they do not deserve.
   *
   * The pairing is a guess. It is made because the alternative, reporting
   * every block as removed and added, is not more honest, it is only less
   * useful. Each pair says how it was found, so a client can tell the
   * guess from an exact match.
   *
   * @param array<int|string, array{int, \Drupal\Core\Entity\ContentEntityInterface}> $left
   *   The left children no id matched, keyed by entity id.
   * @param array<int|string, array{int, \Drupal\Core\Entity\ContentEntityInterface}> $right
   *   The right children no id matched, keyed by entity id.
   * @param array{left: array<string, array{label: string, values: array<int, string>}>, right: array<string, array{label: string, values: array<int, string>}>} $flat
   *   The whole flat result of the root comparison.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   Collects the cacheability of every access decision taken here.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account every access decision is taken for.
   *
   * @return array<int|string, int|string>
   *   The id of the right child each paired left child was matched with.
   */
  private function pairByPosition(array $left, array $right, array $flat, CacheableMetadata $collector, ?AccountInterface $account): array {
    $by_delta = [];
    foreach ($right as $id => [$delta, $entity]) {
      $by_delta[$delta] = [$id, $entity];
    }
    $pairs = [];
    foreach ($left as $id => [$delta, $entity]) {
      if (!isset($by_delta[$delta])) {
        continue;
      }
      [$candidate_id, $candidate] = $by_delta[$delta];
      if ($entity->getEntityTypeId() !== $candidate->getEntityTypeId() || $entity->bundle() !== $candidate->bundle()) {
        continue;
      }
      if (!$this->contentAgrees($entity, $candidate, $flat, $collector, $account)) {
        continue;
      }
      $pairs[$id] = $candidate_id;
    }
    return $pairs;
  }

  /**
   * Decides whether two entities hold enough in common to be one block.
   *
   * The measure is the Dice coefficient over the words of the fields the
   * document would report for both of them. Two entities that share every
   * word score 1, two that share none score 0, and an edit to a block
   * scores near the top of that range. The threshold is the point where
   * the two texts have as much in common as they do not.
   *
   * The words are read from the fields the requesting account may view on
   * both entities, and from no others, so a field the account cannot read
   * never decides what the document says.
   *
   * Two entities with no comparable text of their own cannot be judged on
   * their content. A paragraph whose every field recurses is the usual
   * case. The bundle and the position then decide alone.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $left
   *   The candidate from the left side.
   * @param \Drupal\Core\Entity\ContentEntityInterface $right
   *   The candidate from the right side.
   * @param array{left: array<string, array{label: string, values: array<int, string>}>, right: array<string, array{label: string, values: array<int, string>}>} $flat
   *   The whole flat result of the root comparison.
   * @param \Drupal\Core\Cache\CacheableMetadata $collector
   *   Collects the cacheability of every access decision taken here.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account every access decision is taken for.
   */
  private function contentAgrees(ContentEntityInterface $left, ContentEntityInterface $right, array $flat, CacheableMetadata $collector, ?AccountInterface $account): bool {
    $resource_type = $this->resourceTypeRepository->get($left->getEntityTypeId(), $left->bundle());
    $left_prefix = $this->prefixOf($left);
    $right_prefix = $this->prefixOf($right);
    $names = $this->namesUnder($flat['left'], $left_prefix) + $this->namesUnder($flat['right'], $right_prefix);

    $left_words = [];
    $right_words = [];
    foreach (array_keys($names) as $name) {
      if (!$resource_type->isFieldEnabled($name)) {
        continue;
      }
      if (!$this->fieldIsViewable($left, $right, $name, $collector, $account)) {
        continue;
      }
      $left_words = array_merge($left_words, $this->words($flat['left'][$left_prefix . $name]['values'] ?? []));
      $right_words = array_merge($right_words, $this->words($flat['right'][$right_prefix . $name]['values'] ?? []));
    }

    $total = count($left_words) + count($right_words);
    if ($total === 0) {
      return TRUE;
    }
    $right_counts = array_count_values($right_words);
    $common = 0;
    foreach (array_count_values($left_words) as $word => $count) {
      $common += min($count, $right_counts[$word] ?? 0);
    }
    return (2 * $common) / $total >= self::SIMILARITY_THRESHOLD;
  }

  /**
   * Splits one field's values into lowercase words.
   *
   * Punctuation and case are dropped, so a value that only gained a comma
   * still reads as the same words.
   *
   * Only the text a reader sees counts. Diff builds each side with the
   * field's own plugin, so a formatted text field arrives with its HTML, and
   * a tag or attribute name is not a word. Counting them lets two unrelated
   * blocks share the `p` of their paragraph wrapper, which on short text is
   * enough to pass the similarity guard.
   *
   * The markup is parsed rather than pattern matched. A regular expression
   * that drops everything between angle brackets stops at the first `>`, so
   * a `>` inside a quoted attribute leaks the rest of that attribute into
   * the text.
   *
   * @param array<int, string> $values
   *   The value of each delta.
   *
   * @return list<string>
   *   The words, in order.
   */
  private function words(array $values): array {
    $text = trim(implode(' ', $values));
    if ($text === '') {
      return [];
    }
    $parts = [];
    foreach ((new \DOMXPath(Html::load($text)))->query('//text()') ?: [] as $node) {
      $parts[] = $node->nodeValue ?? '';
    }
    // The text nodes are joined with a space, so the last word inside one
    // element does not join the first word of the next. The parser has
    // already decoded the entities.
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $parts)), -1, PREG_SPLIT_NO_EMPTY);
    return $words === FALSE ? [] : $words;
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
