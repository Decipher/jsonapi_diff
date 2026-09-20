<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Kernel;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\jsonapi_diff\Resource\DiffResource;
use Drupal\jsonapi_diff_test\TestAccess;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\jsonapi_diff\Traits\DiffContentTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests the diff resource driven directly, without the HTTP stack.
 *
 * The resource is built as jsonapi_resources builds it, given a request,
 * and its response is rendered by core's response subscriber. The
 * assertions read the JSON a client would.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffResourceTest extends KernelTestBase {

  use DiffContentTrait;
  use UserCreationTrait;

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
    'jsonapi_diff_test',
  ];

  /**
   * The resource under test, with its setter injections applied.
   */
  protected DiffResource $resource;

  /**
   * The route resource types the enhancer would attach to the request.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceType[]
   */
  protected array $resourceTypes;

  /**
   * A user who may read every revision of the nodes they own.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'diff']);
    $this->createDiffContentTypes();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // The links and the access checker need the routes.
    $this->container->get('router.builder')->rebuild();

    $this->editor = $this->asAccount($this->createUser([
      'access content',
      'view own unpublished content',
      'view all revisions',
    ]));
    $this->setCurrentUser($this->editor);

    $this->resource = DiffResource::create($this->container);
    $this->resource->setResourceTypeRepository($this->container->get('jsonapi.resource_type.repository'));
    $this->resource->setResourceResponseFactory($this->container->get('jsonapi_resources.resource_response_factory'));
    $this->resource->setDocumentExtractor($this->container->get('jsonapi_resources.document_extractor'));
    $this->resourceTypes = $this->resource->getRouteResourceTypes(new Route('/%jsonapi%/diff/{entity_type}/{bundle}/{uuid}'), 'jsonapi_diff.diff');
  }

  /**
   * The bare route compares the default revision with the working copy.
   */
  public function testBareRouteComparesPublishedWithDraft(): void {
    $node = $this->draft($this->createArticle(['field_text' => 'published text', 'uid' => $this->editor->id()]), ['field_text' => 'draft text']);

    $document = $this->diff($node);

    $data = $document['data'];
    $this->assertSame('jsonapi_diff--diff', $data['type']);
    $this->assertSame($node->uuid() . ':1:2', $data['id']);
    $this->assertSame('rel:latest-version', $data['relationships']['left']['data']['meta']['resourceVersion']);
    $this->assertSame(1, $data['relationships']['left']['data']['meta']['drupal_internal__revision_id']);
    $this->assertSame('rel:working-copy', $data['relationships']['right']['data']['meta']['resourceVersion']);
    $this->assertSame(2, $data['relationships']['right']['data']['meta']['drupal_internal__revision_id']);
    $this->assertSame('changed', $data['attributes']['fields']['field_text']['status']);
    $this->assertSame('published text', $data['attributes']['fields']['field_text']['left']);
    $this->assertSame('draft text', $data['attributes']['fields']['field_text']['right']);
    $this->assertArrayNotHasKey('included', $document);
    $this->assertSame([], $data['relationships']['children']['data']);
  }

  /**
   * The related links name the versions, and the self link the pair.
   */
  public function testLinks(): void {
    $node = $this->draft($this->createArticle(['uid' => $this->editor->id()]), ['title' => 'Draft']);
    $uuid = $node->uuid();

    $document = $this->diff($node, ['rightVersion' => 'id:2']);

    $relationships = $document['data']['relationships'];
    $this->assertStringEndsWith("/jsonapi/node/article/$uuid?" . http_build_query(['resourceVersion' => 'rel:latest-version']), $relationships['left']['links']['related']['href']);
    $this->assertStringEndsWith("/jsonapi/node/article/$uuid?" . http_build_query(['resourceVersion' => 'id:2']), $relationships['right']['links']['related']['href']);
    $this->assertStringEndsWith("/jsonapi/diff/node/article/$uuid?" . http_build_query(['leftVersion' => 'rel:latest-version', 'rightVersion' => 'id:2']), $document['data']['links']['self']['href']);
    $this->assertStringEndsWith("/jsonapi/diff/node/article/$uuid?" . http_build_query(['rightVersion' => 'id:2']), $document['links']['self']['href']);
  }

  /**
   * The id is the same however the pair was named.
   */
  public function testIdIsStableAcrossSpellings(): void {
    $node = $this->draft($this->createArticle(['uid' => $this->editor->id()]), ['title' => 'Draft']);

    $bare = $this->diff($node)['data']['id'];
    $explicit = $this->diff($node, ['leftVersion' => 'id:1', 'rightVersion' => 'id:2'])['data']['id'];

    $this->assertSame($node->uuid() . ':1:2', $bare);
    $this->assertSame($bare, $explicit);
  }

  /**
   * Every entity in the tree is one diff resource, children in included.
   */
  public function testChildrenAreIncludedWithTheirPlace(): void {
    [$node, $blocks] = $this->createReshapedArticle(['uid' => $this->editor->id()]);
    [$first, $third, $second, $fourth] = $blocks;
    $published = $this->blockRevisions($this->loadNodeRevision(1));
    $drafted = $this->blockRevisions($this->loadNodeRevision(2));

    $document = $this->diff($node);

    $children = $document['data']['relationships']['children']['data'];
    $this->assertCount(4, $children);
    $this->assertCount(4, $document['included']);

    $this->assertSame(['field' => 'field_blocks', 'left_delta' => 0, 'right_delta' => 0, 'status' => 'same'], $this->childMeta($children, $first));
    $this->assertSame(['field' => 'field_blocks', 'left_delta' => 1, 'right_delta' => 2, 'status' => 'moved'], $this->childMeta($children, $second));
    $this->assertSame(['field' => 'field_blocks', 'left_delta' => 2, 'right_delta' => 1, 'status' => 'moved'], $this->childMeta($children, $third));
    $this->assertSame(['field' => 'field_blocks', 'left_delta' => NULL, 'right_delta' => 3, 'status' => 'added'], $this->childMeta($children, $fourth));

    $edited = $this->includedFor($document, $first);
    $this->assertSame('paragraph--block', $edited['relationships']['left']['data']['type']);
    $this->assertSame('id:' . $published[(int) $first->id()], $edited['relationships']['left']['data']['meta']['resourceVersion']);
    $this->assertSame('id:' . $drafted[(int) $first->id()], $edited['relationships']['right']['data']['meta']['resourceVersion']);
    $this->assertSame('changed', $edited['attributes']['fields']['field_body']['status']);
    $this->assertSame([
      ['type' => '-', 'lines' => ['first block']],
      ['type' => '+', 'lines' => ['first block, edited']],
    ], $edited['attributes']['fields']['field_body']['ops']);

    $added = $this->includedFor($document, $fourth);
    $this->assertNull($added['relationships']['left']['data']);
    $this->assertSame('added', $added['attributes']['fields']['field_body']['status']);
    $this->assertSame(1, $added['attributes']['summary']['added']);
    $this->assertArrayNotHasKey('links', $added);

    $moved = $this->includedFor($document, $second);
    $this->assertSame('same', $moved['attributes']['fields']['field_body']['status']);
    $expected_query = http_build_query([
      'leftVersion' => 'id:' . $published[(int) $second->id()],
      'rightVersion' => 'id:' . $drafted[(int) $second->id()],
    ]);
    $this->assertStringEndsWith('/jsonapi/diff/paragraph/block/' . $second->uuid() . '?' . $expected_query, $moved['links']['self']['href']);
  }

  /**
   * A removed block is reported on the left only.
   */
  public function testRemovedChild(): void {
    $kept = $this->createBlock('kept');
    $removed = $this->createBlock('old');
    $node = $this->createArticle(['field_blocks' => $this->references($kept, $removed), 'uid' => $this->editor->id()]);
    $this->reviseBlock($kept);
    $node = $this->draft($node, ['field_blocks' => $this->references($kept)]);

    $document = $this->diff($node);

    $children = $document['data']['relationships']['children']['data'];
    $this->assertSame(['field' => 'field_blocks', 'left_delta' => 1, 'right_delta' => NULL, 'status' => 'removed'], $this->childMeta($children, $removed));
    $gone = $this->includedFor($document, $removed);
    $this->assertNull($gone['relationships']['right']['data']);
    $this->assertSame($removed->uuid() . ':' . $this->blockRevisions($this->loadNodeRevision(1))[(int) $removed->id()] . ':', $gone['id']);
    $this->assertSame('removed', $gone['attributes']['fields']['field_body']['status']);
  }

  /**
   * A child the user may not view is nowhere in the encoded document.
   *
   * The raw body is read, not the decoded document, so a value that leaked
   * through any other member fails the test too.
   */
  public function testDeniedChildIsAbsentFromTheDocument(): void {
    $shown = $this->createBlock('shown body');
    $denied = $this->createUnpublishedBlock('denied body');
    $node = $this->createArticle(['field_blocks' => $this->references($shown, $denied), 'uid' => $this->editor->id()]);
    $this->reviseBlock($shown, 'shown body, edited');
    $this->reviseBlock($denied, 'denied body, edited');
    $node = $this->draft($node, ['field_blocks' => $this->references($shown, $denied)]);
    $this->assertFalse($denied->access('view', $this->editor));
    $this->assertTrue($shown->access('view', $this->editor));

    $body = (string) $this->respond($node, [])->getContent();

    $this->assertStringNotContainsString('denied body', $body);
    $this->assertStringNotContainsString((string) $denied->uuid(), $body);
    $document = json_decode($body, TRUE);
    $this->assertIsArray($document);
    $this->assertCount(1, $document['included']);
    $this->assertCount(1, $document['data']['relationships']['children']['data']);
    $this->assertStringStartsWith($shown->uuid() . ':', $document['data']['relationships']['children']['data'][0]['id']);
    $this->assertSame('shown body, edited', $this->includedFor($document, $shown)['attributes']['fields']['field_body']['right']);
  }

  /**
   * The response carries what a per-user field access rule decided.
   */
  public function testFieldAccessCacheability(): void {
    $node = $this->draft($this->createArticle(['field_text' => 'text', 'uid' => $this->editor->id()]), ['field_text' => 'text changed']);

    $response = $this->respond($node, []);

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $metadata = $response->getCacheableMetadata();
    $this->assertContains(TestAccess::CACHE_TAG, $metadata->getCacheTags());
    $this->assertContains('user', $metadata->getCacheContexts());
  }

  /**
   * The response carries the pair's, the tree's and the route's cacheability.
   */
  public function testResponseCacheability(): void {
    [$node, $blocks] = $this->createReshapedArticle(['uid' => $this->editor->id()]);

    $response = $this->respond($node, []);
    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $metadata = $response->getCacheableMetadata();

    foreach (['url.query_args:leftVersion', 'url.query_args:rightVersion', 'user.permissions', 'languages:language_content'] as $context) {
      $this->assertContains($context, $metadata->getCacheContexts());
    }
    $this->assertContains('node:' . $node->id(), $metadata->getCacheTags());
    foreach ($blocks as $block) {
      $this->assertContains('paragraph:' . $block->id(), $metadata->getCacheTags());
    }
    $this->assertContains('config:diff.plugins', $metadata->getCacheTags());
  }

  /**
   * The resolver's errors reach the client unchanged.
   */
  public function testResolverErrorsPropagate(): void {
    $node = $this->createArticle(['uid' => $this->editor->id()]);

    $this->expectException(CacheableBadRequestHttpException::class);
    $this->respond($node, ['leftVersion' => '12']);
  }

  /**
   * An unknown UUID, a wrong bundle and a non-revisionable type are 404s.
   */
  public function testNotFound(): void {
    $node = $this->createArticle(['uid' => $this->editor->id()]);
    $cases = [
      'unknown uuid' => ['node', 'article', '00000000-0000-4000-8000-000000000000'],
      'wrong bundle' => ['node', 'page', $node->uuid()],
      'unknown bundle' => ['node', 'nope', $node->uuid()],
      'unknown entity type' => ['nope', 'nope', $node->uuid()],
      'not revisionable' => ['user', 'user', $this->editor->uuid()],
    ];
    foreach ($cases as $label => [$entity_type, $bundle, $uuid]) {
      try {
        $this->resource->process($this->request($entity_type, $bundle, $uuid, []), $this->resourceTypes, $entity_type, $bundle, $uuid);
        $this->fail("$label was served.");
      }
      catch (CacheableNotFoundHttpException $exception) {
        $this->assertSame(404, $exception->getStatusCode(), $label);
        $this->assertContains('url.query_args:leftVersion', $exception->getCacheContexts(), $label);
      }
    }
  }

  /**
   * Processes a diff request for a node and decodes the document.
   *
   * @return array<string, mixed>
   *   The decoded document.
   */
  protected function diff(NodeInterface $node, array $query = []): array {
    $response = $this->respond($node, $query);
    $this->assertSame(200, $response->getStatusCode());
    $document = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($document);
    return $document;
  }

  /**
   * Processes a diff request for a node and renders the response.
   */
  protected function respond(NodeInterface $node, array $query): Response {
    $request = $this->request('node', $node->bundle(), (string) $node->uuid(), $query);
    $response = $this->resource->process($request, $this->resourceTypes, 'node', $node->bundle(), (string) $node->uuid());
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $this->container->get('jsonapi.resource_response.subscriber')->onResponse($event);
    return $event->getResponse();
  }

  /**
   * Builds the request the route would receive.
   */
  protected function request(string $entity_type, string $bundle, string $uuid, array $query): Request {
    return Request::create("/jsonapi/diff/$entity_type/$bundle/$uuid", 'GET', $query);
  }

  /**
   * Finds the meta of the child identifier for a paragraph.
   */
  protected function childMeta(array $children, ParagraphInterface $paragraph): array {
    foreach ($children as $identifier) {
      if (str_starts_with($identifier['id'], $paragraph->uuid() . ':')) {
        return $identifier['meta'];
      }
    }
    $this->fail('No child identifier for paragraph ' . $paragraph->id());
  }

  /**
   * Finds the included diff resource for a paragraph.
   */
  protected function includedFor(array $document, ParagraphInterface $paragraph): array {
    foreach ($document['included'] as $resource) {
      if (str_starts_with($resource['id'], $paragraph->uuid() . ':')) {
        return $resource;
      }
    }
    $this->fail('No included diff for paragraph ' . $paragraph->id());
  }

}
