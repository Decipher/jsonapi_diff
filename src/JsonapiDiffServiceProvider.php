<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Registers the diff normalizer under JSON:API's own namespace.
 *
 * The serializer rejects a normalizer whose class is outside
 * `Drupal\jsonapi\Normalizer`, so the service is built from a subclass that
 * lives there. That class sits outside the module's PSR-4 root, so the
 * namespace is added to the container and the definition is given the file
 * to include.
 *
 * @see \Drupal\jsonapi\Normalizer\ImpostorFrom\jsonapi_diff\DiffResourceObjectNormalizerImpostor
 */
class JsonapiDiffServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $namespaces = $container->getParameter('container.namespaces');
    $modules = $container->getParameter('container.modules');
    $app_root = $container->getParameter('app.root');
    if (!is_array($namespaces) || !is_array($modules) || !is_string($app_root) || !isset($modules['jsonapi_diff']['pathname'])) {
      return;
    }

    // The module pathname is relative to the app root, and the working
    // directory is not the app root in every process that builds a container.
    $path = $app_root . '/' . dirname((string) $modules['jsonapi_diff']['pathname']) . '/src-impostor-normalizers';
    $namespaces['Drupal\jsonapi\Normalizer\ImpostorFrom\jsonapi_diff'][] = $path;
    $container->setParameter('container.namespaces', $namespaces);
    $container->getDefinition('jsonapi_diff.normalizer.resource_object')
      ->setFile($path . '/DiffResourceObjectNormalizerImpostor.php');
  }

}
