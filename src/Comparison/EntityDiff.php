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
   * Constructs an entity diff.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $uuid
   *   The entity UUID.
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
  ) {}

}
