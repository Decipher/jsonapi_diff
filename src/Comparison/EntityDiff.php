<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The comparison of one entity between two revisions.
 *
 * The fields are the entity's own. Entities reached through a reference
 * field are children, each with its own diff.
 */
final readonly class EntityDiff {

  /**
   * This entity's own fields plus every descendant's, counted by status.
   *
   * The summary answers "did this entity change". This answers "did this
   * entity or anything below it change", which is the question a page made
   * of paragraphs is asked. It is summed here rather than by a caller, so
   * every diff carries the same count, and it is summed from the children
   * the tree holds, so an entity dropped for access reasons is counted
   * nowhere.
   *
   * @var array{added: int, removed: int, changed: int, same: int}
   */
  public array $treeSummary;

  /**
   * Constructs an entity diff.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $uuid
   *   The entity UUID. The left side's when both sides hold an entity.
   * @param int|null $leftRevisionId
   *   The left revision id, or null when the entity is absent on the left.
   * @param int|null $rightRevisionId
   *   The right revision id, or null when the entity is absent on the right.
   * @param array<string, \Drupal\jsonapi_diff\Comparison\FieldDiff> $fields
   *   The field diffs keyed by JSON:API public name, in comparison order.
   * @param array{added: int, removed: int, changed: int, same: int} $summary
   *   The count of this entity's own fields by status.
   * @param list<\Drupal\jsonapi_diff\Comparison\ChildDiff> $children
   *   The diffs of the entities reached through reference fields.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of everything read to build this diff.
   * @param string|null $rightUuid
   *   The UUID of the entity on the right side, when that is a different
   *   entity from the one on the left. NULL when both sides hold the same
   *   entity, which is every comparison matched by entity id. It is set
   *   only by the positional pass, which pairs two entities the two sides
   *   hold in the same place.
   */
  public function __construct(
    public string $entityTypeId,
    public string $bundle,
    public string $uuid,
    public ?int $leftRevisionId,
    public ?int $rightRevisionId,
    public array $fields,
    public array $summary,
    public array $children,
    public CacheableMetadata $cacheability,
    public ?string $rightUuid = NULL,
  ) {
    $this->treeSummary = $this->rollUp($summary, $children);
  }

  /**
   * Adds the children's tree summaries to this entity's own summary.
   *
   * Each child was built before its parent and carries its own subtree
   * total, so the whole tree is summed in one pass.
   *
   * @param array{added: int, removed: int, changed: int, same: int} $summary
   *   This entity's own counts.
   * @param list<\Drupal\jsonapi_diff\Comparison\ChildDiff> $children
   *   The children.
   *
   * @return array{added: int, removed: int, changed: int, same: int}
   *   The counts for the whole subtree.
   */
  private function rollUp(array $summary, array $children): array {
    foreach ($children as $child) {
      $child_summary = $child->diff->treeSummary;
      $summary[FieldDiff::ADDED] += $child_summary[FieldDiff::ADDED];
      $summary[FieldDiff::REMOVED] += $child_summary[FieldDiff::REMOVED];
      $summary[FieldDiff::CHANGED] += $child_summary[FieldDiff::CHANGED];
      $summary[FieldDiff::SAME] += $child_summary[FieldDiff::SAME];
    }
    return $summary;
  }

}
