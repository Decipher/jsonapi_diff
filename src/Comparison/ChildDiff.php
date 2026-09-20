<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

/**
 * The place of a child entity's diff under its parent.
 *
 * The deltas locate the child in the parent's reference field on each side.
 * A delta is null on the side where the parent does not reference the child.
 *
 * The status says where the child sits. The match says how the two sides
 * were brought together, because they are not always the same entity. A
 * client that renders a pair needs both: the status to place it, the match
 * to say how much to trust it.
 */
final readonly class ChildDiff {

  /**
   * The child is referenced on the right side only.
   */
  public const string ADDED = 'added';

  /**
   * The child is referenced on the left side only.
   */
  public const string REMOVED = 'removed';

  /**
   * The child is referenced on both sides at different deltas.
   */
  public const string MOVED = 'moved';

  /**
   * The child is referenced on both sides at the same delta.
   */
  public const string SAME = 'same';

  /**
   * The same entity on both sides, found by entity id. Exact.
   */
  public const string MATCH_ID = 'id';

  /**
   * Two different entities, paired because they share a position. Inferred.
   */
  public const string MATCH_POSITION = 'position';

  /**
   * The child is on one side only, so nothing was paired with it.
   */
  public const string MATCH_NONE = 'none';

  /**
   * Constructs a child diff.
   *
   * @param string $field
   *   The JSON:API public name of the parent's reference field.
   * @param int|null $leftDelta
   *   The delta on the left side, or null when absent there.
   * @param int|null $rightDelta
   *   The delta on the right side, or null when absent there.
   * @param string $status
   *   One of the status constants.
   * @param string $match
   *   One of the match constants.
   * @param \Drupal\jsonapi_diff\Comparison\EntityDiff $diff
   *   The child's own diff.
   */
  public function __construct(
    public string $field,
    public ?int $leftDelta,
    public ?int $rightDelta,
    public string $status,
    public string $match,
    public EntityDiff $diff,
  ) {}

}
