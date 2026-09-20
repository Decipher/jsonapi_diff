<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_diff\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\jsonapi\JsonApiResource\IncludedData;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi_diff\Comparison\ChildDiff;
use Drupal\jsonapi_diff\Comparison\EntityDiff;
use Drupal\jsonapi_diff\Comparison\FieldDiff;
use Drupal\jsonapi_diff\Comparison\ItemDiff;
use Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the document shape on core JSON:API's own value objects.
 *
 * No entity is compared here. The diffs are built by hand, so the test
 * pins what the normalizer does with a resource object that is not an
 * entity: the relatable fields land under `relationships` with the
 * identifiers' meta intact, the rest under `attributes`, and further
 * resource objects under `included`.
 *
 * @group jsonapi_diff
 */
#[Group('jsonapi_diff')]
class DiffDocumentTest extends KernelTestBase {

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
   * The resource type of a node the diffs compare.
   */
  protected ResourceType $articleType;

  /**
   * The diff resource type.
   */
  protected ResourceType $diffType;

  /**
   * The UUID of a node that exists, so its related links resolve.
   *
   * The link normalizer checks access to each link's route, and that upcasts
   * the UUID. A link to a node that does not exist is dropped.
   */
  protected string $uuid;

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
    $node = Node::create(['type' => 'article', 'title' => 'Article']);
    $node->save();
    $this->uuid = (string) $node->uuid();
    // The related and self links need the JSON:API and diff routes.
    $this->container->get('router.builder')->rebuild();

