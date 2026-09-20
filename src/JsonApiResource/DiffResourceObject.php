<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\JsonApiResource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceIdentifier;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeAttribute;
use Drupal\jsonapi\ResourceType\ResourceTypeRelationship;
use Drupal\jsonapi_diff\Comparison\ChildDiff;
use Drupal\jsonapi_diff\Comparison\EntityDiff;
use Drupal\jsonapi_diff\Comparison\FieldDiff;

/**
 * The JSON:API resource object of one entity's diff.
 *
 * The shape is fixed here. `summary`, `tree_summary` and `fields` are
 * attributes. `summary` counts this entity's own fields and `tree_summary`
 * counts the whole subtree below it, which is the count a page made of
 * paragraphs needs. `left` and
 * `right` point at the compared entity, with the version in the identifier's
 * meta and the individual URL of that version as the `related` link.
 * `children` lists the diffs of the entities the comparison recursed into,
 * with each child's place in the parent recorded in its identifier's meta.
 *
 * A sparse fieldset needs nothing here. Core's resource object normalizer
 * keeps the members the fieldset names and drops the rest, and it does that
 * for every resource of the type, in `included` as well as in the primary
 * data.
 *
 * @see \Drupal\jsonapi\Normalizer\ResourceObjectNormalizer::doNormalize()
 */
final class DiffResourceObject extends ResourceObject {

  /**
   * The resource type name of every diff resource.
   */
  public const string TYPE_NAME = 'jsonapi_diff--diff';

  /**
   * The query parameter that names the left version.
   *
   * JSON:API reserves all-lowercase query parameter names for itself, so core
   * rejects `left` before the route runs. This name carries a capital for the
   * same reason core's own `resourceVersion` does.
   *
   * @see \Drupal\jsonapi\JsonApiSpec::isValidCustomQueryParameter()
   */
  public const string LEFT_PARAMETER = 'leftVersion';

  /**
   * The query parameter that names the right version.
   */
  public const string RIGHT_PARAMETER = 'rightVersion';

  /**
   * The cache contexts every diff resource object varies by.
   *
   * The version identifiers are read back in the document, so two spellings
   * of the same revision are two normalizations. Field access is the user's.
   * The compared values are in the content language.
   */
  private const array CACHE_CONTEXTS = [
    'url.query_args:leftVersion',
    'url.query_args:rightVersion',
    'user.permissions',
    'languages:language_content',
  ];

