<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

/**
 * The comparison of one item of a field between two revisions.
 *
 * A field holds one item per delta. The Diff module builds a string per
 * delta and then joins them, which is why a field carries one status for
 * the whole field. This is that string kept per delta, so a client can
 * narrow a change to the item that carries it.
 *
 * The two sides are paired by delta, because a delta is all a field item
 * has. An item holds no identity of its own, so an item inserted before
 * another shifts it, and the shift reads as a change at both positions.
 * Entities reached through a reference field are matched by entity id
 * instead, one level up, in the children of the entity diff.
 */
final readonly class ItemDiff {

  /**
   * Constructs an item diff.
   *
   * @param int $delta
   *   The delta of the item on both sides.
   * @param string $status
   *   One of the status constants of FieldDiff.
   * @param string $left
   *   The left value of this item, empty when the side has no such item.
   * @param string $right
   *   The right value of this item, empty when the side has no such item.
   * @param array<int, array{type: string, lines: list<string>}> $ops
   *   Line operations for this item, with a type of '=', '-' or '+'.
   *
   * @see \Drupal\jsonapi_diff\Comparison\FieldDiff
   */
  public function __construct(
    public int $delta,
    public string $status,
    public string $left,
    public string $right,
    public array $ops,
  ) {}

}
