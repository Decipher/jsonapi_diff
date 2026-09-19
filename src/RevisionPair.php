<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Two revisions of one entity, resolved and access checked.
 *
 * The version identifiers are kept as the client gave them. A client that
 * asked for `rel:working-copy` reads it back unchanged. The revision ids are
 * on the entities.
 */
final readonly class RevisionPair {

  public function __construct(
    public ContentEntityInterface $left,
    public ContentEntityInterface $right,
    public string $leftVersion,
    public string $rightVersion,
    public CacheableMetadata $cacheability,
  ) {}

}
