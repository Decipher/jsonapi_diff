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
- Recommended modules
- Installation
- Configuration
- The endpoint
- The document
- Access
- Discovery
- Limitations
- For module maintainers
- FAQ
- Maintainers

## Requirements

- PHP 8.3 or later
- Drupal 10.5 or 11
- JSON:API (Drupal core)
- [Diff](https://www.drupal.org/project/diff)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Recommended modules

[JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia) adds
the discovery link described under Discovery. It is optional, and the route
works without it.

On PHP 8.4, its only release (8.x-1.10) declares an implicitly nullable
parameter that Drupal's test error handler turns into a thrown exception. A
running site is unaffected, because a deprecation is only a notice, but a module
whose tests exercise a link provider needs the dev branch or the patch from
<https://www.drupal.org/i/3526924>. This module's development build carries it.

## Installation

Install with Composer, then enable the module:

```bash
composer require drupal/jsonapi_diff
drush en jsonapi_diff
```

See [installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for the standard instructions.

## Configuration

This module has no configuration of its own. What is compared, and how, is
Diff's configuration at `/admin/config/content/diff/fields`. What is exposed is
JSON:API's resource type configuration. There is no third place to look, which
is the point.

One Diff default surprises most people once. A field is compared when Diff has a
builder plugin for it and that plugin is not hidden. Absent an explicit setting,
Diff decides by asking whether the field appears in **any** of the entity type's
view displays. **A field hidden from every view display is never compared**, and
it is absent from `fields` rather than reported as `same`.

That is easy to hit on a decoupled site, where view displays are often left
empty because nothing renders them. Either put the field in a view display, or
give it an explicit plugin in Diff's field settings. The second is the better
answer for a decoupled site, because it states the intent instead of relying on
a side effect.

Diff never compares an entity type's bundle field, its revision field,
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

## The endpoint

One route, read-only:

```
GET /jsonapi/diff/{entity_type}/{bundle}/{uuid}
```

Drupal's vocabulary for this is unavoidable, so in short:

| Term | Means |
| --- | --- |
| Revision | One saved state of an entity. Saving again adds a revision rather than overwriting the last |
| Default revision | The revision Drupal serves when nobody asks for a particular one. On a plain site that is the published one |
| Working copy | The newest revision of all, published or not. A draft saved over a published page is the working copy while the published revision stays the default |

A query parameter names each side. Each holds a core JSON:API resource version
identifier, the same grammar `resourceVersion` takes on the individual route,
resolved by the same negotiator.

| Parameter | Default | Accepts |
| --- | --- | --- |
| `leftVersion` | `rel:latest-version` | `id:<revision id>`, `rel:latest-version`, `rel:working-copy` |
| `rightVersion` | `rel:working-copy` | The same three forms |

So the bare URL, with no query parameters, compares the published revision
against the working copy. An explicit pair is two identifiers:

```bash
curl -H 'Accept: application/vnd.api+json' \
  'https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?leftVersion=id:12&rightVersion=id:15'
```

Nothing forces `left` to be the older side, but the defaults and the names read
that way: `left` is where the content came from and `right` is where it went, so
a `+` in `ops` is an addition on the right.

A revision id for the `id:` form comes from `attributes.drupal_internal__vid` on
a node fetched from core's individual route, or from
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

Comparing a revision with itself is allowed. Every field comes back `same`.

### The capital letter in `leftVersion`

Core validates query parameter names on every JSON:API route, this one included,
and requires a custom name to hold at least one character outside `a-z`.
All-lowercase names are reserved for the specification itself. So `?left=` and
`?right=` are refused with a `400` before the route even runs, which is the same
reason core's own parameter is `resourceVersion` and not `version`.

Member names inside the document have no such rule, and they are `left` and
`right`. The two spellings are deliberate: `leftVersion` in the query, `left` in
the document.

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
same `id` when they resolve to the same revision. A side the entity is absent
from leaves its segment empty, as in `f1bc6380-bb56-4f1b-9c73-b8e3416e75b3::11`.

A response, cut to one field, one child and one `included` resource:

```json
{
  "data": {
    "type": "jsonapi_diff--diff",
    "id": "85924444-4579-493c-8658-e654df08ff08:1:2",
    "attributes": {
      "summary": { "added": 0, "removed": 0, "changed": 1, "same": 3 },
      "tree_summary": { "added": 1, "removed": 0, "changed": 2, "same": 5 },
      "fields": {
        "title": {
          "label": "Title",
          "status": "changed",
          "left": "Autumn in the alpine parks",
          "right": "Autumn in the alpine national parks",
          "ops": [
            { "type": "-", "lines": ["Autumn in the alpine parks"] },
            { "type": "+", "lines": ["Autumn in the alpine national parks"] }
          ],
          "items": [
            {
              "delta": 0,
              "status": "changed",
              "left": "Autumn in the alpine parks",
              "right": "Autumn in the alpine national parks",
              "ops": [
                { "type": "-", "lines": ["Autumn in the alpine parks"] },
                { "type": "+", "lines": ["Autumn in the alpine national parks"] }
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
      "children": {
        "data": [
          {
            "type": "jsonapi_diff--diff",
            "id": "342736e4-7a34-4460-b72c-9b861a432dc8:1:8",
            "meta": {
              "field": "field_blocks",
              "left_delta": 0,
              "right_delta": 0,
              "status": "same",
              "match": "id"
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
        "summary": { "added": 0, "removed": 0, "changed": 0, "same": 2 },
        "tree_summary": { "added": 0, "removed": 0, "changed": 0, "same": 2 },
        "fields": {}
      }
    }
  ]
}
```

`right` has the same shape as `left`, and the members left out above follow the
same patterns. An entity with nothing of its own to report has `"fields": {}`, an
empty JSON object rather than an empty array. A response whose comparison
recursed into nothing carries no `included` member at all, so treat it as
optional rather than expecting an empty array.

### Left and right

`left` and `right` each carry one resource identifier of the compared entity,
not of a diff. They usually point at the same entity, because both sides are
revisions of it. The revision is in the identifier's `meta`: `resourceVersion`
repeats the identifier for that side, and `drupal_internal__revision_id` gives
the revision it resolved to. `links.related` is the individual JSON:API URL of
that version, so following it fetches the whole revision from core's own route.

Both versions cannot appear in `included`. A JSON:API compound document holds
each type and id pair once, and the two versions share both. The `related` links
are the honest route to the full data.

On a child that only one side has, the absent side is `"data": null` with no
links. On a child whose `meta.match` is `position`, the two identifiers name two
different entities, and that resource carries no `self` link: the route compares
revisions of one entity and has no URL that restates a pair of two.

### The children

`children` lists the diffs of the entities the comparison recursed into. Each
identifier's `meta` says where that child sat:

| Key | Holds |
| --- | --- |
| `field` | The public name of the reference field it was found on |
| `left_delta` | Its position on the left, or `null` when the left side lacks it |
| `right_delta` | Its position on the right, or `null` when the right side lacks it |
| `status` | `same`, `moved`, `added` or `removed` |
| `match` | `id`, `position` or `none`, how the two sides were brought together |

`status` is about the reference, not the content. `moved` is the same entity at a
different position, and its own fields are still reported on their own merits, so
a block that was only reordered is `moved` with every field `same`.

`match` says how much to trust the pair. `id` is the same entity on both sides
and is exact. `position` is two different entities paired because they hold the
same place in the same field, and it is a guess: see the limitation below for
what guards it. `none` is a child only one side has, and always sits beside
`added` or `removed`. A client that will not act on a guess filters on
`match === 'id'`.

Recursion follows Diff's own rule. A reference field is walked when its Diff
builder plugin offers up the referenced entities. In practice that means
`entity_reference_revisions` fields, so paragraphs recurse. Plain
`entity_reference` fields do not, and a term or a media item is compared as its
label on the parent instead.

### The fields

`attributes.fields` is keyed by the JSON:API public field name, so an aliased
field is keyed by its alias and a disabled field is absent. The order is Diff's,
not the resource type's.

| Key | Holds |
| --- | --- |
| `label` | The field's human label |
| `status` | `added`, `removed`, `changed` or `same` |
| `left` | The left value as one string |
| `right` | The right value as one string |
| `ops` | Line operations from `left` to `right` |
| `items` | The same four keys again, per item of the field, each with its `delta` |

Each entry in `ops` is `{"type": ..., "lines": [...]}`, where `type` is `=` for
carried lines, `-` for removed and `+` for added, and `lines` holds them in order
with no markup. A changed line arrives as a `-` followed by a `+`, which is the
pair a client needs to run its own word diff over the change.

`items` reports the same comparison per item, so a client can narrow a change to
the value that carries it rather than highlighting the whole field. Every field
has items whatever its cardinality, and a field of cardinality one reports one
item at delta 0. So a client iterates `items` without first asking how many
values a field takes, and entity UUID plus public field name plus `delta`
addresses any change in the document.

A field's own `status` follows from its items: one status shared by every item is
the field's status, and a mix is `changed`. The two can never disagree. The two
sides' items are paired by delta, which is a position and not an identity, and
the limitation below says what that cannot tell you.

### Summary and tree summary

The same four counts appear twice, under two names that answer different
questions.

| Attribute | Answers |
| --- | --- |
| `summary` | Did this entity's own fields change |
| `tree_summary` | Did this entity or anything below it change |

Both count fields, never items, so a field with ten values and one change counts
as one `changed`. `summary` counts only what is present, so a field dropped by
JSON:API or by field access is not in the totals, and the four counts always add
up to the number of entries in `fields`.

`tree_summary` adds every descendant's counts, to any depth. On the root it
answers "did this page change", which is the question a comparison UI asks. On a
page whose text lives in paragraphs the two differ: a draft that only edited a
block leaves `summary` all `same` and reports the edit in `tree_summary`. It
counts what is in the document and nothing else, so a child the user may not view
is counted nowhere above it.

Reach for `tree_summary` on a child rather than `children[].meta.status`, because
a block whose text was rewritten in place keeps its id and its position. Its
`meta.status` is `same` while its own `tree_summary` reports the change.

A client that only needs to know whether anything changed never has to walk the
tree. `tree_summary` on the primary data already holds the counts for all of it.

### Asking for less

The diff type takes a JSON:API sparse fieldset, so a client that wants the counts
and nothing else asks for them:

```bash
curl -H 'Accept: application/vnd.api+json' \
  'https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?fields%5Bjsonapi_diff--diff%5D=tree_summary'
```

The fieldset names any combination of `summary`, `tree_summary`, `fields`,
`left`, `right` and `children`. `type` and `id` are always present, as the
specification requires, and a name that is not a member of the type is ignored.

A fieldset belongs to a resource type rather than to a place in the document, so
it trims every diff in the response, the children in `included` as well as the
primary data. It trims members and not the tree: a fieldset without `children`
drops that relationship from each resource, and the nested diffs stay in
`included`.

`?include` is ignored, because the tree is already whole.

### Caching

Every response includes the cache tags of both compared revisions and of every
entity in the tree, the tags of Diff's own configuration, and cache contexts for
`leftVersion`, `rightVersion`, the sparse fieldset, the user's permissions and
the content language. The cacheability of every access decision is included,
allowed or denied, so a `403` served to one user is not then served to a user who
is allowed.

Saving the entity again invalidates the cached diff. A different version pair is
a different cache entry, and so is a different fieldset.

## Access

The module introduces no permission of its own. Each side is subject to the
decision core JSON:API would make for that revision on its own individual route:
view access to the entity, plus the site's revision view access for a non-default
revision.

Either side denied is a `403` for the whole diff, and no field data from either
revision reaches the client. A label-only view counts as a denial too, since a
diff of one would disclose the fields the label hides. An entity that does not
exist is a `404` whatever the user's access, so the route cannot be used to learn
that an entity exists.

Per-field view access applies inside the tree. A field the user may not view is
absent from that entity's `fields` and is not counted in its `summary`. An entity
the user may not view is dropped from the document entirely, and no
`tree_summary` above it counts its fields, so the rollup never reports a change
the reader cannot be shown.

Two rules catch people out:

- **`bypass node access` does not grant revision operations.** Core's node access
  handler skips its own bypass for the four revision operations on purpose, so a
  role holding `bypass node access` and nothing else is still denied the working
  copy.
- **Content moderation adds a rule for the working copy.** When that module is
  installed, reading the latest revision of a moderated entity also needs `view
  latest version`. A role that can read the published side and not the draft gets
  a `403` on the bare URL, not a partial document.

So the smallest role that can read the bare URL of a moderated article holds:

| Permission | Comes from |
| --- | --- |
| `access content` | Node |
| `view all revisions`, or `view <type> revisions` | Node |
| `view latest version` | Content Moderation |
| Whatever lets it see the unpublished draft, such as `view own unpublished content` | Node |

The route is a JSON:API route, so it uses whatever authentication the site has
already set up for JSON:API, whether that is a session cookie, an OAuth token or
basic auth. There is no separate credential and no separate configuration.

Granting `view all revisions` to the anonymous role makes every revision of every
node readable by anyone, through core JSON:API as much as through this route. On
a site with unpublished drafts that is usually the wrong answer. Give it to an
authenticated editor role, and let the frontend request the diff as the editor
rather than as the site.

## Discovery

With [JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia)
installed, every resource object of a revisionable entity type JSON:API exposes
carries a `diff` member in its `links`, next to core's `latest-version` and
`working-copy` links. Its `href` is the bare diff route for that entity, so a
client follows a link instead of assembling a URL out of the type, bundle and
UUID.

The link is present only when the user could follow it. The provider asks the
same question the route answers, through the same service: it resolves the two
default versions and checks read access to both. A link and the route it points
at cannot disagree, so an absent link is a definite answer rather than a `403`
waiting to happen. The decision's cacheability is bubbled into the response, so a
link's absence is not cached across users with different access.

Exactness is not free. Each resource object costs one version resolution and two
access checks, measured at about two extra database queries and well under a
millisecond, so a fifty item collection pays about a hundred queries for its
fifty links. A link that cannot lie is worth that, and a site that disagrees can
leave JSON:API Hypermedia uninstalled and keep the route.

It sits in `suggest` rather than in `require`, so Composer leaves it alone unless
a site asks for it.

## Limitations

This module reports the cases below less precisely than a reader might expect.
Every one has a workaround, and none of them is a defect in the code.

### Blocks with no id in common are paired by position

Children are matched by entity id first. A draft holding new revisions of the
same paragraphs, which is what Drupal's own paragraphs UI produces, matches
exactly and reports `match: id`.

A workflow that creates brand new paragraph entities for each draft leaves that
pass with nothing to match. Those children are then paired by the position they
hold, and each such pair reports `match: position`. That is a guess, and it is
guarded three ways: both sides must sit at the same delta of the same reference
field, must be the same entity type and bundle, and must share at least half
their words, measured as a Dice coefficient over the fields the document reports
for both of them. Below that, the two are left as an honest `removed` and
`added`.

The word guard needs words to weigh. Two blocks that hold no text of their own,
which a container paragraph whose every field recurses does, are decided by the
delta and the bundle alone. The blocks inside the container are then matched on
their own merits.

A pair sits at one position on both sides, so its `status` is always `same`. What
changed is inside it, in that child's own `fields` and `tree_summary`.

It reads a page that kept its shape, and does not recover a page that did not:

- **An insertion or a deletion shifts everything after it.** Each new pairing is
  then a block against the one beside it, which the word guard usually rejects,
  so the run falls back to `removed` and `added`.
- **A block rewritten from scratch is not a pair.** No threshold can both accept
  a rewrite and reject an unrelated block.
- **Reordering is invisible.** Only equal positions are paired, so two replaced
  blocks that swapped places are four children, not two `moved` ones.
- **A guess is still a guess.** Two blocks of one bundle that happen to share
  their words will be paired, and `match: position` is the only warning.
- **Access decides first.** A child the reader may not view leaves the tree
  before the pairing runs, so it is never paired and never reported.

**What to do:** keep the entity ids across drafts where you can. Load the
existing paragraph and save a new revision of it rather than creating a
replacement. Positional alignment is there for the flows where that is not
possible, not as a substitute for them.

### A field's items are matched by position

`items` pairs the two sides by delta, because a delta is all a field item has.
Unlike a referenced entity, an item carries no id that survives a save.

That reports an edit in place exactly: a gallery of six images with the fourth
swapped is five `same` items and one `changed`. It reports an insertion less
well. An item added at the front shifts every item after it, so the field reads
as a run of `changed` items with one `added` at the end. Nothing is lost, but the
report says "these positions changed" where a reader wanted "one item was
inserted here". Appending and truncating are unaffected.

**What to do:** where insertion order matters and the items are substantial,
model them as referenced entities. Those are matched by entity id under
`children`, which survives reordering.

### A recursed reference field has no entry of its own

When a reference field recurses, its children appear under `children` and the
field itself never appears in `fields`. There is no status for `field_blocks` as
a field, so a client cannot ask "did the block list change" in one read.

**What to do:** treat `children` as that field's report. A field whose children
are all `same` did not change, and any `added`, `removed` or `moved` child means
it did.

### What it deliberately does not do

- **No markup of its own.** `ops` holds plain lines, with no `<ins>`, no CSS
  classes and no rendered table, because the frontend owns the theme. Note that
  `left` and `right` are the strings Diff produced, and a few field types render
  through their formatter, so core's `created` field arrives as a `<time>`
  element.
- **No word-level operations.** Lines are as fine as it goes. A changed line
  comes through as a `-` and a `+` over the same region, which is the pair a
  client needs to run its own word diff in whichever library it already has.
- **No render coordinates.** The document says which field and which delta
  changed, not where that sits on a rendered page.
- **No write operations.** The route is `GET` only. Core's own routes revert,
  publish and edit.
- **No way to ask for a smaller tree.** A sparse fieldset trims each resource's
  members and that is the whole of it. There is no `include` filtering, no
  pagination, and no way to leave out the fields whose status is `same`.

## For module maintainers

**There are no hooks.** The module defines no hook and no event, so there is no
`jsonapi_diff.api.php`. A field appears in the diff because Diff compares it and
JSON:API exposes it, so the extension points are Diff's builder plugins and
JSON:API's resource types rather than anything here.

**It reads core JSON:API internals.** Every class in core's `jsonapi` module is
marked `@internal`, and this module uses three of its services: the version
negotiator, the entity access checker and the resource type repository. JSON:API
Resources builds on the same internals, so the exposure is not new, but it is
real. The mitigation is functional tests that assert the statuses and the
document shape over real HTTP, so a core change shows up as a red pipeline rather
than as a quiet change in output.

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
counts, then fetches the one diff it wants to show in full.

**Q: Why does a date field's value hold a `<time>` element?**

**A:** Diff builds each side's string with the field's own plugin, and core's
date fields render through a formatter that emits `<time>`. The module passes the
string through rather than stripping tags, because stripping would be guessing at
what the field meant. Handle `left` and `right` as opaque strings per field type,
or set a different Diff plugin for the field.

**Q: What happens to translations?**

**A:** The comparison uses the current content language, the same as core
JSON:API. A request on a language prefix reads that language's values on both
sides, and the language is one of the response's cache contexts. One language per
request, never two languages against each other.

**Q: Is there an entity behind `jsonapi_diff--diff`?**

**A:** No. It is a resource type the module declares for the document's shape.
Nothing stores a diff, there is no individual route for the type name, and
nothing is ever written back to it. Follow the `self` link on a diff resource to
fetch it again, and a `left` or `right` `related` link to reach real entity data.

**Q: Can I diff a paragraph on its own?**

**A:** Yes. The route takes any revisionable entity type JSON:API exposes, so
`/jsonapi/diff/paragraph/block/{uuid}` works. Each `included` resource's `self`
link is that URL for that child, with the two revision ids already filled in.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
