<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Access\EntityAccessChecker;
use Drupal\jsonapi\JsonApiResource\LabelOnlyResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Revisions\ResourceVersionRouteEnhancer;
use Drupal\jsonapi\Revisions\VersionNegotiator;

/**
 * Resolves the two versions a diff compares.
 *
 * Version identifiers use core JSON:API's grammar and are resolved by its
 * negotiator. Access to each side is core JSON:API's decision too. This
 * service applies the defaults, composes the two, and maps the outcomes.
 */
final readonly class RevisionPairResolver {

  /**
   * The version compared when `left` is absent.
   */
  public const string DEFAULT_LEFT = 'rel:latest-version';

  /**
   * The version compared when `right` is absent.
   */
  public const string DEFAULT_RIGHT = 'rel:working-copy';

  public function __construct(
    private VersionNegotiator $versionNegotiator,
    private EntityAccessChecker $entityAccessChecker,
  ) {}

  /**
   * Resolves a pair of versions for one entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, as loaded for the route. Its default revision.
   * @param string|null $left
   *   The left version identifier, or NULL for the default.
   * @param string|null $right
   *   The right version identifier, or NULL for the default.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check access for. Defaults to the current user.
   *
   * @throws \Drupal\Core\Http\Exception\CacheableBadRequestHttpException
   *   When an identifier is not in core JSON:API's grammar.
   * @throws \Drupal\Core\Http\Exception\CacheableNotFoundHttpException
   *   When the entity type keeps no revisions, or a version does not exist.
   * @throws \Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException
   *   When the account may not view either version.
   */
  public function resolve(ContentEntityInterface $entity, ?string $left, ?string $right, ?AccountInterface $account = NULL): RevisionPair {
    $cacheability = (new CacheableMetadata())->addCacheContexts([
      'url.query_args:left',
      'url.query_args:right',
      'user.permissions',
    ]);

    if (!$entity->getEntityType()->isRevisionable()) {
      $message = sprintf('The `%s` entity type keeps no revisions, so it has nothing to compare.', $entity->getEntityTypeId());
      throw new CacheableNotFoundHttpException($cacheability, $message);
    }

    $left ??= self::DEFAULT_LEFT;
    $right ??= self::DEFAULT_RIGHT;

    $left_revision = $this->negotiate($entity, $left, $cacheability);
    $right_revision = $this->negotiate($entity, $right, $cacheability);
    $cacheability->addCacheableDependency($left_revision);
    $cacheability->addCacheableDependency($right_revision);

    // Both sides are checked before either is judged, so a denial carries
    // the cacheability of both decisions.
    $denied = array_filter([
      'left' => !$this->isViewable($left_revision, $account, $cacheability),
      'right' => !$this->isViewable($right_revision, $account, $cacheability),
    ]);
    if ($denied) {
      $message = sprintf('The current user is not allowed to view the %s version of the requested resource.', implode(' and ', array_keys($denied)));
      throw new CacheableAccessDeniedHttpException($cacheability, $message);
    }

    return new RevisionPair($left_revision, $right_revision, $left, $right, $cacheability);
  }

  /**
   * Resolves one identifier to a revision.
   *
   * Core's negotiator already throws cacheable 400 and 404 exceptions. They
   * are rethrown with this route's cacheability added, so a cached error
   * varies by the `left` and `right` query arguments.
   */
  private function negotiate(ContentEntityInterface $entity, string $identifier, CacheableMetadata $cacheability): ContentEntityInterface {
    // Core validates the shape before negotiating, so a bare `12` is a 400
    // and never reaches a negotiator.
    if (preg_match(ResourceVersionRouteEnhancer::VERSION_IDENTIFIER_VALIDATOR, $identifier) !== 1) {
      $message = sprintf('A resource version identifier was provided in an invalid format: `%s`', $identifier);
      throw new CacheableBadRequestHttpException($cacheability, $message);
    }

    try {
      $revision = $this->versionNegotiator->getRevision($entity, $identifier);
    }
    catch (CacheableBadRequestHttpException $exception) {
      throw new CacheableBadRequestHttpException($this->mergeCacheability($cacheability, $exception), $exception->getMessage(), $exception);
    }
    catch (CacheableNotFoundHttpException $exception) {
      throw new CacheableNotFoundHttpException($this->mergeCacheability($cacheability, $exception), $exception->getMessage(), $exception);
    }

    assert($revision instanceof ContentEntityInterface);
    return $revision;
  }

  /**
   * Asks core JSON:API whether the account may view a revision.
   *
   * A label-only result counts as a denial. A diff of a label-only view
   * would expose the fields the label hides. The result's cacheability is
   * collected either way.
   */
  private function isViewable(ContentEntityInterface $revision, ?AccountInterface $account, CacheableMetadata $cacheability): bool {
    $result = $this->entityAccessChecker->getAccessCheckedResourceObject($revision, $account);
    $cacheability->addCacheableDependency($result);
    return $result instanceof ResourceObject && !$result instanceof LabelOnlyResourceObject;
  }

  /**
   * Combines the route cacheability with what a core exception carries.
   */
  private function mergeCacheability(CacheableMetadata $cacheability, CacheableBadRequestHttpException|CacheableNotFoundHttpException $exception): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheableDependency($cacheability)
      ->addCacheableDependency($exception);
  }

}
