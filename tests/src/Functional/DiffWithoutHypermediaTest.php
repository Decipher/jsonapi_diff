<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the module on a site without JSON:API Hypermedia.
 *
 * JSON:API Hypermedia is a suggestion, not a dependency. The route has to
 * work without it, and nothing may add a diff link to a resource object.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffWithoutHypermediaTest extends DiffLinkProviderTestBase {

  /**
   * The route serves a diff and resource objects carry no diff link.
   */
  public function testTheRouteWorksWithNoLinkProvider(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('jsonapi_hypermedia'));
    $this->drupalLogin($this->reviewer);

    $individual = $this->fetch($this->individualPath());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('working-copy', $individual['data']['links']);
    $this->assertArrayNotHasKey('diff', $individual['data']['links']);

    $diff = $this->fetch($this->diffPath());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('jsonapi_diff--diff', $diff['data']['type']);
    $this->assertSame('same', $diff['data']['attributes']['fields']['field_text']['status']);
  }

}