    $repository = $this->container->get('jsonapi.resource_type.repository');
    $article = $repository->get('node', 'article');
    $this->assertInstanceOf(ResourceType::class, $article);
    $this->articleType = $article;
    $this->diffType = DiffResourceObject::resourceType([$article]);
  }

  /**
   * Relatable fields normalize as relationships, the rest as attributes.
   */
  public function testRelationshipsAndAttributesAreSplit(): void {
    $ops = [
      ['type' => '-', 'lines' => ['one']],
      ['type' => '+', 'lines' => ['two']],
    ];
    $items = [new ItemDiff(0, FieldDiff::CHANGED, 'one', 'two', $ops)];
    $fields = ['title' => new FieldDiff('Title', FieldDiff::CHANGED, 'one', 'two', $ops, $items)];
    $diff = $this->entityDiff($this->uuid, 1, 2, $fields);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'rel:latest-version', 'id:2');

    $data = $this->normalize($root, [])['data'];

    $this->assertSame('jsonapi_diff--diff', $data['type']);
    $this->assertSame($this->uuid . ':1:2', $data['id']);
    $this->assertSame(['summary', 'tree_summary', 'fields'], array_keys($data['attributes']));
    $this->assertSame(['left', 'right', 'children'], array_keys($data['relationships']));
    $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 1, 'same' => 0], $data['attributes']['summary']);
    $this->assertSame([
      'label' => 'Title',
      'status' => 'changed',
      'left' => 'one',
      'right' => 'two',
      'ops' => [
        ['type' => '-', 'lines' => ['one']],
        ['type' => '+', 'lines' => ['two']],
      ],
      'items' => [
        [
          'delta' => 0,
          'status' => 'changed',
          'left' => 'one',
          'right' => 'two',
          'ops' => [
            ['type' => '-', 'lines' => ['one']],
            ['type' => '+', 'lines' => ['two']],
          ],
        ],
      ],
    ], $data['attributes']['fields']['title']);
  }

  /**
   * The items of a field encode as a JSON array, empty or not.
   *
   * The `fields` map needs a cast to encode as a JSON object. A list of
   * items needs none, which is why the per-item entries are a list and not
   * a map keyed by delta.
   */
  public function testItemsEncodeAsAnArray(): void {
    $two = [
      new ItemDiff(0, FieldDiff::SAME, 'one', 'one', [['type' => '=', 'lines' => ['one']]]),
      new ItemDiff(1, FieldDiff::ADDED, '', 'two', [['type' => '+', 'lines' => ['two']]]),
    ];
    $fields = [
      'field_lines' => new FieldDiff('Lines', FieldDiff::CHANGED, 'one', "one\ntwo", [], $two),
      'title' => new FieldDiff('Title', FieldDiff::SAME, 'one', 'one', [], []),
    ];
    $diff = $this->entityDiff($this->uuid, 1, 2, $fields);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'id:1', 'id:2');
    $document = new JsonApiDocumentTopLevel(new ResourceObjectData([$root], 1), new IncludedData([]), new LinkCollection([]));

    $normalization = $this->container->get('jsonapi.serializer')->normalize($document, 'api_json', []);
    $this->assertInstanceOf(CacheableNormalization::class, $normalization);
    $json = (string) json_encode($normalization->getNormalization());

    $this->assertStringContainsString('"items":[{"delta":0,', $json);
    $this->assertStringContainsString('"items":[]', $json);
    $decoded = json_decode($json, FALSE);
    $this->assertIsArray($decoded->data->attributes->fields->field_lines->items);
    $this->assertSame([0, 1], array_column((array) json_decode($json, TRUE)['data']['attributes']['fields']['field_lines']['items'], 'delta'));
  }

  /**
   * The tree summary carries the children's counts, the summary does not.
   */
  public function testTreeSummaryRollsUpTheChildren(): void {
    $child = $this->entityDiff('c1', 5, 6, ['field_body' => new FieldDiff('Body', FieldDiff::CHANGED, 'one', 'two', [], [])]);
    $diff = $this->entityDiff($this->uuid, 1, 2, ['title' => new FieldDiff('Title', FieldDiff::SAME, 'one', 'one', [], [])], [
      new ChildDiff('field_blocks', 0, 0, ChildDiff::SAME, $child),
    ]);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'id:1', 'id:2');

    $data = $this->normalize($root, [new DiffResourceObject($this->diffType, $this->articleType, $child, 'id:5', 'id:6')]);

    $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 0, 'same' => 1], $data['data']['attributes']['summary']);
    $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 1, 'same' => 1], $data['data']['attributes']['tree_summary']);
    // A leaf reports the same counts twice.
    $included = $data['included'][0]['attributes'];
    $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 1, 'same' => 0], $included['summary']);
    $this->assertSame($included['summary'], $included['tree_summary']);
  }

  /**
   * The identifiers keep their meta, and the links point at the versions.
   */
  public function testSideRelationshipsCarryVersionMetaAndRelatedLinks(): void {
    $diff = $this->entityDiff($this->uuid, 1, 2);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'rel:latest-version', 'id:2');

    $data = $this->normalize($root, [])['data'];

    $this->assertSame([
      'type' => 'node--article',
      'id' => $this->uuid,
      'meta' => ['resourceVersion' => 'rel:latest-version', 'drupal_internal__revision_id' => 1],
    ], $data['relationships']['left']['data']);
    $this->assertSame([
      'type' => 'node--article',
      'id' => $this->uuid,
      'meta' => ['resourceVersion' => 'id:2', 'drupal_internal__revision_id' => 2],
    ], $data['relationships']['right']['data']);
    $this->assertStringEndsWith('/jsonapi/node/article/' . $this->uuid . '?' . $this->query(['resourceVersion' => 'rel:latest-version']), $data['relationships']['left']['links']['related']['href']);
    $this->assertStringEndsWith('/jsonapi/node/article/' . $this->uuid . '?' . $this->query(['resourceVersion' => 'id:2']), $data['relationships']['right']['links']['related']['href']);
    $this->assertStringEndsWith('/jsonapi/diff/node/article/' . $this->uuid . '?' . $this->query(['leftVersion' => 'rel:latest-version', 'rightVersion' => 'id:2']), $data['links']['self']['href']);
  }

  /**
   * Children are identifiers with structural meta, and their diffs included.
   */
  public function testChildrenAreIncludedWithStructuralMeta(): void {
    $moved = $this->entityDiff('c1', 5, 6);
    $added = $this->entityDiff('c2', NULL, 7);
    $diff = $this->entityDiff($this->uuid, 1, 2, [], [
      new ChildDiff('field_blocks', 0, 1, ChildDiff::MOVED, $moved),
      new ChildDiff('field_blocks', NULL, 0, ChildDiff::ADDED, $added),
    ]);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'rel:latest-version', 'rel:working-copy');
    $included = [
      new DiffResourceObject($this->diffType, $this->articleType, $moved, 'id:5', 'id:6'),
      new DiffResourceObject($this->diffType, $this->articleType, $added, NULL, 'id:7'),
    ];

    $document = $this->normalize($root, $included);

    $this->assertSame([
      ['type' => 'jsonapi_diff--diff', 'id' => 'c1:5:6', 'meta' => ['field' => 'field_blocks', 'left_delta' => 0, 'right_delta' => 1, 'status' => 'moved']],
      ['type' => 'jsonapi_diff--diff', 'id' => 'c2::7', 'meta' => ['field' => 'field_blocks', 'left_delta' => NULL, 'right_delta' => 0, 'status' => 'added']],
    ], $document['data']['relationships']['children']['data']);
    $this->assertCount(2, $document['included']);
    $this->assertSame('c1:5:6', $document['included'][0]['id']);
    $this->assertStringEndsWith('?' . $this->query(['leftVersion' => 'id:5', 'rightVersion' => 'id:6']), $document['included'][0]['links']['self']['href']);

    $one_sided = $document['included'][1];
    $this->assertSame('c2::7', $one_sided['id']);
    $this->assertNull($one_sided['relationships']['left']['data']);
    $this->assertArrayNotHasKey('links', $one_sided['relationships']['left']);
    $this->assertSame('id:7', $one_sided['relationships']['right']['data']['meta']['resourceVersion']);
    $this->assertArrayNotHasKey('links', $one_sided);
    $this->assertSame([], $one_sided['relationships']['children']['data']);
  }

  /**
   * Without children there is no included member.
   */
  public function testNoIncludedMemberWithoutChildren(): void {
    $root = new DiffResourceObject($this->diffType, $this->articleType, $this->entityDiff($this->uuid, 1, 2), 'id:1', 'id:2');

    $document = $this->normalize($root, []);

    $this->assertArrayNotHasKey('included', $document);
    $this->assertSame([], $document['data']['relationships']['children']['data']);
  }

  /**
   * The map attributes encode as objects when they hold nothing.
   *
   * PHP encodes an empty array as `[]`, so the assertions are made on the
   * encoded string and on the decoded objects. A PHP-side comparison cannot
   * tell an empty map from an empty list.
   */
  public function testEmptyFieldsEncodeAsAnObject(): void {
    $root = new DiffResourceObject($this->diffType, $this->articleType, $this->entityDiff($this->uuid, 1, 2), 'id:1', 'id:2');
    $document = new JsonApiDocumentTopLevel(new ResourceObjectData([$root], 1), new IncludedData([]), new LinkCollection([]));

    $normalization = $this->container->get('jsonapi.serializer')->normalize($document, 'api_json', []);
    $this->assertInstanceOf(CacheableNormalization::class, $normalization);
    $json = (string) json_encode($normalization->getNormalization());

    $this->assertStringContainsString('"fields":{}', $json);
    $this->assertStringNotContainsString('"fields":[]', $json);
    $decoded = json_decode($json, FALSE);
    $this->assertInstanceOf(\stdClass::class, $decoded->data->attributes->fields);
    $this->assertInstanceOf(\stdClass::class, $decoded->data->attributes->summary);
    $this->assertInstanceOf(\stdClass::class, $decoded->data->attributes->tree_summary);
  }

  /**
   * The normalization carries the diff's cacheability and the contexts.
   */
  public function testNormalizationCacheability(): void {
    $diff = $this->entityDiff($this->uuid, 1, 2);
    $root = new DiffResourceObject($this->diffType, $this->articleType, $diff, 'id:1', 'id:2');

    $normalization = $this->container->get('jsonapi.serializer')->normalize($root, 'api_json', []);
    $this->assertInstanceOf(CacheableNormalization::class, $normalization);

    $this->assertContains('node:9', $normalization->getCacheTags());
    foreach (['url.query_args:leftVersion', 'url.query_args:rightVersion', 'user.permissions', 'languages:language_content'] as $context) {
      $this->assertContains($context, $normalization->getCacheContexts());
    }
  }

  /**
   * Builds a query string the way a link's href encodes one.
   *
   * @param array<string, string> $parameters
   *   The query parameters.
   */
  protected function query(array $parameters): string {
    return http_build_query($parameters);
  }

  /**
   * Normalizes a document with the root as primary data.
   *
   * @param \Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject $root
   *   The primary data.
   * @param \Drupal\jsonapi_diff\JsonApiResource\DiffResourceObject[] $included
   *   The included resource objects.
   *
   * @return array<string, mixed>
   *   The normalized document.
   */
  protected function normalize(DiffResourceObject $root, array $included): array {
    $document = new JsonApiDocumentTopLevel(new ResourceObjectData([$root], 1), new IncludedData($included), new LinkCollection([]));
    $normalization = $this->container->get('jsonapi.serializer')->normalize($document, 'api_json', []);
    $this->assertInstanceOf(CacheableNormalization::class, $normalization);
    $normalized = $normalization->getNormalization();
    $this->assertIsArray($normalized);
    // Encode and decode, so the assertions see what a client sees.
    return json_decode((string) json_encode($normalized), TRUE);
  }

  /**
   * Builds an article diff by hand.
   *
   * @param string $uuid
   *   The entity UUID.
   * @param int|null $left
   *   The left revision id, or NULL when absent on the left.
   * @param int|null $right
   *   The right revision id, or NULL when absent on the right.
   * @param array<string, \Drupal\jsonapi_diff\Comparison\FieldDiff> $fields
   *   The field diffs.
   * @param list<\Drupal\jsonapi_diff\Comparison\ChildDiff> $children
   *   The children.
   */
  protected function entityDiff(string $uuid, ?int $left, ?int $right, array $fields = [], array $children = []): EntityDiff {
    $counts = array_count_values(array_map(static fn (FieldDiff $field): string => $field->status, $fields));
    return new EntityDiff('node', 'article', $uuid, $left, $right, $fields, [
      'added' => $counts['added'] ?? 0,
      'removed' => $counts['removed'] ?? 0,
      'changed' => $counts['changed'] ?? 0,
      'same' => $counts['same'] ?? 0,
    ], $children, (new CacheableMetadata())->addCacheTags(['node:9']));
  }

}
