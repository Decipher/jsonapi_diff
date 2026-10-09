<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Access;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Access\EntityAccessChecker;
use Drupal\jsonapi\JsonApiResource\LabelOnlyResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObject;

/**
 * Asks core JSON:API whether an account may view an entity.
 *
 * The compared revisions and every entity the comparison recurses into are
 * judged here, so the two cannot drift apart. A label-only result counts as
 * a denial: a diff of a label-only view would expose the fields the label
 * hides. The result's cacheability is collected either way.
 *
 * Access is judged on the translation the comparison reads. Diff compares
 * the current content language's translation where one exists, so that is
 * the object judged, and not the default translation an entity loads as.
 */
final readonly class EntityViewCheck {

  public function __construct(
    private EntityAccessChecker $entityAccessChecker,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Returns the translation of an entity that the comparison reads.
   *
   * This is the rule Diff's entity parser applies: the current content
   * language's translation where the entity has one, else the entity as
   * loaded. Translations are published and moderated apart, so access must be
   * judged on this object, not on the default translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, at any revision.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The translation whose values a diff would show.
   */
  public function compared(ContentEntityInterface $entity): ContentEntityInterface {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    return $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
  }

  /**
   * Decides whether an account may view an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, at the revision that would be served.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the decision, allowed or denied.
   *
   * @return bool
   *   TRUE when the whole entity may be viewed.
   */
  public function isViewable(ContentEntityInterface $entity, ?AccountInterface $account, CacheableMetadata $cacheability): bool {
    $result = $this->entityAccessChecker->getAccessCheckedResourceObject($entity, $account);
    $cacheability->addCacheableDependency($result);
    return $result instanceof ResourceObject && !$result instanceof LabelOnlyResourceObject;
  }

}
