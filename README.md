# JSON:API Diff

[![Pipeline](https://git.drupalcode.org/project/jsonapi_diff/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/jsonapi_diff/-/pipelines)
[![Test](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x)

Exposes the difference between two revisions of an entity as a JSON:API
document.

Core JSON:API already serves any revision of an entity, so a decoupled editor
can switch a page between its published version and a draft. What it cannot say
is what changed. The Diff module works that out, field by field and down through
paragraphs, but renders the answer as admin-theme HTML. This module puts the
same comparison on a JSON:API route, as data a frontend can render itself.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/jsonapi_diff).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/jsonapi_diff).

## Table of contents

- Requirements
- Installation
- The endpoint
- The document
- Reading the tree in a client
- Access
- Discovery
- Which fields are compared
- Limitations
- What it deliberately does not do
- For module maintainers
- FAQ
- Maintainers

## Requirements

- PHP 8.3 or later
- Drupal 10.5 or 11
- JSON:API (Drupal core)
- [Diff](https://www.drupal.org/project/diff)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

[JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia) is
optional. It adds the discovery link described under Discovery, and the route
works without it.

## Installation

1. Download and install via Composer:

   ```bash
   composer require drupal/jsonapi_diff
   ```

2. Enable the module:

   ```bash
   drush en jsonapi_diff
   ```

There is nothing here to configure. Which fields compare, and how, is already
Diff's own configuration at `/admin/config/content/diff/fields`. Read
"Which fields are compared" below before assuming a field will show up, because
Diff's default for a field it has no setting for surprises most people once.

## The endpoint

One route, read-only:

```
GET /jsonapi/diff/{entity_type}/{bundle}/{uuid}
```

Drupal's vocabulary for this is unavoidable, so in short:

| Term | Means |
| --- | --- |
| Revision | One saved state of an entity. Saving an entity again adds a revision rather than overwriting the last one |
| Default revision | The revision Drupal serves when nobody asks for a particular one. On a plain site that is the published one |
| Working copy | The newest revision of all, published or not. A draft saved over a published page is the working copy while the published revision stays the default |

A query parameter names each side. Each holds a core JSON:API resource version
identifier, the same grammar `resourceVersion` takes on the individual route,
resolved by the same negotiator.

| Parameter | Default | Accepts |
| --- | --- | --- |
| `leftVersion` | `rel:latest-version` | `id:<revision id>`, `rel:latest-version`, `rel:working-copy` |
| `rightVersion` | `rel:working-copy` | The same three forms |

`rel:latest-version` is the newest default revision, which on an ordinary site
is the published one. `rel:working-copy` is the newest revision of all, default
or not. So the bare URL, with no query parameters, compares the published
revision against the working copy:

```bash
curl -H 'Accept: application/vnd.api+json' \
  https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08
```

Nothing forces `left` to be the older side, but the defaults and the names read
that way: `left` is where the content came from and `right` is where it went, so
a `+` in `ops` is an addition on the right.

An explicit pair is two identifiers:

```bash
curl -H 'Accept: application/vnd.api+json' \
  'https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?leftVersion=id:12&rightVersion=id:15'
```

A revision id for the `id:` form comes from `attributes.drupal_internal__vid` on
a node fetched from core's individual route, which core names
`drupal_internal__` plus the entity type's revision key. It also comes from
`drupal_internal__revision_id` in the `meta` of this document's own `left` and
`right` relationships. Nothing here lists an entity's revisions, and core
JSON:API does not expose a route for that either.

Error statuses match what core JSON:API gives for the same request on its own
individual route.

| Situation | Status |
| --- | --- |
| An identifier outside the grammar, such as `leftVersion=12` | `400` |
| A revision id that exists but belongs to another entity | `404` |
| An unknown UUID, a UUID of another bundle, or an entity type that does not keep revisions | `404` |
| Either side denied | `403` |

Comparing a revision with itself is allowed. Every field comes back with status
`same`.

### The capital letter in `leftVersion`

Core validates query parameter names on every JSON:API route, this one included,
and `JsonApiSpec::isValidCustomQueryParameter()` requires a custom name to hold
at least one character outside `a-z`. All-lowercase names are reserved for the
specification itself. So `?left=` and `?right=` are refused with a `400` before
the route even runs, which is the same reason core's own parameter is
`resourceVersion` and not `version`.

Member names inside the document have no such rule, and they are `left` and
`right`. The two spellings are deliberate rather than an oversight:
`leftVersion` in the query, `left` in the document.

## The document

Primary data is the diff of the addressed entity. Every entity the comparison
recursed into gets its own resource of the same type in `included`, to any
depth, so one request returns the whole tree.

| Member | Holds |
| --- | --- |
| `type` | Always `jsonapi_diff--diff` |
| `id` | `{entity uuid}:{left revision id}:{right revision id}` |
| `attributes.summary` | The entity's own fields counted by status |
| `attributes.tree_summary` | The same counts for this entity and everything below it |
| `attributes.fields` | One entry per compared field, keyed by JSON:API public name |
| `relationships.left` | The compared entity at the left version |
| `relationships.right` | The compared entity at the right version |
| `relationships.children` | The diffs of the entities this one recursed into |

The `id` is built from revision ids rather than from the identifiers the client
sent, so `?leftVersion=rel:latest-version` and `?leftVersion=id:1` produce the
same `id` when they resolve to the same revision. It is stable for a pair and
unique within the document. A side the entity is absent from leaves its segment
empty, as in `f1bc6380-bb56-4f1b-9c73-b8e3416e75b3::11`.

A response, trimmed to two of the node's four fields, two of its four children
and one of the four `included` resources:

```json
{
  "data": {
    "type": "jsonapi_diff--diff",
    "id": "85924444-4579-493c-8658-e654df08ff08:1:2",
    "links": {
      "self": {
        "href": "https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?leftVersion=rel%3Alatest-version&rightVersion=rel%3Aworking-copy"
      }
    },
    "attributes": {
      "summary": { "added": 0, "removed": 0, "changed": 1, "same": 3 },
      "tree_summary": { "added": 1, "removed": 0, "changed": 2, "same": 5 },
      "fields": {
        "uid": {
          "label": "Authored by",
          "status": "same",
          "left": "admin",
          "right": "admin",
          "ops": [{ "type": "=", "lines": ["admin"] }],
          "items": [
            {
              "delta": 0,
              "status": "same",
              "left": "admin",
              "right": "admin",
              "ops": [{ "type": "=", "lines": ["admin"] }]
            }
          ]
        },
        "field_tags": {
          "label": "Tags",
          "status": "changed",
          "left": "Drupal\nJSON:API",
          "right": "Drupal\nDecoupled",
          "ops": [
            { "type": "=", "lines": ["Drupal"] },
            { "type": "-", "lines": ["JSON:API"] },
            { "type": "+", "lines": ["Decoupled"] }
          ],
          "items": [
            {
              "delta": 0,
              "status": "same",
              "left": "Drupal",
              "right": "Drupal",
              "ops": [{ "type": "=", "lines": ["Drupal"] }]
            },
            {
              "delta": 1,
              "status": "changed",
              "left": "JSON:API",
              "right": "Decoupled",
              "ops": [
                { "type": "-", "lines": ["JSON:API"] },
                { "type": "+", "lines": ["Decoupled"] }
              ]
            }
          ]
        }
      }
    },
    "relationships": {
      "left": {
        "data": {
          "type": "node--article",
          "id": "85924444-4579-493c-8658-e654df08ff08",
          "meta": {
            "resourceVersion": "rel:latest-version",
            "drupal_internal__revision_id": 1
          }
        },
        "links": {
          "related": {
            "href": "https://example.com/jsonapi/node/article/85924444-4579-493c-8658-e654df08ff08?resourceVersion=rel%3Alatest-version"
          }
        }
      },
      "right": {
        "data": {
          "type": "node--article",
          "id": "85924444-4579-493c-8658-e654df08ff08",
          "meta": {
            "resourceVersion": "rel:working-copy",
            "drupal_internal__revision_id": 2
          }
        },
        "links": {
          "related": {
            "href": "https://example.com/jsonapi/node/article/85924444-4579-493c-8658-e654df08ff08?resourceVersion=rel%3Aworking-copy"
          }
        }
      },
      "children": {
        "data": [
          {
            "type": "jsonapi_diff--diff",
            "id": "342736e4-7a34-4460-b72c-9b861a432dc8:1:8",
            "meta": {
              "field": "field_blocks",
              "left_delta": 0,
              "right_delta": 0,
              "status": "same"
            }
          },
          {
            "type": "jsonapi_diff--diff",
            "id": "f1bc6380-bb56-4f1b-9c73-b8e3416e75b3::11",
            "meta": {
              "field": "field_blocks",
              "left_delta": null,
              "right_delta": 3,
              "status": "added"
            }
          }
        ]
      }
    }
  },
  "included": [
    {
      "type": "jsonapi_diff--diff",
      "id": "342736e4-7a34-4460-b72c-9b861a432dc8:1:8",
      "links": {
        "self": {
          "href": "https://example.com/jsonapi/diff/paragraph/block/342736e4-7a34-4460-b72c-9b861a432dc8?leftVersion=id%3A1&rightVersion=id%3A8"
        }
      },
      "attributes": {
        "summary": { "added": 0, "removed": 0, "changed": 1, "same": 0 },
        "tree_summary": { "added": 0, "removed": 0, "changed": 1, "same": 0 },
        "fields": {
          "field_body": {
            "label": "Body",
            "status": "changed",
            "left": "first block",
            "right": "first block, edited in the draft",
            "ops": [
              { "type": "-", "lines": ["first block"] },
              { "type": "+", "lines": ["first block, edited in the draft"] }
            ],
            "items": [
              {
                "delta": 0,
                "status": "changed",
                "left": "first block",
                "right": "first block, edited in the draft",
                "ops": [
                  { "type": "-", "lines": ["first block"] },
                  { "type": "+", "lines": ["first block, edited in the draft"] }
                ]
              }
            ]
          }
        }
      },
      "relationships": {
        "left": {
          "data": {
            "type": "paragraph--block",
            "id": "342736e4-7a34-4460-b72c-9b861a432dc8",
            "meta": {
              "resourceVersion": "id:1",
              "drupal_internal__revision_id": 1
            }
          },
          "links": {
            "related": {
              "href": "https://example.com/jsonapi/paragraph/block/342736e4-7a34-4460-b72c-9b861a432dc8?resourceVersion=id%3A1"
            }
          }
        },
        "right": {
          "data": {
            "type": "paragraph--block",
            "id": "342736e4-7a34-4460-b72c-9b861a432dc8",
            "meta": {
              "resourceVersion": "id:8",
              "drupal_internal__revision_id": 8
            }
          },
          "links": {
            "related": {
              "href": "https://example.com/jsonapi/paragraph/block/342736e4-7a34-4460-b72c-9b861a432dc8?resourceVersion=id%3A8"
            }
          }
        },
        "children": { "data": [] }
      }
    }
  ]
}
```

### Left and right

`left` and `right` each carry one resource identifier of the compared entity,
not of a diff. Both point at the same entity, because both sides are revisions
of it. The revision is in the identifier's `meta`: `resourceVersion` repeats the
identifier for that side, and `drupal_internal__revision_id` gives the revision
it resolved to. `links.related` is the individual JSON:API URL of that version,
so following it fetches the whole revision from core's own route.

Both versions cannot appear in `included`. A JSON:API compound document holds
each type and id pair once, and the two versions share both. The `related` links
are the honest route to the full data.

On a child that only one side has, the absent side is `"data": null` with no
links.

### The children

`children` lists the diffs of the entities the comparison recursed into. Each
identifier's `meta` says where that child sat:

| Key | Holds |
| --- | --- |
| `field` | The public name of the reference field it was found on |
| `left_delta` | Its position on the left, or `null` when the left side lacks it |
| `right_delta` | Its position on the right, or `null` when the right side lacks it |
| `status` | `same`, `moved`, `added` or `removed` |

`moved` means the same entity at a different position. Its own fields are still
reported on their own merits, so a block that was only reordered is `moved` with
every field `same`. A client that wants one badge per block combines the two.

Recursion follows Diff's own rule. A reference field is walked when its Diff
builder plugin offers up the referenced entities. In practice that means
`entity_reference_revisions` fields, so paragraphs recurse. Plain
`entity_reference` fields do not, and a term or a media item is compared as its
label on the parent instead.

### The fields

`attributes.fields` is keyed by the JSON:API public field name, so a field
aliased on its resource type is keyed by the alias, and a field JSON:API has
disabled is absent. The order is the order the comparison produced, which is
Diff's order and not the resource type's.

| Key | Holds |
| --- | --- |
| `label` | The field's human label |
| `status` | `added`, `removed`, `changed` or `same` |
| `left` | The left value as one string |
| `right` | The right value as one string |
| `ops` | Line operations from `left` to `right` |
| `items` | The same four, per item of the field |

Each entry in `ops` is `{"type": ..., "lines": [...]}`, where `type` is `=` for
carried lines, `-` for removed and `+` for added, and `lines` holds them in
order with no markup. A changed line arrives as a `-` followed by a `+`, which
is the pair a client needs to run its own word diff over the change.

### The items of a field

`items` reports the same comparison per item, so a client can narrow a change to
the item that carries it rather than highlighting the whole field. It is a JSON
array in delta order, and each entry is:

| Key | Holds |
| --- | --- |
| `delta` | The item's position in the field, on both sides |
| `status` | `added`, `removed`, `changed` or `same` |
| `left` | That item's left value |
| `right` | That item's right value |
| `ops` | Line operations from that item's `left` to its `right` |

The `ops` of an item have the same three types as a field's, and cover that item
only, so a field of two multi-line values gives each value its own operations
instead of one run over the joined text.

Every field has items, whatever its cardinality: a field of cardinality one
reports one item at delta 0. A client iterates `items` without first asking how
many values the field takes, and `entity uuid` plus public field name plus
`delta` addresses any change in the document.

A field's own `status` follows from its items. One status shared by every item
is the field's status, and a mix of statuses is `changed`. The two can never
disagree: a field reported as `same` has no item that is not, and a field
reported as `changed` has at least one item that is not `same`.

`summary` and `tree_summary` count fields, not items. A field with ten items and
one change counts as one `changed` field in both.

The two sides' items are paired by delta, which is a position and not an
identity. See the limitation below for what that cannot tell you.

### Summary and tree summary

The same four counts appear twice on a diff, under two names that answer
different questions.

| Attribute | Answers |
| --- | --- |
| `summary` | Did this entity's own fields change |
| `tree_summary` | Did this entity or anything below it change |

`attributes.summary` counts the entity's own fields by status. It counts only
what is present, so a field dropped by JSON:API or by field access is not in the
totals, and the four counts always add up to the number of entries in `fields`.
Children are not counted, because each child has a summary of its own.

`attributes.tree_summary` adds every descendant's counts to those, to any depth.
On the root it answers "did this page change", which is the question a
comparison UI asks, and on a page whose text lives in paragraphs the two
summaries differ: a draft that only edited a block leaves `summary` all `same`
and reports the edit in `tree_summary`. On a child it answers "did this block
change", counting the block and anything nested inside it.

`tree_summary` counts what is in the document and nothing else. A child the user
may not view is absent from the document, and so is a child of a resource type
JSON:API does not expose. No rollup above them counts their fields. On an entity
with no children the two attributes are equal.

Reach for `tree_summary` on a child rather than `children[].meta.status`. The
`meta` status is about the reference: whether that block was added, removed,
moved or left where it was. A block whose text was rewritten in place keeps its
id and its position, so its `meta.status` is `same` while its own
`tree_summary` reports the change.

### Asking for less

The diff type takes a JSON:API sparse fieldset, so a client that wants the
counts and nothing else asks for them:

```bash
curl -H 'Accept: application/vnd.api+json' \
  'https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?fields%5Bjsonapi_diff--diff%5D=tree_summary'
```

The fieldset names members of the diff resource, in any combination:

| Member | Holds |
| --- | --- |
| `summary` | The counts for the entity's own fields |
| `tree_summary` | The counts for the entity and everything below it |
| `fields` | Every compared field, with both sides, the line operations and the items |
| `left`, `right` | The compared entity at each version |
| `children` | The identifiers of the nested diffs |

`type` and `id` are always present, as the specification requires. A member the
fieldset leaves out is absent from the resource, and a name that is not a member
of the type is ignored, which is what core does with one on its own routes.

A fieldset belongs to a resource type rather than to a place in the document, so
it trims every diff in the response, the children in `included` as well as the
primary data. It trims members and not the tree: a fieldset without `children`
drops that relationship from each resource, and the nested diffs stay in
`included`. A response is cached per fieldset.

`?include` is ignored, because the tree is already whole. There is nothing left
to ask for.

### Caching

Every response includes the cache tags of both compared revisions and of every
entity in the tree, the tags of Diff's own configuration, and cache contexts for
`leftVersion`, `rightVersion`, the sparse fieldset, the user's permissions and
the content language. The cacheability of every access decision is included,
allowed or denied, so a `403` served to one user is not then served to a user
who is allowed.

Saving the entity again invalidates the cached diff. A different version pair is
a different cache entry, and so is a different fieldset.

## Reading the tree in a client

The document is a compound document, so the children are identifiers in the root
and the resources themselves are in `included`. A client joins them on `id`,
which is the same join any JSON:API `include` needs:

```js
// One pass to index every diff resource in the response.
const byId = new Map(
  [doc.data, ...(doc.included ?? [])].map((resource) => [resource.id, resource]),
)

// Then walk the tree from the root, parents before children.
function walk(diff, depth = 0) {
  const pad = '  '.repeat(depth)
  for (const [name, field] of Object.entries(diff.attributes.fields)) {
    if (field.status === 'same') {
      continue
    }
    // The items narrow a change to the value that carries it, so a gallery
    // with one new image highlights one image and not the whole field.
    for (const item of field.items.filter((item) => item.status !== 'same')) {
      console.log(`${pad}${name}[${item.delta}]: ${item.status}`)
    }
  }
  for (const child of diff.relationships.children.data) {
    console.log(`${pad}${child.meta.field}[${child.meta.right_delta}]: ${child.meta.status}`)
    walk(byId.get(child.id), depth + 1)
  }
}

walk(doc.data)
```

A client that only needs to know whether anything changed does not need the walk
at all. `attributes.tree_summary` on the primary data already holds the counts
for the whole tree, so a root whose `tree_summary` is all `same` has no change
anywhere in it, paragraphs included. Walk the tree when you want to show what
changed and where. Read `tree_summary` when you want the answer.

## Access

The module introduces no permission of its own. Each side is subject to the
decision core JSON:API would make for that revision on its own individual route.
That means view access to the entity, plus the site's revision view access for a
non-default revision. Content moderation's latest-version rule is part of that
decision when the module is installed.

Either side denied is a `403` for the whole diff, and no field data from either
revision reaches the client. A label-only view counts as a denial too,
since a diff of one would disclose the fields the label hides.

Per-field view access applies inside the tree. A field the user may not view is
absent from that entity's `fields` and is not counted in its `summary`. An
entity the user may not view is dropped from the document. No `tree_summary`
above it counts its fields, so the rollup never reports a change the reader
cannot be shown.

An entity that does not exist is a `404` whatever the user's access, so the
route cannot be used to learn that an entity exists.

**`bypass node access` does not grant revision operations.** Core's node access
handler skips its own bypass for the four revision operations on purpose, so a
role holding `bypass node access` and nothing else is still denied the working
copy. Add `view all revisions`, or the per-type `view <type> revisions`, to read
a non-default revision.

**Content moderation adds a rule for the working copy.** When that module is
installed, reading the latest revision of a moderated entity also needs `view
latest version`, which is the rule that governs core's own Latest version tab. A
role that can read the published side and not the draft gets a `403` on the bare
URL, not a partial document.

So the smallest role that can read the bare URL of a moderated article holds:

| Permission | Comes from |
| --- | --- |
| `access content` | Node |
| `view all revisions`, or `view <type> revisions` | Node |
| `view latest version` | Content Moderation |
| Whatever lets it see the unpublished draft, such as `view own unpublished content` | Node |

Grant them at `/admin/people/permissions`, on the role the frontend's requests
authenticate as. The route is a JSON:API route, so it uses whatever
authentication the site has already set up for JSON:API, whether that is a
session cookie, an OAuth token through Simple OAuth, or basic auth. There is no
separate credential and no separate configuration.

Granting `view all revisions` to the anonymous role makes every revision of
every node readable by anyone, through core JSON:API as much as through this
route. On a site with unpublished drafts that is usually the wrong answer. Give
it to an authenticated editor role, and let the frontend request the diff as the
editor rather than as the site.

## Discovery

With [JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia)
installed, every resource object of a revisionable entity type JSON:API exposes
carries a `diff` member in its `links`, next to core's `latest-version` and
`working-copy` links. Its `href` is the bare diff route for that entity, so a
client follows a link instead of assembling a URL out of the type, bundle and
UUID.

```json
"links": {
  "self": { "href": "https://example.com/jsonapi/node/article/85924444-4579-493c-8658-e654df08ff08" },
  "working-copy": { "href": "https://example.com/jsonapi/node/article/85924444-4579-493c-8658-e654df08ff08?resourceVersion=rel%3Aworking-copy" },
  "diff": { "href": "https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08" }
}
```

The link is present only when the user could follow it. The provider asks the
same question the route answers, through the same service: it resolves the two
default versions and checks read access to both. A link and the route it points
at cannot disagree, so an absent link is a definite answer rather than a `403`
waiting to happen.

In practice:

- A node whose newest revision is its published default revision is compared
  with itself. Anyone who may read the node gets the link, and no revision
  permission is involved.
- A node with a newer revision on top needs read access to that revision too.
  That is `view all revisions` or `view <type> revisions`, plus whatever opens
  an unpublished revision, such as owning it with `view own unpublished
  content`. Revision access on its own is not enough.
- On a site with Content Moderation, `view latest version` applies as well,
  because core's JSON:API entity access checker applies Content Moderation's
  latest version rule to a non-default revision.

The decision's cacheability is bubbled into the response, so a link's absence is
not cached across users with different access.

Exactness is not free. Each resource object costs one version resolution and two
access checks, measured at about two extra database queries and well under a
millisecond. A fifty item collection pays about a hundred queries for its fifty
links. A link that cannot lie is worth that, and a site that disagrees can leave
JSON:API Hypermedia uninstalled and keep the route.

JSON:API Hypermedia sits in `suggest` rather than in `require`, so Composer
leaves it alone unless a site asks for it. Without it the route behaves the same
and only the link is missing.

### JSON:API Hypermedia on PHP 8.4

The module's only release, 8.x-1.10 from July 2024, declares an implicitly
nullable parameter in `AccessRestrictedLink::__construct()`. PHP 8.4 deprecates
that, and Drupal's test error handler turns a site-side deprecation into a thrown
exception, so any module whose tests exercise a link provider errors against the
released version. The class is not loaded until a provider returns a link, which
is why a site can install the module and see nothing wrong until the first
provider is added.

A running site is not broken by this. A deprecation is a notice, so the
discovery link works on PHP 8.4 against the released version, which has been
confirmed against a real site. It is a test suite that fails, because Drupal
turns the same notice into an exception while testing.

The fix is merged on `8.x-1.x` and not yet in a release. See
<https://www.drupal.org/i/3526924>. So a site on PHP 8.4 can install 1.10 as it
is, and a module whose tests exercise a link provider wants the dev branch or
that patch. This module's own development build carries the patch for that
reason. A site on PHP 8.3 is unaffected, and so is a site that does not install
JSON:API Hypermedia at all.

## Which fields are compared

This is the part that surprises people, and it is Diff's decision rather than
this module's.

A field is compared when Diff has a builder plugin for it and that plugin is not
set to hidden. Absent an explicit setting at
`/admin/config/content/diff/fields`, Diff decides by asking whether the field
appears in any of the entity type's view displays. **A field hidden from every
view display is treated as hidden by Diff, so it is never compared and this
module never sees it.** It is absent from `fields` rather than reported as
`same`.

That is easy to hit on a decoupled site, where view displays are often left
empty because nothing renders them. If a field belongs in the diff, either put it
in a view display or give it an explicit plugin in Diff's field settings. The
second is the better answer for a decoupled site, because it states the intent
instead of relying on a side effect.

Diff also never compares an entity type's bundle field, its revision field,
`revision_log` or `revision_uid`, and never compares a field that is not
revisionable.

A field drops out of the document for one of these reasons:

| Cause | Where to fix it |
| --- | --- |
| The field is not revisionable | Nothing to fix. There is no second value to compare |
| Diff has no plugin for it, or it is set to hidden | Diff's field settings |
| It is in no view display and has no explicit Diff plugin | A view display, or Diff's field settings |
| JSON:API does not expose it on that resource type | JSON:API Extras field settings, if that module is installed |
| The user may not view it | The role's permissions |

## Limitations

This module reports the cases below less precisely than a reader might expect.
Every one has a workaround, and none of them is a defect in the code.

### Children are matched by entity id

Diff aligns referenced entities by entity id, and so does this module. A draft
holding new revisions of the same paragraphs compares cleanly. A workflow that
creates brand new paragraph entities for each draft reports every block as
`removed` and every replacement as `added`, because to the comparison they are
different entities that happen to hold similar text.

Drupal's own paragraphs UI does the right thing here: editing a node creates new
revisions of the existing paragraphs and the ids survive. The problem shows up in
custom code and in import flows that rebuild the field from scratch.

**What to do:** keep the entity ids across drafts. Load the existing paragraph
and save a new revision of it rather than creating a replacement. Positional
alignment, for the cases where that is impossible, is a candidate for a later
release.

### A field's items are matched by position

`items` pairs the two sides by delta, because a delta is all a field item has.
Unlike a referenced entity, an item carries no id that survives a save, so there
is nothing else to match it on.

That reports an edit in place exactly: a gallery of six images with the fourth
swapped is five `same` items and one `changed` item. It reports an insertion
less well. An item added at the front shifts every item after it, so each
position now holds the item that used to sit before it, and the field reads as a
run of `changed` items with one `added` at the end. Nothing was lost, but the
report says "these positions changed" where a reader wanted "one item was
inserted here".

Appending and truncating are unaffected, because neither shifts an existing
position.

**What to do:** for content where insertion order matters and the items are
substantial, model the items as referenced entities. Those are matched by entity
id under `children`, which survives reordering, and each one then reports
`added`, `removed` or `moved` on its own.

### A recursed reference field has no entry of its own

When a reference field recurses, its children appear under `children` and the
field itself never appears in `fields`. There is no status for `field_blocks` as
a field, so a client cannot ask "did the block list change" in one read. It
derives that from the children's statuses instead, and from the field name each
child's `meta` carries.

**What to do:** treat `children` as that field's report. A field whose children
are all `same` did not change, and any `added`, `removed` or `moved` child means
it did.

## What it deliberately does not do

- **No markup of its own.** `ops` holds plain lines. There is no `<ins>` or
  `<del>`, no CSS classes and no rendered table, because the frontend owns the
  theme. Do note that `left` and `right` are the strings Diff produced, and a few
  field types render through their formatter, so core's `created` field arrives
  as a `<time>` element, which comes from the field's own formatter.
- **No character-level or word-level operations.** Lines are as fine as it goes.
  A changed line comes through as a `-` and a `+` over the same region, which is
  the pair a client needs to run its own word diff in whichever library it
  already has.
- **No render coordinates.** The document says which field and which delta
  changed. It does not say where that sits on a rendered page, because only the
  frontend knows how it laid the page out.
- **No write operations.** The route is `GET` only. Nothing here reverts a revision,
  publishes a draft or edits a field. Core's own routes do that.
- **No way to ask for a smaller tree.** A sparse fieldset trims each resource's
  members, and that is the whole of it. There is no `include` filtering, no
  pagination, and no way to leave out the fields whose status is `same`, so
  every entity the comparison reached comes back every time.

## For module maintainers

**There are no hooks.** The module defines no hook and no event, so there is no
`jsonapi_diff.api.php`. A field appears in the diff because Diff compares it and
JSON:API exposes it, which means the extension points are Diff's builder plugins
and JSON:API's resource types rather than anything here. Adding a field type to
the comparison is a Diff builder plugin. Renaming or hiding a field in the
output is a JSON:API resource type change.

**It reads core JSON:API internals.** Every class in core's `jsonapi` module is
marked `@internal`, and this module uses three of its services: the version
negotiator, the entity access checker and the resource type repository. JSON:API
Resources builds on the same internals, so the exposure is not new, but it is
real: a core change to any of the three can break a release. The mitigation is
functional tests that assert the statuses and the document shape over real HTTP,
so a core change shows up as a red pipeline rather than as a quiet change in
output.

**The document shape is alpha.** The tests pin the resource type name, the `id`
format, the four field statuses, the four child statuses and the `ops` operation
types, so none of those moves without a red pipeline here first. Treat `meta` as
open for additional keys.

## FAQ

**Q: Why does an unchanged field carry its full text on both sides?**

**A:** Because the client this was built for needs the text anyway to render the
page, so sending it once with the diff saves a second request. It does make a
large tree a large payload. A client that does not need the text asks for
`fields[jsonapi_diff--diff]=tree_summary,children` and walks the tree on the
counts, then fetches the one diff it wants to show in full. A fieldset cannot
keep `fields` and drop the entries whose status is `same`.

**Q: How do I tell whether a page changed at all?**

**A:** Read `attributes.tree_summary` on the primary data. It counts the node's
own fields and every paragraph below it, so one set of counts answers the
question. The `attributes.summary` beside it counts the node's own fields alone,
which on a page built from paragraphs is usually not what a comparison UI is
asking.

**Q: Can I diff a paragraph on its own?**

**A:** Yes. The route takes any revisionable entity type JSON:API exposes, so
`/jsonapi/diff/paragraph/block/{uuid}` works. Each `included` resource's `self`
link is that URL for that child, with the two revision ids already filled in.

**Q: Why does a date field's value hold a `<time>` element?**

**A:** Diff builds each side's string with the field's own plugin, and core's
date fields render through a formatter that emits `<time>`. The module passes the
string through rather than stripping tags, because stripping would be guessing at
what the field meant. Handle `left` and `right` as opaque strings per field type,
or set a different Diff plugin for the field.

**Q: How do I show a word-level highlight?**

**A:** Take a `-` and the `+` that follows it, and run a word diff over the two
line sets in the frontend. Every diff library does this, and doing it in the
client means the highlighting follows the client's own word boundaries rather
than Drupal's.

**Q: What happens to translations?**

**A:** The comparison uses the current content language, the same as core
JSON:API. A request on a language prefix reads that language's values on both
sides, and the language is one of the response's cache contexts. One language per
request, never two languages against each other.

**Q: Is there an entity behind `jsonapi_diff--diff`?**

**A:** No. `jsonapi_diff--diff` is a resource type the module declares for the
document's shape. Nothing stores a diff, there is no individual route for the
type name, and nothing is ever written back to it. Follow the `self` link on a
diff resource to fetch it again, and a `left` or `right` `related` link to reach
real entity data.

**Q: Why is there no `included` member on some responses?**

**A:** Because the compared entity recursed into nothing. An entity with no
`entity_reference_revisions` field, or one whose reference fields are empty on
both sides, has an empty `children` relationship and no `included` member at all.
Treat the member as optional rather than expecting an empty array.

**Q: Why did I get a `400` when the identifier looks fine?**

**A:** Two causes, and the error message tells them apart. A version identifier
that is not `id:<number>`, `rel:latest-version` or `rel:working-copy` is rejected
by this module. A query parameter name that is entirely lowercase is rejected by
core before the route runs, because the specification reserves those names. See
"The capital letter in `leftVersion`" above.

**Q: Does this add any configuration?**

**A:** No. Diff's field settings decide what is compared and how, and JSON:API's
resource type settings decide what is exposed. There is no third place to look,
which is the point.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
