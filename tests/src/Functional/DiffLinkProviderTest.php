<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the diff link JSON:API Hypermedia adds to a resource object.
 *
 * The link provider is only discovered when that module is installed, so
 * every assertion here is about the site that has it.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffLinkProviderTest extends DiffLinkProviderTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jsonapi_hypermedia'];

  /**
   * A reader of revisions gets the link individually and in a collection.
   */
  public function testTheLinkIsPresentWithRevisionAccess(): void {
    $this->drupalLogin($this->reviewer);
    $expected = $this->getAbsoluteUrl($this->diffPath());

    $individual = $this->fetch($this->individualPath());
    $this->assertSession()->statusCodeEquals(200);
    $links = $individual['data']['links'];
    $this->assertArrayHasKey('working-copy', $links);
    $this->assertArrayHasKey('diff', $links);
    $this->assertSame($expected, $links['diff']['href']);

    $collection = $this->fetch('/jsonapi/node/article');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $collection['data']);
    $this->assertSame($expected, $collection['data'][0]['links']['diff']['href']);
  }

  /**
   * The href is the diff route itself, and it carries no query parameters.
   */
  public function testTheHrefIsTheBareDiffRoute(): void {
    $this->drupalLogin($this->reviewer);

    $document = $this->fetch($this->individualPath());
    $href = $document['data']['links']['diff']['href'];

    $this->assertSame($this->getAbsoluteUrl($this->diffPath()), $href);
    $this->assertStringNotContainsString('?', $href);

    // A client that follows the link reaches the diff of the two defaults.
    $followed = $this->fetch($href);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('jsonapi_diff--diff', $followed['data']['type']);
  }

  /**
   * A user without revision access gets no link.
   */
  public function testTheLinkIsAbsentWithoutRevisionAccess(): void {
    $this->drupalLogin($this->viewer);

    $document = $this->fetch($this->individualPath());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('working-copy', $document['data']['links']);
    $this->assertArrayNotHasKey('diff', $document['data']['links']);
  }

  /**
   * A resource type whose entity type keeps no revisions gets no link.
   */
  public function testTheLinkIsAbsentWhenRevisionsAreNotKept(): void {
    $this->drupalLogin($this->reviewer);

    $document = $this->fetch('/jsonapi/user/user/' . $this->reviewer->uuid());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayNotHasKey('working-copy', $document['data']['links']);
    $this->assertArrayNotHasKey('diff', $document['data']['links']);
  }

  /**
   * A response cached without the link is not served to a user who gets it.
   */
  public function testTheLinkIsNotCachedAcrossUsers(): void {
    $path = $this->individualPath();

    $this->drupalLogin($this->viewer);
    $without = $this->fetch($path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertArrayNotHasKey('diff', $without['data']['links']);
    // The access decision is keyed by the permissions it was made for.
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Contexts', 'user.permissions');

    // The same user reads the cached response back.
    $this->fetch($path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');

    $this->drupalLogin($this->reviewer);
    $with = $this->fetch($path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertArrayHasKey('diff', $with['data']['links']);
  }

}
