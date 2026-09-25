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
    // `self` is on every resource object in every supported core version, so
    // it proves the links were read without depending on a version link.
    $this->assertArrayHasKey('self', $links);
    $this->assertArrayHasKey('diff', $links);
    $this->assertSame($expected, $links['diff']['href']);

    $collection = $this->fetch('/jsonapi/node/article');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(2, $collection['data']);
    foreach ($collection['data'] as $object) {
      $this->assertArrayHasKey('diff', $object['links']);
    }
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
   * A user who cannot read the draft gets no link to it.
   *
   * Revision access alone does not open an unpublished revision, so the
   * diff route answers 403. The link has to agree with that.
   */
  public function testTheLinkIsAbsentWhenTheDraftCannotBeRead(): void {
    $this->drupalLogin($this->revisionReader);

    $document = $this->fetch($this->individualPath($this->drafted));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayNotHasKey('diff', $document['data']['links']);

    $this->fetch($this->diffPath($this->drafted));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A user without revision access gets no link to a node with a draft.
   */
  public function testTheLinkIsAbsentWithoutRevisionAccess(): void {
    $this->drupalLogin($this->viewer);

    $document = $this->fetch($this->individualPath($this->drafted));

    $this->assertSession()->statusCodeEquals(200);
    // The `self` link proves the links were read, so the absent `diff` below
    // is a real absence rather than an empty member.
    $this->assertArrayHasKey('self', $document['data']['links']);
    $this->assertArrayNotHasKey('diff', $document['data']['links']);

    $this->fetch($this->diffPath($this->drafted));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Without a newer revision the link needs no revision access at all.
   *
   * Both sides are then the published default revision, which anyone who
   * may read the node may read. The route says 200, so the link is offered.
   */
  public function testTheLinkIsPresentWhenNoRevisionAccessIsNeeded(): void {
    $this->drupalLogin($this->viewer);

    $document = $this->fetch($this->individualPath());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('diff', $document['data']['links']);

    $followed = $this->fetch($document['data']['links']['diff']['href']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('same', $followed['data']['attributes']['fields']['field_text']['status']);
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
    $path = $this->individualPath($this->drafted);

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
