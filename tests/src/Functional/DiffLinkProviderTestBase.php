<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi_diff\Traits\DiffContentTrait;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * The site the discovery link tests share.
 *
 * Two articles: one published with a single revision, one published with an
 * unpublished draft on top. Three users, so the link can be measured against
 * every answer the diff route gives. The subclass decides whether JSON:API
 * Hypermedia is installed, because that is the only difference the tests
 * measure.
 */
abstract class DiffLinkProviderTestBase extends BrowserTestBase {

  use DiffContentTrait;

  /**
   * The JSON:API media type every request asks for.
   */
  protected const array HEADERS = ['Accept' => 'application/vnd.api+json'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'text',
    'filter',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'diff',
    'jsonapi_diff',
    'page_cache',
    'dynamic_page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user who may read every revision, published or not.
   */
  protected UserInterface $reviewer;

  /**
   * A user who may read revisions but no unpublished content.
   *
   * Revision access alone is not enough to read a draft, so this user can
   * diff a node that has no draft and cannot diff one that has.
   */
  protected UserInterface $revisionReader;

  /**
   * A user who may read content but no revision of it.
   */
  protected UserInterface $viewer;

  /**
   * The published article every test reads. It has one revision.
   */
  protected NodeInterface $article;

  /**
   * A published article with an unpublished draft on top.
   */
  protected NodeInterface $drafted;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createDiffContentTypes();
    // The web process reads the routes from the database. Rebuild them here,
    // so the new bundle's JSON:API routes exist.
    $this->container->get('router.builder')->rebuild();

    // Node grants decide an unpublished revision per user unless the user
    // bypasses them, and Dynamic Page Cache will not cache a per user
    // decision. The reviewer bypasses, so the cache scenarios measure the
    // link's own cacheability.
    $this->reviewer = $this->asAccount($this->drupalCreateUser([
      'access content',
      'bypass node access',
      'view all revisions',
    ]));
    $this->revisionReader = $this->asAccount($this->drupalCreateUser([
      'access content',
      'view all revisions',
    ]));
    $this->viewer = $this->asAccount($this->drupalCreateUser(['access content']));
    $this->article = $this->createArticle(['field_text' => 'published text']);
    $this->drafted = $this->draft($this->createArticle(['field_text' => 'published text']), ['field_text' => 'draft text']);
  }

  /**
   * The individual route of a node, or of the shared article.
   */
  protected function individualPath(?NodeInterface $node = NULL): string {
    return '/jsonapi/node/article/' . ($node ?? $this->article)->uuid();
  }

  /**
   * The diff route of a node, or of the shared article, with no parameters.
   */
  protected function diffPath(?NodeInterface $node = NULL): string {
    return '/jsonapi/diff/node/article/' . ($node ?? $this->article)->uuid();
  }

  /**
   * Fetches a JSON:API document as the current session's user.
   *
   * @param string $path
   *   The path, or an absolute URL from a link.
   *
   * @return array<string, mixed>
   *   The decoded document, or an empty array when the body is not JSON.
   */
  protected function fetch(string $path): array {
    return json_decode($this->drupalGet($path, [], self::HEADERS), TRUE) ?? [];
  }

}
