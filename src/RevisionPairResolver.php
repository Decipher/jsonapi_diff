<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\jsonapi\Revisions\ResourceVersionRouteEnhancer;
use Drupal\jsonapi\Revisions\VersionNegotiator;
use Drupal\jsonapi_diff\Access\EntityViewCheck;

/**
 * Resolves the two versions a diff compares.
 *
 * Version identifiers use core JSON:API's grammar and are resolved by its
 * negotiator. Access to each side is core JSON:API's decision too. This
 * service applies the defaults, composes the two, and maps the outcomes.
 */
final readonly class RevisionPairResolver {

  /**
   * The version compared when `leftVersion` is absent.
   */
  public const string DEFAULT_LEFT = 'rel:latest-version';

  /**
   * The version compared when `rightVersion` is absent.
   */
  public const string DEFAULT_RIGHT = 'rel:working-copy';

  /**
   * The version of the current user's unsaved preview of a node.
   *
   * It follows core's grammar but is resolved here only. Core JSON:API keeps
   * no such version, so its own routes never learn the identifier.
   */
  public const string PREVIEW = 'rel:preview';

  public function __construct(
    private VersionNegotiator $versionNegotiator,
    private EntityViewCheck $entityViewCheck,
    private PrivateTempStoreFactory $tempStoreFactory,
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
      'url.query_args:leftVersion',
      'url.query_args:rightVersion',
      'user.permissions',
    ]);

    if (!$entity->getEntityType()->isRevisionable()) {
      $message = sprintf('The `%s` entity type keeps no revisions, so it has nothing to compare.', $entity->getEntityTypeId());
      throw new CacheableNotFoundHttpException($cacheability, $message);
    }

    $left ??= self::DEFAULT_LEFT;
    $right ??= self::DEFAULT_RIGHT;

    [$left_revision, $left_token] = $this->negotiate($entity, $left, $cacheability);
    [$right_revision, $right_token] = $this->negotiate($entity, $right, $cacheability);
    $cacheability->addCacheableDependency($left_revision);
    $cacheability->addCacheableDependency($right_revision);

    // Both sides are checked before either is judged, so a denial carries
    // the cacheability of both decisions.
    $denied = array_filter([
      'left' => !$this->entityViewCheck->isViewable($left_revision, $account, $cacheability),
      'right' => !$this->entityViewCheck->isViewable($right_revision, $account, $cacheability),
    ]);
    if ($denied) {
      $message = sprintf('The current user is not allowed to view the %s version of the requested resource.', implode(' and ', array_keys($denied)));
      throw new CacheableAccessDeniedHttpException($cacheability, $message);
    }

    return new RevisionPair($left_revision, $right_revision, $left, $right, $cacheability, $account, $left_token, $right_token);
  }

  /**
   * Resolves one identifier to a revision.
   *
   * Core's negotiator already throws cacheable 400 and 404 exceptions. They
   * are rethrown with this route's cacheability added, so a cached error
   * varies by the `leftVersion` and `rightVersion` query arguments.
   *
   * @return array{\Drupal\Core\Entity\ContentEntityInterface, string|null}
   *   The revision, and for an unsaved preview the token that names it.
   */
  private function negotiate(ContentEntityInterface $entity, string $identifier, CacheableMetadata $cacheability): array {
    if ($identifier === self::PREVIEW) {
      return $this->preview($entity, $cacheability);
    }

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
    // The translation the comparison reads is the one judged and served.
    return [$this->entityViewCheck->compared($revision), NULL];
  }

  /**
   * Resolves the current user's unsaved preview of a node.
   *
   * Core's node form keeps the preview in the private `node_preview` temp
   * store, keyed by the node's UUID, so only the user who pressed Preview
   * can read it. The response is never cached: the preview changes every
   * time the form is previewed again.
   *
   * @return array{\Drupal\Core\Entity\ContentEntityInterface, string}
   *   The preview entity, and a token naming this preview. The token is a
   *   digest of the temp store entry's owner and stored form state, so diff
   *   ids differ from every stored revision's, from any other editor's
   *   preview, and from this editor's earlier previews.
   *
   * @throws \Drupal\Core\Http\Exception\CacheableBadRequestHttpException
   *   When the entity is not a node. Core keeps previews only for nodes.
   * @throws \Drupal\Core\Http\Exception\CacheableNotFoundHttpException
   *   When the current user has no preview of this node in progress.
   */
  private function preview(ContentEntityInterface $entity, CacheableMetadata $cacheability): array {
    $cacheability->mergeCacheMaxAge(0);
    if ($entity->getEntityTypeId() !== 'node') {
      $message = sprintf('`%s` names an unsaved node preview, and the `%s` entity type has none.', self::PREVIEW, $entity->getEntityTypeId());
      throw new CacheableBadRequestHttpException($cacheability, $message);
    }

    $store = $this->tempStoreFactory->get('node_preview');
    $uuid = (string) $entity->uuid();
    $form_state = $store->get($uuid);
    $metadata = $store->getMetadata($uuid);
    $form_object = $form_state instanceof FormStateInterface ? $form_state->getFormObject() : NULL;
    $preview = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$preview instanceof ContentEntityInterface || $preview->uuid() !== $uuid || $metadata === NULL) {
      throw new CacheableNotFoundHttpException($cacheability, 'No preview of this node is in progress for the current user.');
    }

    // The token digests the stored form state itself, before any temporary
    // id is given, so every write of the preview gets its own. The store's
    // timestamp alone has whole seconds, and two previews saved in the same
    // second would share it.
    $token = 'preview-' . substr(hash('sha256', $metadata->getOwnerId() . ':' . $uuid . ':' . serialize($form_state)), 0, 12);
    $this->nameUnsavedEntities($preview);
    return [$this->entityViewCheck->compared($preview), $token];
  }

  /**
   * Gives every unsaved entity in a preview's reference tree a temporary id.
   *
   * Diff keys what it compares by entity id, so two unsaved paragraphs, both
   * without one, would be read as the same entity. Each gets a negative id,
   * unique in this request and never saved: the preview came from the temp
   * store and is discarded after the response.
   */
  private function nameUnsavedEntities(FieldableEntityInterface $entity, int &$next = -1): void {
    foreach ($entity->getFields(FALSE) as $items) {
      if (!$items instanceof EntityReferenceFieldItemListInterface || $items->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
        continue;
      }
      foreach ($items as $item) {
        $child = $item->entity ?? NULL;
        if (!$child instanceof FieldableEntityInterface) {
          continue;
        }
        $id_key = $child->getEntityType()->getKey('id');
        if ($child->id() === NULL && is_string($id_key)) {
          $child->set($id_key, $next--);
          $child->enforceIsNew();
        }
        $this->nameUnsavedEntities($child, $next);
      }
    }
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
