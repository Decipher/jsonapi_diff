<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Two revisions of one entity, resolved and access checked.
 *
 * The version identifiers are kept as the client gave them. A client that
 * asked for `rel:working-copy` reads it back unchanged. The revision ids are
 * on the entities.
 *
 * The account the pair was judged for travels with it, so the comparison
 * of the tree below these two revisions is judged for the same account.
 */
final readonly class RevisionPair {

  /**
   * Constructs a revision pair.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $left
   *   The left revision.
   * @param \Drupal\Core\Entity\ContentEntityInterface $right
   *   The right revision.
   * @param string $leftVersion
   *   The left version identifier, as the client spelled it.
   * @param string $rightVersion
   *   The right version identifier, as the client spelled it.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the resolution and both access decisions.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account both sides were checked for, or NULL for the current user.
   */
  public function __construct(
    public ContentEntityInterface $left,
    public ContentEntityInterface $right,
    public string $leftVersion,
    public string $rightVersion,
    public CacheableMetadata $cacheability,
    public ?AccountInterface $account = NULL,
  ) {}

}
