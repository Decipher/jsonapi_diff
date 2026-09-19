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
 * One published article and two users. The reviewer may read revisions, the
 * viewer may not. The subclass decides whether JSON:API Hypermedia is
 * installed, because that is the only difference the tests measure.
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
   * A user who may read every revision.
   */
  protected UserInterface $reviewer;

  /**
   * A user who may read content but no revision of it.
   */
  protected UserInterface $viewer;

  /**
   * The published article every test reads.
   */
  protected NodeInterface $article;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createDiffContentTypes();
    // The web process reads the routes from the database. Rebuild them here,
    // so the new bundle's JSON:API routes exist.
    $this->container->get('router.builder')->rebuild();

    $this->reviewer = $this->asAccount($this->drupalCreateUser([
      'access content',
      'view all revisions',
    ]));
    $this->viewer = $this->asAccount($this->drupalCreateUser(['access content']));
    $this->article = $this->createArticle(['field_text' => 'published text']);
  }

  /**
   * The individual route of the shared article.
   */
  protected function individualPath(): string {
    return '/jsonapi/node/article/' . $this->article->uuid();
  }

  /**
   * The diff route of the shared article, with no query parameters.
   */
  protected function diffPath(): string {
    return '/jsonapi/diff/node/article/' . $this->article->uuid();
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
