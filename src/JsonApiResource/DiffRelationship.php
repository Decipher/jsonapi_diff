<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\JsonApiResource;

use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\Relationship;
use Drupal\jsonapi\JsonApiResource\RelationshipData;
use Drupal\jsonapi\JsonApiResource\ResourceObject;

/**
 * A relationship built from resource identifiers instead of a field.
 *
 * Core only creates a relationship from an entity reference field. A diff
 * resource is not an entity, so its relationships are assembled from the
 * identifiers directly. The identifiers keep their meta. The normalizer from
 * jsonapi_resources renders any Relationship value under `relationships`.
 */
final class DiffRelationship extends Relationship {

  /**
   * Creates a relationship for a diff resource object.
   *
   * @param \Drupal\jsonapi\JsonApiResource\ResourceObject $context
   *   The resource object the relationship belongs to.
   * @param string $field_name
   *   The public field name.
   * @param \Drupal\jsonapi\JsonApiResource\ResourceIdentifier[] $identifiers
   *   The related resource identifiers, with their meta.
   * @param int $cardinality
   *   One for a to-one relationship, -1 for to-many.
   * @param \Drupal\jsonapi\JsonApiResource\LinkCollection|null $links
   *   The relationship links, if any.
   */
  public static function create(ResourceObject $context, string $field_name, array $identifiers, int $cardinality, ?LinkCollection $links = NULL): self {
    return new self(
      $field_name,
      new RelationshipData($identifiers, $cardinality),
      $links ?? new LinkCollection([]),
      [],
      $context,
    );
  }

}
