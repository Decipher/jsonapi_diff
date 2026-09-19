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
      "fields": {
        "uid": {
          "label": "Authored by",
          "status": "same",
          "left": "admin",
          "right": "admin",
          "ops": [{ "type": "=", "lines": ["admin"] }]
        },
        "title": {
          "label": "Title",
          "status": "changed",
          "left": "Diff demo article",
          "right": "Diff demo article (draft)",
          "ops": [
            { "type": "-", "lines": ["Diff demo article"] },
            { "type": "+", "lines": ["Diff demo article (draft)"] }
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
        "fields": {
          "field_body": {
            "label": "Body",
            "status": "changed",
            "left": "first block",
            "right": "first block, edited in the draft",
            "ops": [
              { "type": "-", "lines": ["first block"] },
              { "type": "+", "lines": ["first block, edited in the draft"] }
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

Each entry in `ops` is `{"type": ..., "lines": [...]}`, where `type` is `=` for
carried lines, `-` for removed and `+` for added, and `lines` holds them in
order with no markup. A changed line arrives as a `-` followed by a `+`, which
is the pair a client needs to run its own word diff over the change.

`attributes.summary` counts the entity's own fields by status. It counts only
what is present, so a field dropped by JSON:API or by field access is not in the
totals, and the four counts always add up to the number of entries in `fields`.
Children are not counted, because each child has a summary of its own.

### Caching

Every response includes the cache tags of both compared revisions and of every
entity in the tree, the tags of Diff's own configuration, and cache contexts for
`leftVersion`, `rightVersion`, the user's permissions and the content language.
The cacheability of every access decision is included, allowed or denied, so a
`403` served to one user is not then served to a user who is allowed.

Saving the entity again invalidates the cached diff. Two different version pairs
are cached separately.

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
    if (field.status !== 'same') {
      console.log(`${pad}${name}: ${field.status}`)
    }
  }
  for (const child of diff.relationships.children.data) {
    console.log(`${pad}${child.meta.field}[${child.meta.right_delta}]: ${child.meta.status}`)
    walk(byId.get(child.id), depth + 1)
  }
}

walk(doc.data)
```

`attributes.summary` answers "did anything change here" without the walk, but it
counts one entity's own fields and not its children, so a root whose summary is
all `same` can still hold a changed paragraph. Walk the tree, or sum the
summaries of everything in `included` as well.

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
absent from that entity's `fields` and is not counted in its `summary`.

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

The link is present only when the user could follow it, which is `view` on the
entity plus `view all revisions`. A user without revision access sees no `diff`
member, and the decision's cacheability is bubbled into the response, so that
absence is not cached across users with different access.

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

The fix is merged on `8.x-1.x` and not yet in a release. See
<https://www.drupal.org/i/3526924>. Until one carries it, a site on PHP 8.4 needs
the dev branch or that patch applied to 1.10. A site on PHP 8.3 is unaffected,
and so is a site that does not install JSON:API Hypermedia at all.

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

### A multi-value field has one status for the whole field

Diff joins a field's values into one string, one value per line, before
comparing. This module reports one status, one `left`, one `right` and one `ops`
list for the field as a whole. So a gallery of six images with one image swapped
is one `changed` field, not five `same` items beside one `changed` item.

`ops` still shows which line moved, so a client can work out which value changed
by matching lines back to deltas. That holds when a value renders as one line,
which most do, and it breaks down when a value renders as several.

**What to do:** read `ops` rather than the field status when per-value detail
matters, and read `changed` on a multi-value field as "something in here
changed".

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
- **No way to ask for less.** Sparse fieldset support, `include` filtering and
  pagination are all absent, so the whole tree comes back every time, with the
  full text of unchanged fields in it.

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
open for additional keys, and expect sparse fieldset support before 1.0, which
will stop the whole tree coming back every time.

## FAQ

**Q: Why does an unchanged field carry its full text on both sides?**

**A:** Because the client this was built for needs the text anyway to render the
page, so sending it once with the diff saves a second request. It does make a
large tree a large payload. Trimming it belongs in a later release, as a sparse
fieldset, which is the JSON:API way to ask for less.

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
