<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the diff link against content moderation's latest version rule.
 *
 * Core's entity access checker applies that rule to a non-default revision,
 * so the diff route does too. Revision access alone is not the whole answer
 * on a moderated site, and the link has to say what the route will.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffLinkProviderModerationTest extends DiffLinkProviderTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jsonapi_hypermedia', 'content_moderation'];

  /**
   * A moderated article with a published default revision and a draft.
   */
  protected NodeInterface $moderated;

  /**
   * A user with every revision permission but not `view latest version`.
   */
  protected UserInterface $blindToDrafts;

  /**
   * The same user with `view latest version` added.
   */
  protected UserInterface $moderator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $this->container->get('router.builder')->rebuild();

    $permissions = [
      'access content',
      'view all revisions',
      'view article revisions',
      'view any unpublished content',
    ];
    $this->blindToDrafts = $this->asAccount($this->drupalCreateUser($permissions));
    $this->moderator = $this->asAccount($this->drupalCreateUser([...$permissions, 'view latest version']));

    $node = Node::create([
      'type' => 'article',
      'title' => 'Article',
      'field_text' => 'published text',
      'moderation_state' => 'published',
    ]);
    $node->save();
    $node->set('field_text', 'draft text');
    $node->set('moderation_state', 'draft');
    $node->save();
    $this->moderated = $this->reloadDefaultRevision($node);
  }

  /**
   * Without `view latest version` the diff is refused, so no link is given.
   */
  public function testTheLinkIsAbsentWithoutTheModerationPermission(): void {
    $this->drupalLogin($this->blindToDrafts);

    $document = $this->fetch($this->individualPath($this->moderated));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayNotHasKey('diff', $document['data']['links']);

    $this->fetch($this->diffPath($this->moderated));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * With `view latest version` the diff is served, so the link is given.
   */
  public function testTheLinkIsPresentWithTheModerationPermission(): void {
    $this->drupalLogin($this->moderator);

    $document = $this->fetch($this->individualPath($this->moderated));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('diff', $document['data']['links']);

    $followed = $this->fetch($document['data']['links']['diff']['href']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('changed', $followed['data']['attributes']['fields']['field_text']['status']);
  }

  /**
   * Reloads the default revision of a node, as the route loads it.
   */
  protected function reloadDefaultRevision(NodeInterface $node): NodeInterface {
    $reloaded = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    return $reloaded;
  }

}
