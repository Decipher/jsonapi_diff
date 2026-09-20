<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Functional;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi_diff\Traits\DiffContentTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the diff route over real HTTP.
 *
 * Statuses, error documents, the related links and both page caches only
 * exist in the full stack, so those are asserted here. The document shape
 * is pinned by the kernel tests.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffResourceTest extends BrowserTestBase {

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
    'language',
    'content_translation',
    'page_cache',
    'dynamic_page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user who may read every revision of the nodes they own.
   */
  protected UserInterface $editor;

  /**
   * A user whose access to every revision rests on permissions alone.
   *
   * An owner-only draft is decided per user by core, which Dynamic Page
   * Cache refuses to cache. The cache scenarios use this user, so what they
   * measure is the diff's own cacheability.
   */
  protected UserInterface $publisher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createDiffContentTypes();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    // The web process reads the routes from the database. Rebuild them here,
    // so the new bundles' JSON:API routes exist for the related links.
    $this->container->get('router.builder')->rebuild();

    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    assert($anonymous instanceof Role);
    $this->grantPermissions($anonymous, ['access content']);

    $this->editor = $this->asAccount($this->drupalCreateUser([
      'access content',
      'view own unpublished content',
      'view all revisions',
    ]));
    $this->publisher = $this->asAccount($this->drupalCreateUser([
      'access content',
      'bypass node access',
      'view all revisions',
    ]));
  }

  /**
   * An editor reads the whole tree, with the children in included.
   */
  public function testDiffWithParagraphs(): void {
    [$node, $blocks] = $this->createReshapedArticle(['uid' => $this->editor->id()]);
    [$first, $third, $second, $fourth] = $blocks;
    $this->drupalLogin($this->editor);

    $document = $this->fetch($this->path($node));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/vnd.api+json');
    $this->assertSame('jsonapi_diff--diff', $document['data']['type']);
    $this->assertSame($node->uuid() . ':1:2', $document['data']['id']);
    $this->assertSame('changed', $document['data']['attributes']['fields']['field_text']['status']);
    $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 1, 'same' => 3], $document['data']['attributes']['summary']);
    $this->assertCount(4, $document['included']);

    $statuses = [];
    foreach ($document['data']['relationships']['children']['data'] as $identifier) {
      $statuses[substr($identifier['id'], 0, 36)] = $identifier['meta']['status'];
    }
    $this->assertSame('same', $statuses[$first->uuid()]);
    $this->assertSame('moved', $statuses[$second->uuid()]);
    $this->assertSame('moved', $statuses[$third->uuid()]);
    $this->assertSame('added', $statuses[$fourth->uuid()]);
  }

  /**
   * A revision compared with itself has status same everywhere.
   */
  public function testSameRevision(): void {
    $node = $this->createArticle(['field_text' => 'text', 'uid' => $this->editor->id()]);
    $this->drupalLogin($this->editor);

    $document = $this->fetch($this->path($node), ['leftVersion' => 'id:1', 'rightVersion' => 'id:1']);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(0, $document['data']['attributes']['summary']['changed']);
    foreach ($document['data']['attributes']['fields'] as $name => $field) {
      $this->assertSame('same', $field['status'], $name);
    }
  }

  /**
   * A paragraph that holds only a reference reports fields as an object.
   *
   * Every field of a group recurses into `children`, so its own map is
   * empty. PHP encodes an empty array as `[]`, so the raw body is read here
   * rather than a decoded document, which cannot tell the two apart.
   */
  public function testReferenceOnlyParagraphReportsAnEmptyObject(): void {
    $this->createGroupParagraphType();
    $this->container->get('router.builder')->rebuild();
    $group = $this->createGroup($this->createBlock('first block'));
    $node = $this->createArticle([
      'field_text' => 'text',
      'field_blocks' => $this->references($group),
      'uid' => $this->editor->id(),
    ]);
    $this->drupalLogin($this->editor);

    $body = $this->drupalGet($this->path($node), [], self::HEADERS);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString('"fields":{}', $body);
    $this->assertStringNotContainsString('"fields":[]', $body);

    $document = json_decode($body, FALSE);
    $diffs = array_values(array_filter($document->included, static fn (\stdClass $diff): bool => str_starts_with($diff->id, $group->uuid() . ':')));
    $this->assertCount(1, $diffs);
    $this->assertInstanceOf(\stdClass::class, $diffs[0]->attributes->fields);
    $this->assertSame([], get_object_vars($diffs[0]->attributes->fields));
  }

  /**
   * A visitor who may read the node cannot read a block it may not view.
   *
   * Both default versions resolve to the published revision, so the node
   * itself is served. The unpublished block inside it is not, and neither
   * are its values, anywhere in the body.
   */
  public function testDeniedChildIsAbsentForAnonymous(): void {
    $shown = $this->createBlock('shown block');
    $denied = $this->createUnpublishedBlock('denied block');
    $node = $this->createArticle([
      'field_text' => 'published text',
      'field_blocks' => $this->references($shown, $denied),
      'uid' => $this->editor->id(),
    ]);
    $this->assertFalse($denied->access('view', new AnonymousUserSession()));

    $body = $this->drupalGet($this->path($node), [], self::HEADERS);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringNotContainsString('denied block', $body);
    $this->assertStringNotContainsString((string) $denied->uuid(), $body);
    $document = json_decode($body, TRUE);
    $this->assertCount(1, $document['included']);
    $this->assertStringStartsWith($shown->uuid() . ':', $document['included'][0]['id']);
    $this->assertSame('shown block', $document['included'][0]['attributes']['fields']['field_body']['left']);
  }

  /**
   * A malformed identifier is a 400 error document.
   */
  public function testMalformedIdentifier(): void {
    $node = $this->createArticle(['uid' => $this->editor->id()]);
    $this->drupalLogin($this->editor);

    $document = $this->fetch($this->path($node), ['leftVersion' => '12']);

    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame('400', $document['errors'][0]['status']);
    $this->assertArrayNotHasKey('data', $document);
  }

  /**
   * Anonymous cannot diff against a draft, and the denial is not cached.
   */
  public function testAnonymousIsDeniedTheDraft(): void {
    $node = $this->draft($this->createArticle(['field_text' => 'published secret', 'uid' => $this->editor->id()]), ['field_text' => 'draft secret']);

    $document = $this->fetch($this->path($node));

    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame('403', $document['errors'][0]['status']);
    $this->assertArrayNotHasKey('data', $document);
    $this->assertSession()->responseNotContains('published secret');
    $this->assertSession()->responseNotContains('draft secret');

    // The same URL serves the editor, so the denial was not cached for them.
    $this->drupalLogin($this->editor);
    $document = $this->fetch($this->path($node));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('draft secret', $document['data']['attributes']['fields']['field_text']['right']);
  }

  /**
   * A denial on one side denies the whole diff.
   */
  public function testOneSideDenied(): void {
    $node = $this->revise($this->createArticle(['field_text' => 'old', 'uid' => $this->editor->id()]), ['field_text' => 'new']);
    $this->drupalLogin($this->asAccount($this->drupalCreateUser(['access content'])));

    // Revision 1 is no longer the default, so it needs revision access.
    $document = $this->fetch($this->path($node), ['leftVersion' => 'id:1', 'rightVersion' => 'id:2']);

    $this->assertSession()->statusCodeEquals(403);
    $this->assertArrayNotHasKey('data', $document);
    $this->assertSession()->responseNotContains('old');
  }

  /**
   * An unknown UUID, a wrong bundle and a non-revisionable type are 404s.
   */
  public function testNotFound(): void {
    $node = $this->createArticle(['uid' => $this->editor->id()]);
    $this->drupalLogin($this->editor);

    foreach ([
      "/jsonapi/diff/node/article/00000000-0000-4000-8000-000000000000",
      "/jsonapi/diff/node/page/{$node->uuid()}",
      "/jsonapi/diff/user/user/{$this->editor->uuid()}",
    ] as $path) {
      $document = $this->fetch($path);
      $this->assertSession()->statusCodeEquals(404);
      $this->assertSame('404', $document['errors'][0]['status'], $path);
    }
  }

  /**
   * The related links resolve to the compared versions on core's route.
   */
  public function testRelatedLinksResolve(): void {
    $node = $this->draft($this->createArticle(['title' => 'Published', 'uid' => $this->editor->id()]), ['title' => 'Draft']);
    $this->drupalLogin($this->editor);
    $document = $this->fetch($this->path($node));
    $relationships = $document['data']['relationships'];

    $left = $this->fetch($relationships['left']['links']['related']['href']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('Published', $left['data']['attributes']['title']);
    $this->assertSame(1, $left['data']['attributes']['drupal_internal__vid']);

    $right = $this->fetch($relationships['right']['links']['related']['href']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('Draft', $right['data']['attributes']['title']);
    $this->assertSame(2, $right['data']['attributes']['drupal_internal__vid']);
  }

  /**
   * A repeat request is a cache hit until the entity is saved again.
   */
  public function testSavingInvalidatesTheCachedDiff(): void {
    $node = $this->draft($this->createArticle(['uid' => $this->editor->id()]), ['title' => 'Draft']);
    $this->drupalLogin($this->publisher);

    $this->fetch($this->path($node));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->fetch($this->path($node));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');

    $this->draft($node, ['title' => 'Second draft']);

    $document = $this->fetch($this->path($node));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSame(3, $document['data']['relationships']['right']['data']['meta']['drupal_internal__revision_id']);
  }

  /**
   * Different version pairs are cached separately.
   */
  public function testVersionPairsAreCachedSeparately(): void {
    $node = $this->revise($this->revise($this->createArticle(['field_text' => 'one', 'uid' => $this->editor->id()]), ['field_text' => 'two']), ['field_text' => 'three']);
    $this->drupalLogin($this->publisher);

    $bare = $this->fetch($this->path($node));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSame(3, $bare['data']['relationships']['left']['data']['meta']['drupal_internal__revision_id']);

    $explicit = $this->fetch($this->path($node), ['leftVersion' => 'id:1']);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSame(1, $explicit['data']['relationships']['left']['data']['meta']['drupal_internal__revision_id']);
    $this->assertSame('one', $explicit['data']['attributes']['fields']['field_text']['left']);

    $this->fetch($this->path($node));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
  }

  /**
   * Two sparse fieldsets are cached separately.
   */
  public function testFieldsetsAreCachedSeparately(): void {
    $node = $this->revise($this->createArticle(['field_text' => 'one', 'uid' => $this->editor->id()]), ['field_text' => 'two']);
    $this->drupalLogin($this->publisher);
    $query = ['leftVersion' => 'id:1', 'rightVersion' => 'id:2'];

    $summary = $this->fetch($this->path($node), $query + $this->fieldset('summary'));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertArrayHasKey('summary', $summary['data']['attributes']);
    $this->assertArrayNotHasKey('fields', $summary['data']['attributes']);

    $fields = $this->fetch($this->path($node), $query + $this->fieldset('fields'));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertArrayHasKey('fields', $fields['data']['attributes']);
    $this->assertArrayNotHasKey('summary', $fields['data']['attributes']);

    $repeat = $this->fetch($this->path($node), $query + $this->fieldset('summary'));
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->assertSame($summary, $repeat);
  }

  /**
   * Behind a language prefix both sides are compared in that language.
   */
  public function testTranslatedPair(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->rebuildContainer();
    $this->container->get('router.builder')->rebuild();

    $node = $this->createArticle(['field_text' => 'Hello', 'uid' => $this->editor->id()]);
    $node->addTranslation('fr', ['title' => 'Article', 'field_text' => 'Bonjour', 'uid' => $this->editor->id()])->save();
    $revision = $this->loadNodeRevision((int) $node->getRevisionId());
    $revision->getTranslation('fr')->set('field_text', 'Bonsoir');
    $revision->setNewRevision(TRUE);
    $revision->save();
    $this->drupalLogin($this->editor);
    $query = ['leftVersion' => 'id:1', 'rightVersion' => 'id:2'];

    $french = $this->fetch('/fr' . $this->path($node), $query);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('Bonjour', $french['data']['attributes']['fields']['field_text']['left']);
    $this->assertSame('Bonsoir', $french['data']['attributes']['fields']['field_text']['right']);
    $this->assertSame('changed', $french['data']['attributes']['fields']['field_text']['status']);

    $english = $this->fetch($this->path($node), $query);
    $this->assertSame('Hello', $english['data']['attributes']['fields']['field_text']['left']);
    $this->assertSame('same', $english['data']['attributes']['fields']['field_text']['status']);
  }

  /**
   * Builds the diff path of a node.
   */
  protected function path(NodeInterface $node): string {
    return "/jsonapi/diff/node/{$node->bundle()}/{$node->uuid()}";
  }

  /**
   * Builds the query parameter of a sparse fieldset on the diff type.
   *
   * @param string $members
   *   The member names, comma separated.
   *
   * @return array<string, array<string, string>>
   *   The query parameter.
   */
  protected function fieldset(string $members): array {
    return ['fields' => [DiffResourceObject::TYPE_NAME => $members]];
  }

  /**
   * Fetches a JSON:API document as the current session's user.
   *
   * @param string $path
   *   The path, or an absolute URL from a link.
   * @param array<string, string> $query
   *   The query parameters.
   *
   * @return array<string, mixed>
   *   The decoded document, or an empty array when the body is not JSON.
   */
  protected function fetch(string $path, array $query = []): array {
    $content = $this->drupalGet($path, ['query' => $query], self::HEADERS);
    return json_decode($content, TRUE) ?? [];
  }

}