  /**
   * Constructs the resource object of one entity diff.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $diff_type
   *   The diff resource type.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $entity_type
   *   The resource type of the compared entity.
   * @param \Drupal\jsonapi_diff\Comparison\EntityDiff $diff
   *   The diff.
   * @param string|null $left_version
   *   The version identifier of the left side, or NULL when absent.
   * @param string|null $right_version
   *   The version identifier of the right side, or NULL when absent.
   * @param \Drupal\Core\Language\LanguageInterface|null $language
   *   The content language the values were compared in.
   */
  public function __construct(ResourceType $diff_type, ResourceType $entity_type, EntityDiff $diff, ?string $left_version, ?string $right_version, ?LanguageInterface $language = NULL) {
    assert($diff_type->getTypeName() === self::TYPE_NAME);
    assert(($left_version === NULL) === ($diff->leftRevisionId === NULL));
    assert(($right_version === NULL) === ($diff->rightRevisionId === NULL));

    // The relationships take this object as their context before the parent
    // constructor runs. Relationship::__construct() only stores the
    // reference, and nothing reads it before normalization.
    $fields = [
      'summary' => $diff->summary,
      'tree_summary' => $diff->treeSummary,
      'fields' => array_map(static fn (FieldDiff $field): array => [
        'label' => $field->label,
        'status' => $field->status,
        'left' => $field->left,
        'right' => $field->right,
        'ops' => $field->ops,
      ], $diff->fields),
      'left' => $this->side('left', $entity_type, $diff->uuid, $diff->leftRevisionId, $left_version),
      'right' => $this->side('right', $entity_type, $diff->uuid, $diff->rightRevisionId, $right_version),
      'children' => DiffRelationship::create($this, 'children', array_map(static fn (ChildDiff $child): ResourceIdentifier => new ResourceIdentifier($diff_type, self::idFor($child->diff), [
        'field' => $child->field,
        'left_delta' => $child->leftDelta,
        'right_delta' => $child->rightDelta,
        'status' => $child->status,
      ]), $diff->children), -1),
    ];

    $links = [];
    if ($left_version !== NULL && $right_version !== NULL) {
      $url = Url::fromRoute('jsonapi_diff.diff', [
        'entity_type' => $diff->entityTypeId,
        'bundle' => $diff->bundle,
        'uuid' => $diff->uuid,
      ], ['query' => [self::LEFT_PARAMETER => $left_version, self::RIGHT_PARAMETER => $right_version]]);
      $links['self'] = new Link(new CacheableMetadata(), $url, 'self');
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($diff->cacheability)
      ->addCacheContexts(self::CACHE_CONTEXTS);

    parent::__construct($cacheability, $diff_type, self::idFor($diff), NULL, $fields, new LinkCollection($links), $language);
  }

  /**
   * Builds the id of a diff resource.
   *
   * The id is the entity UUID and the two revision ids. It is the same for
   * every request that compares the same pair, whatever the versions were
   * called. A side the entity is absent from has an empty segment.
   */
  public static function idFor(EntityDiff $diff): string {
    return sprintf('%s:%s:%s', $diff->uuid, $diff->leftRevisionId ?? '', $diff->rightRevisionId ?? '');
  }

  /**
   * Builds the diff resource type.
   *
   * `left` and `right` can relate to any versionable resource type. Which
   * ones are passed in decides what a client is told. `children` relates to
   * the diff type itself.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType[] $versionable_types
   *   The resource types a diff can compare.
   */
  public static function resourceType(array $versionable_types): ResourceType {
    $fields = [
      'summary' => new ResourceTypeAttribute('summary'),
      'tree_summary' => new ResourceTypeAttribute('tree_summary'),
      'fields' => new ResourceTypeAttribute('fields'),
      'left' => new ResourceTypeRelationship('left'),
      'right' => new ResourceTypeRelationship('right'),
      'children' => new ResourceTypeRelationship('children', NULL, TRUE, FALSE),
    ];
    // Not internal, so it may be served. Not locatable, because no individual
    // route exists for the type name. Not mutable, so nothing is ever
    // deserialized into the target class. Not versionable.
    $resource_type = new ResourceType('jsonapi_diff', 'diff', \stdClass::class, FALSE, FALSE, FALSE, FALSE, $fields);
    $resource_type->setRelatableResourceTypes([
      'left' => $versionable_types,
      'right' => $versionable_types,
      'children' => [$resource_type],
    ]);
    return $resource_type;
  }

  /**
   * Builds the relationship to one side of the comparison.
   *
   * A side the entity is absent from has `data: null` and no links.
   */
  private function side(string $name, ResourceType $entity_type, string $uuid, ?int $revision_id, ?string $version): DiffRelationship {
    if ($revision_id === NULL || $version === NULL) {
      return DiffRelationship::create($this, $name, [], 1);
    }
    $identifier = new ResourceIdentifier($entity_type, $uuid, [
      'resourceVersion' => $version,
      'drupal_internal__revision_id' => $revision_id,
    ]);
    $links = [];
    if (!$entity_type->isInternal()) {
      $url = Url::fromRoute(sprintf('jsonapi.%s.individual', $entity_type->getTypeName()), ['entity' => $uuid], ['query' => ['resourceVersion' => $version]]);
      $links['related'] = new Link(new CacheableMetadata(), $url, 'related');
    }
    return DiffRelationship::create($this, $name, [$identifier], 1, new LinkCollection($links));
  }

}
