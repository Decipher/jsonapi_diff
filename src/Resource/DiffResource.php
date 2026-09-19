<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\CacheableResourceResponse;
use Drupal\jsonapi\JsonApiResource\IncludedData;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi_diff\Comparison\EntityDiff;
use Drupal\jsonapi_diff\Comparison\TreeBuilder;
use Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject;
use Drupal\jsonapi_diff\RevisionPairResolver;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Serves the difference between two revisions as a JSON:API document.
 *
 * The resource finds the entity, hands the version identifiers to the
 * resolver and the pair to the tree builder, and turns the tree into a
 * document. Access is the resolver's decision. The document shape is the
 * resource object's.
 */
final class DiffResource extends ResourceBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly RevisionPairResolver $revisionPairResolver,
    private readonly TreeBuilder $treeBuilder,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jsonapi_diff.revision_pair_resolver'),
      $container->get('jsonapi_diff.tree_builder'),
      $container->get('entity.repository'),
      $container->get('language_manager'),
    );
  }

  /**
   * Processes the diff request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. `leftVersion` and `rightVersion` are read from its query.
   * @param \Drupal\jsonapi\ResourceType\ResourceType[] $resource_types
   *   The route resource types. Only the diff type.
   * @param string $entity_type
   *   The entity type id from the path.
   * @param string $bundle
   *   The bundle from the path.
   * @param string $uuid
   *   The entity UUID from the path.
   *
   * @return \Drupal\jsonapi\CacheableResourceResponse
   *   The response.
   *
   * @throws \Drupal\Core\Http\Exception\CacheableNotFoundHttpException
   *   When the resource type is unknown, not versionable, or the entity is
   *   not found. The message is the same whoever asks.
   */
  public function process(Request $request, array $resource_types, string $entity_type, string $bundle, string $uuid): CacheableResourceResponse {
    $diff_type = reset($resource_types);
    if (!$diff_type instanceof ResourceType) {
      throw new \LogicException('The diff route defines exactly one resource type.');
    }

    $cacheability = (new CacheableMetadata())->addCacheContexts([
      'url.query_args:leftVersion',
      'url.query_args:rightVersion',
      'user.permissions',
      'languages:language_content',
    ]);

    $resource_type = $this->resourceTypeRepository->get($entity_type, $bundle);
    if (!$resource_type instanceof ResourceType || $resource_type->isInternal() || !$resource_type->isVersionable()) {
      throw new CacheableNotFoundHttpException($cacheability, $this->notFoundMessage($entity_type, $bundle, $uuid));
    }
    $entity = $this->entityRepository->loadEntityByUuid($entity_type, $uuid);
    if (!$entity instanceof ContentEntityInterface || $entity->bundle() !== $bundle) {
      throw new CacheableNotFoundHttpException($cacheability, $this->notFoundMessage($entity_type, $bundle, $uuid));
    }

    $pair = $this->revisionPairResolver->resolve($entity, $this->version($request, DiffResourceObject::LEFT_PARAMETER), $this->version($request, DiffResourceObject::RIGHT_PARAMETER));
    $tree = $this->treeBuilder->build($pair->left, $pair->right);

    $language = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);
    $root = new DiffResourceObject($diff_type, $resource_type, $tree, $pair->leftVersion, $pair->rightVersion, $language);
    $included = [];
    $this->collectIncluded($diff_type, $tree, $language, $included);

    $links = new LinkCollection([
      'self' => new Link(new CacheableMetadata(), Url::fromUri($request->getUri()), 'self'),
    ]);
    $document = new JsonApiDocumentTopLevel(new ResourceObjectData([$root], 1), new IncludedData($included), $links);

    $response = new CacheableResourceResponse($document);
    $response->addCacheableDependency($cacheability)
      ->addCacheableDependency($pair->cacheability)
      ->addCacheableDependency($tree->cacheability);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    $versionable = array_values(array_filter(
      $this->resourceTypeRepository->all(),
      static fn (ResourceType $resource_type): bool => $resource_type->isVersionable() && !$resource_type->isInternal(),
    ));
    return [DiffResourceObject::resourceType($versionable)];
  }

  /**
   * Reads one version identifier from the query. NULL when absent.
   */
  private function version(Request $request, string $name): ?string {
    $value = $request->query->get($name);
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * Adds the diff resource objects of a tree's children to `included`.
   *
   * Children come in the order the tree holds them, each followed by its own
   * children. A child's versions are its revision ids, since the client did
   * not name them.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $diff_type
   *   The diff resource type.
   * @param \Drupal\jsonapi_diff\Comparison\EntityDiff $diff
   *   The diff whose children are collected.
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The content language the values were compared in.
   * @param \Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject[] $included
   *   The collected resource objects.
   */
  private function collectIncluded(ResourceType $diff_type, EntityDiff $diff, LanguageInterface $language, array &$included): void {
    foreach ($diff->children as $child) {
      $entity_type = $this->resourceTypeRepository->get($child->diff->entityTypeId, $child->diff->bundle);
      assert($entity_type instanceof ResourceType, 'The tree only holds entities JSON:API exposes.');
      $included[] = new DiffResourceObject(
        $diff_type,
        $entity_type,
        $child->diff,
        $child->diff->leftRevisionId === NULL ? NULL : 'id:' . $child->diff->leftRevisionId,
        $child->diff->rightRevisionId === NULL ? NULL : 'id:' . $child->diff->rightRevisionId,
        $language,
      );
      $this->collectIncluded($diff_type, $child->diff, $language, $included);
    }
  }

  /**
   * The message for every not found case.
   *
   * One wording for an unknown type, a wrong bundle and a missing entity, so
   * a client cannot tell from the answer which one it hit.
   */
  private function notFoundMessage(string $entity_type, string $bundle, string $uuid): string {
    return sprintf('No revisionable `%s--%s` resource with the UUID `%s` was found.', $entity_type, $bundle, $uuid);
  }

}
