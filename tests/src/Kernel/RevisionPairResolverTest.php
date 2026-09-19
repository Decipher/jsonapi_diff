<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Kernel;

use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Drupal\jsonapi_diff\RevisionPairResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * Tests version resolution and access for a revision pair.
 *
 * @group jsonapi_diff
 */
class RevisionPairResolverTest extends KernelTestBase {

  use ContentModerationTestTrait;

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
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'diff',
    'jsonapi_diff',
  ];

  /**
   * The resolver under test.
   */
  protected RevisionPairResolver $resolver;

  /**
   * A node with a published default revision and a newer unpublished one.
   */
  protected NodeInterface $node;

  /**
   * The user who owns the node.
   */
  protected User $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    // The access checker's moderation branch reads the JSON:API routes.
    $this->container->get('router.builder')->rebuild();

    // Anonymous may read published content, like most sites.
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    assert($anonymous instanceof Role);
    $anonymous->grantPermission('access content')->save();

    $this->owner = $this->createUser([]);
    $this->node = $this->createDraftedNode($this->owner);

    $this->resolver = $this->container->get('jsonapi_diff.revision_pair_resolver');
  }

  /**
   * With no identifiers, left is the default and right the newest revision.
   */
  public function testDefaultsResolveToDefaultAndNewestRevision(): void {
    $pair = $this->resolver->resolve($this->node, NULL, NULL, $this->owner);

    $this->assertSame('rel:latest-version', $pair->leftVersion);
    $this->assertSame('rel:working-copy', $pair->rightVersion);
    $this->assertSame(1, (int) $pair->left->getRevisionId());
    $this->assertSame(2, (int) $pair->right->getRevisionId());
    $this->assertTrue($pair->left->isDefaultRevision());
    $this->assertFalse($pair->right->isDefaultRevision());
  }

  /**
   * The `id:` and `rel:` forms both name a revision.
   */
  public function testIdAndRelFormsResolve(): void {
    $pair = $this->resolver->resolve($this->node, 'id:2', 'rel:latest-version', $this->owner);
    $this->assertSame('id:2', $pair->leftVersion);
    $this->assertSame('rel:latest-version', $pair->rightVersion);
    $this->assertSame(2, (int) $pair->left->getRevisionId());
    $this->assertSame(1, (int) $pair->right->getRevisionId());

    $pair = $this->resolver->resolve($this->node, 'id:1', 'rel:working-copy', $this->owner);
    $this->assertSame(1, (int) $pair->left->getRevisionId());
    $this->assertSame(2, (int) $pair->right->getRevisionId());
  }

  /**
   * An identifier outside core's grammar is a 400.
   */
  public function testMalformedIdentifierIsBadRequest(): void {
    try {
      $this->resolver->resolve($this->node, '12', NULL, $this->owner);
      $this->fail('A bare revision id was accepted.');
    }
    catch (CacheableBadRequestHttpException $exception) {
      $this->assertSame(400, $exception->getStatusCode());
      $this->assertContains('url.query_args:left', $exception->getCacheContexts());
    }

    try {
      $this->resolver->resolve($this->node, NULL, 'rel:newest', $this->owner);
      $this->fail('An unknown rel was accepted.');
    }
    catch (CacheableBadRequestHttpException $exception) {
      $this->assertSame(400, $exception->getStatusCode());
      $this->assertContains('url.query_args:right', $exception->getCacheContexts());
    }
  }

  /**
   * A revision of another entity is a 404, as is one that does not exist.
   */
  public function testForeignRevisionIsNotFound(): void {
    $other = $this->createDraftedNode($this->owner);
    $foreign = 'id:' . $other->getRevisionId();

    try {
      $this->resolver->resolve($this->node, NULL, $foreign, $this->owner);
      $this->fail('A revision of another entity was accepted.');
    }
    catch (CacheableNotFoundHttpException $exception) {
      $this->assertSame(404, $exception->getStatusCode());
      $this->assertContains('url.query_args:right', $exception->getCacheContexts());
    }

    $this->expectException(CacheableNotFoundHttpException::class);
    $this->resolver->resolve($this->node, 'id:999', NULL, $this->owner);
  }

  /**
   * Both sides may name the same revision.
   */
  public function testSameRevisionOnBothSidesIsAllowed(): void {
    $pair = $this->resolver->resolve($this->node, 'id:1', 'id:1', new AnonymousUserSession());
    $this->assertSame(1, (int) $pair->left->getRevisionId());
    $this->assertSame(1, (int) $pair->right->getRevisionId());
  }

  /**
   * An entity type without revisions is a 404 before any negotiation.
   */
  public function testNonRevisionableEntityTypeIsNotFound(): void {
    $this->expectException(CacheableNotFoundHttpException::class);
    $this->resolver->resolve($this->owner, NULL, NULL, $this->owner);
  }

  /**
   * Anonymous cannot diff against an unpublished draft, and learns nothing.
   */
  public function testAnonymousIsDeniedAnUnpublishedWorkingCopy(): void {
    try {
      $this->resolver->resolve($this->node, NULL, NULL, new AnonymousUserSession());
      $this->fail('Anonymous read an unpublished revision.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      $this->assertSame(403, $exception->getStatusCode());
      // Core's exception carries the entity. Ours must not.
      $this->assertNotInstanceOf(EntityAccessDeniedHttpException::class, $exception);
      $this->assertStringNotContainsString('Draft title', $exception->getMessage());
      $this->assertStringContainsString('right', $exception->getMessage());
    }
  }

  /**
   * A user with revision access gets both sides.
   *
   * Node grants a non-owner no `view` on an unpublished revision, so the
   * owner is the user that exercises the `view all revisions` shim.
   */
  public function testViewAllRevisionsGetsBothSides(): void {
    $viewer = $this->createUser([
      'access content',
      'view own unpublished content',
      'view all revisions',
    ]);
    $node = $this->createDraftedNode($viewer);

    $pair = $this->resolver->resolve($node, NULL, NULL, $viewer);
    $this->assertSame('Published title', $pair->left->label());
    $this->assertSame('Draft title', $pair->right->label());
  }

  /**
   * Without content moderation's permission the working copy is denied.
   */
  public function testModerationDeniesWorkingCopyWithoutViewLatestVersion(): void {
    $this->installContentModeration();
    $viewer = $this->createUser([
      'access content',
      'view any unpublished content',
      'view all revisions',
    ]);
    $node = $this->createModeratedDraft($viewer);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->resolver->resolve($node, NULL, NULL, $viewer);
  }

  /**
   * With content moderation's permission the working copy is allowed.
   */
  public function testModerationAllowsWorkingCopyWithViewLatestVersion(): void {
    $this->installContentModeration();
    $viewer = $this->createUser([
      'access content',
      'view any unpublished content',
      'view all revisions',
      'view latest version',
    ]);
    $node = $this->createModeratedDraft($viewer);

    $pair = $this->resolver->resolve($node, NULL, NULL, $viewer);
    $this->assertSame('Published title', $pair->left->label());
    $this->assertSame('Draft title', $pair->right->label());
  }

  /**
   * A denial varies by permissions, so it is not served to an allowed user.
   */
  public function testDenialCacheabilityCarriesUserPermissions(): void {
    try {
      $this->resolver->resolve($this->node, NULL, NULL, new AnonymousUserSession());
      $this->fail('Anonymous read an unpublished revision.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      $this->assertContains('user.permissions', $exception->getCacheContexts());
      $this->assertContains('url.query_args:right', $exception->getCacheContexts());
      $this->assertContains('node:' . $this->node->id(), $exception->getCacheTags());
    }
  }

  /**
   * An allowed pair carries both query contexts and the entity's tag.
   */
  public function testAllowedPairCacheability(): void {
    $pair = $this->resolver->resolve($this->node, NULL, NULL, $this->owner);

    $contexts = $pair->cacheability->getCacheContexts();
    $this->assertContains('url.query_args:left', $contexts);
    $this->assertContains('url.query_args:right', $contexts);
    $this->assertContains('user.permissions', $contexts);
    $this->assertContains('node:' . $this->node->id(), $pair->cacheability->getCacheTags());
  }

  /**
   * Installs content moderation with the editorial workflow on articles.
   *
   * Installing modules rebuilds the container, so the resolver is fetched
   * again.
   */
  protected function installContentModeration(): void {
    $this->enableModules(['workflows', 'content_moderation']);
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $this->container->get('router.builder')->rebuild();
    $this->resolver = $this->container->get('jsonapi_diff.revision_pair_resolver');
  }

  /**
   * Creates a published moderated node with a pending draft.
   */
  protected function createModeratedDraft(User $owner): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Published title',
      'uid' => $owner->id(),
      'moderation_state' => 'published',
    ]);
    $node->save();

    $node->set('moderation_state', 'draft');
    $node->setTitle('Draft title');
    $node->save();

    $default = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    assert($default instanceof NodeInterface);
    return $default;
  }

  /**
   * Creates a published node with a newer unpublished revision.
   *
   * Revision 1 is the published default. Revision 2 is unpublished and not
   * the default, the shape of a draft. The node returned is the default
   * revision, as a route would load it.
   */
  protected function createDraftedNode(User $owner, string $type = 'article'): NodeInterface {
    $node = Node::create([
      'type' => $type,
      'title' => 'Published title',
      'uid' => $owner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();

    $node->setNewRevision();
    $node->isDefaultRevision(FALSE);
    $node->setUnpublished();
    $node->setTitle('Draft title');
    $node->save();

    $default = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    assert($default instanceof NodeInterface);
    return $default;
  }

  /**
   * Creates a user with the given permissions.
   *
   * @param string[] $permissions
   *   Permissions granted through a role of its own.
   */
  protected function createUser(array $permissions): User {
    $user = User::create(['name' => $this->randomMachineName()]);
    if ($permissions) {
      $role_id = $this->randomMachineName(8);
      $role = Role::create(['id' => $role_id, 'label' => 'Test']);
      foreach ($permissions as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $user->addRole($role_id);
    }
    $user->save();
    return $user;
  }

}
