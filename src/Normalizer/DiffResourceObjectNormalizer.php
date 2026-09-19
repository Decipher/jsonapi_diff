<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff\Normalizer;

use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

/**
 * Encodes the map attributes of a diff resource object as JSON objects.
 *
 * `summary` and `fields` are maps. PHP has one array type, so an empty map
 * encodes as `[]` and a client that expects an object breaks. A real case is
 * a paragraph whose only field is a reference to other paragraphs: every
 * field recurses into `children` and nothing is left to report.
 *
 * The cast cannot be done where the resource object is built. Core hands any
 * attribute that is not a field item list straight to
 * CacheableNormalization::permanent(), which asserts the value is an array or
 * a scalar. An object only survives nested inside the normalization, so it is
 * put there after the resource object has been normalized.
 *
 * The normalizer decorates core's and delegates every object to it, so other
 * decorators of the same service keep running.
 *
 * @see \Drupal\jsonapi\Normalizer\ResourceObjectNormalizer::serializeField()
 * @see \Drupal\jsonapi\Normalizer\Value\CacheableNormalization::__construct()
 */
class DiffResourceObjectNormalizer implements NormalizerInterface, SerializerAwareInterface {

  use SerializerAwareTrait;

  /**
   * The attributes of a diff resource object that are maps.
   */
  private const array MAP_ATTRIBUTES = ['summary', 'fields'];

  /**
   * Constructs the normalizer.
   *
   * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $inner
   *   The decorated resource object normalizer.
   */
  public function __construct(protected readonly NormalizerInterface $inner) {}

  /**
   * {@inheritdoc}
   *
   * @param mixed $object
   *   The object to normalize.
   * @param string|null $format
   *   The format the normalization is encoded as.
   * @param array<string, mixed> $context
   *   The normalization context.
   *
   * @return array<mixed>|string|int|float|bool|\ArrayObject<int|string, mixed>|null
   *   The normalization.
   */
  public function normalize($object, $format = NULL, array $context = []): array|string|int|float|bool|\ArrayObject|NULL {
    // The serializer only injects itself into the outermost decorator, so it
    // is passed on before the inner normalizer runs.
    if ($this->inner instanceof SerializerAwareInterface) {
      $this->inner->setSerializer($this->serializer);
    }
    $normalized = $this->inner->normalize($object, $format, $context);
    if (!$object instanceof DiffResourceObject || !$normalized instanceof CacheableNormalization) {
      return $normalized;
    }

    $value = $normalized->getNormalization();
    if (!is_array($value) || !isset($value['attributes']) || !is_array($value['attributes'])) {
      return $normalized;
    }
    $cast = FALSE;
    foreach (self::MAP_ATTRIBUTES as $name) {
      // A sparse fieldset leaves out the attributes it was not asked for.
      if (isset($value['attributes'][$name]) && is_array($value['attributes'][$name])) {
        $value['attributes'][$name] = (object) $value['attributes'][$name];
        $cast = TRUE;
      }
    }
    return $cast ? new CacheableNormalization($normalized, $value) : $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, $format = NULL, array $context = []): bool {
    // The context is not passed on. Symfony's interface only took two
    // arguments until 7.0, and no normalizer in this chain reads it.
    return $this->inner->supportsNormalization($data, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return $this->inner->getSupportedTypes($format);
  }

}
