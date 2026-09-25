<?php

declare(strict_types=1);

namespace Drupal\jsonapi\Normalizer\ImpostorFrom\jsonapi_diff;

use Drupal\jsonapi_diff\Normalizer\DiffResourceObjectNormalizer;

/**
 * Lends the diff normalizer a class name JSON:API's serializer accepts.
 *
 * Core's JSON:API serializer refuses any normalizer whose class is outside
 * the `Drupal\jsonapi\Normalizer` namespace. This subclass carries the name,
 * and the service provider tells the container where to find the file. It is
 * the pattern JSON:API Resources ships for the same reason.
 *
 * @see \Drupal\jsonapi\Serializer\Serializer::__construct()
 * @see \Drupal\jsonapi_diff\JsonapiDiffServiceProvider
 */
class DiffResourceObjectNormalizerImpostor extends DiffResourceObjectNormalizer {}
