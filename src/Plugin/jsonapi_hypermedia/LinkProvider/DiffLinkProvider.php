<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
use Drupal\jsonapi_hypermedia\Plugin\LinkProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells a client that a resource object can be diffed, and where.
 *
 * The link is the diff route with no query parameters, which compares the
 * latest version with the working copy. A client that wants another pair
 * adds `leftVersion` and `rightVersion` itself.
 *
 * The plugin is only discovered when JSON:API Hypermedia is installed, so
 * the module suggests that module rather than depending on it.
 *
 * @JsonapiHypermediaLinkProvider(
 *   id = "jsonapi_diff.diff",
 *   link_key = "diff",
 *   link_relation_type = "diff",
 *   link_context = {
 *     "resource_object" = TRUE,
 *   }
 * )
 */
final class DiffLinkProvider extends LinkProviderBase implements ContainerFactoryPluginInterface {

  /**
   * The entity repository.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $provider = new self($configuration, $plugin_id, $plugin_definition);
    $provider->entityRepository = $container->get('entity.repository');
    return $provider;
  }

  /**
   * {@inheritdoc}
   *
   * The link is inaccessible when there is nothing to point at, and when the
   * user could not follow it.
   */
  public function getLink($context): AccessRestrictedLink {
    assert($context instanceof ResourceObject);
    $cacheability = CacheableMetadata::createFromObject($context);
    $resource_type = $context->getResourceType();

    // The diff route answers 404 for an entity type that keeps no revisions,
    // and for a resource type JSON:API does not serve. A link to either is a
    // link a client cannot follow. This also covers the diff resource type
    // itself, which is not backed by an entity type at all.
    if (!$resource_type->isVersionable() || $resource_type->isInternal()) {
      return AccessRestrictedLink::createInaccessibleLink($cacheability);
    }

    // A resource object carries the resource type and the UUID. Only the
    // label-only subclass also holds the entity, and that one is a view
    // denial anyway. So the entity is loaded by its UUID.
    $entity = $this->entityRepository->loadEntityByUuid($resource_type->getEntityTypeId(), $context->getId());
    if (!$entity instanceof ContentEntityInterface) {
      return AccessRestrictedLink::createInaccessibleLink($cacheability);
    }

    // The diff compares two revisions, so reading one is not enough. These
    // are the two operations core JSON:API checks for a non-default version.
    // Access result objects, so the decision's cacheability travels with it.
    // @see \Drupal\jsonapi\Access\EntityAccessChecker::checkEntityAccess()
    $access = $entity->access('view', NULL, TRUE)
      ->andIf($entity->access('view all revisions', NULL, TRUE));

    $url = Url::fromRoute('jsonapi_diff.diff', [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'uuid' => $entity->uuid(),
    ]);

    return AccessRestrictedLink::createLink($access, $cacheability, $url, $this->getLinkRelationType());
  }

}
