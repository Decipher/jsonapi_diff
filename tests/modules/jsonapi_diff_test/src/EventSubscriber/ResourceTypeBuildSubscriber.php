<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff_test\EventSubscriber;

use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvent;
use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Aliases one field, disables another, and disables one resource type.
 *
 * This is the core mechanism a site uses to rename or hide a field in
 * JSON:API, and to take a bundle off the API altogether. The diff has to
 * follow it.
 */
final class ResourceTypeBuildSubscriber implements EventSubscriberInterface {

  /**
   * The internal name of the aliased field.
   */
  public const string ALIASED_FIELD = 'field_alias';

  /**
   * The public name given to the aliased field.
   */
  public const string PUBLIC_NAME = 'public_alias';

  /**
   * The internal name of the disabled field.
   */
  public const string DISABLED_FIELD = 'field_hidden';

  /**
   * The resource type name the site has taken off JSON:API.
   */
  public const string DISABLED_TYPE = 'paragraph--internal';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ResourceTypeBuildEvents::BUILD => 'onResourceTypeBuild',
    ];
  }

  /**
   * Applies the alias and the two kinds of disabling.
   */
  public function onResourceTypeBuild(ResourceTypeBuildEvent $event): void {
    if ($event->getResourceTypeName() === self::DISABLED_TYPE) {
      $event->disableResourceType();
      return;
    }
    foreach ($event->getFields() as $field) {
      if ($field->getInternalName() === self::ALIASED_FIELD) {
        $event->setPublicFieldName($field, self::PUBLIC_NAME);
      }
      if ($field->getInternalName() === self::DISABLED_FIELD) {
        $event->disableField($field);
      }
    }
  }

}
