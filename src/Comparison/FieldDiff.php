<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Comparison;

/**
 * The comparison of one field between two revisions.
 *
 * The left and right values are the strings the Diff module built for the
 * field. The ops are line operations a client can render without markup.
 *
 * The items are the same comparison made per delta. The field's own status
 * follows from theirs: `same` when every item is, `added` when every item
 * is, `removed` when every item is, and `changed` otherwise. So a field
 * reported as `changed` always has at least one item that is not `same`,
 * and a client can narrow the change to the item that carries it.
 */
final readonly class FieldDiff {

  /**
   * The field has a value on the right side only.
   */
  public const string ADDED = 'added';

  /**
   * The field has a value on the left side only.
   */
  public const string REMOVED = 'removed';

  /**
   * The field has a different value on each side.
   */
  public const string CHANGED = 'changed';

  /**
   * The field has the same value on both sides.
   */
  public const string SAME = 'same';

  /**
   * Constructs a field diff.
   *
   * @param string $label
   *   The field definition's label.
   * @param string $status
   *   One of the status constants.
   * @param string $left
   *   The left value as one string.
   * @param string $right
   *   The right value as one string.
   * @param array<int, array{type: string, lines: list<string>}> $ops
   *   Line operations with a type of '=', '-' or '+'.
   * @param list<\Drupal\jsonapi_diff\Comparison\ItemDiff> $items
   *   The same comparison per delta, in delta order.
   */
  public function __construct(
    public string $label,
    public string $status,
    public string $left,
    public string $right,
    public array $ops,
    public array $items,
  ) {}

}
