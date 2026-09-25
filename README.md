# JSON:API Diff

[![Pipeline](https://git.drupalcode.org/project/jsonapi_diff/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/jsonapi_diff/-/pipelines)
[![Test](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x)

Exposes the difference between two revisions of an entity as a JSON:API
document.

Core JSON:API serves any revision of an entity, so a decoupled editor can switch
a page between its published version and a draft. It cannot say what changed.
The [Diff](https://www.drupal.org/project/diff) module works that out, field by
field and down through paragraphs, and renders it as admin-theme HTML. This
module puts the same comparison on a JSON:API route, as data a frontend renders
itself.

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
- Limitations
- FAQ
- Maintainers

## Requirements

- PHP 8.3 or later
- Drupal 10.5 or 11
- JSON:API (Drupal core)
- [Diff](https://www.drupal.org/project/diff)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Recommended modules

[JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia) adds a
`diff` link to every revisionable resource object, so a client follows a link
instead of building the URL. The route works without it.

On PHP 8.4 its 8.x-1.10 release triggers a deprecation that Drupal's test error
handler turns into an exception. A running site is unaffected. Use the dev branch
or the patch from [#3526924](https://www.drupal.org/i/3526924) if your own tests
exercise a link provider.

## Installation

Install with Composer, then enable the module:

```bash
composer require drupal/jsonapi_diff
drush en jsonapi_diff
```

See [installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for the standard instructions.

## Configuration

None. Diff's field settings at `/admin/config/content/diff/fields` decide what is
compared and how. JSON:API's resource type settings decide what is exposed. This
module reads both and adds nothing of its own.

If a field is missing from a diff, it is one of those two that left it out. See
the FAQ.

## The endpoint

One route, read-only:

```
GET /jsonapi/diff/{entity_type}/{bundle}/{uuid}
```

A query parameter names each side, in core JSON:API's resource version grammar
and resolved by core's negotiator.

| Parameter | Default | Accepts |
| --- | --- | --- |
| `leftVersion` | `rel:latest-version` | `id:<revision id>`, `rel:latest-version`, `rel:working-copy` |
| `rightVersion` | `rel:working-copy` | The same three forms |

`rel:latest-version` is the newest default revision, which on an ordinary site is
the published one. `rel:working-copy` is the newest revision of all. So the bare
URL compares the published page against its draft:

```bash
curl -H 'Accept: application/vnd.api+json' \
  'https://example.com/jsonapi/diff/node/article/85924444-4579-493c-8658-e654df08ff08?leftVersion=id:12&rightVersion=id:15'
```

`left` is where the content came from and `right` is where it went, so a `+` in
`ops` is an addition on the right. Comparing a revision with itself is allowed,
and every field comes back `same`. The comparison uses the current content
language, the same as core JSON:API, one language per request.

The parameter names include a capital letter because core rejects an
all-lowercase custom query parameter on every JSON:API route, and core's own
parameter is `resourceVersion` for the same reason. Inside the document the
members are `left` and `right`.

Error statuses match core JSON:API on its own individual route.

| Situation | Status |
| --- | --- |
| An identifier outside the grammar, such as `leftVersion=12` | `400` |
| An all-lowercase parameter name, such as `left=id:12` | `400` |
| A revision id belonging to another entity | `404` |
| An unknown UUID, a UUID of another bundle, or an entity type without revisions | `404` |
| Either side denied | `403` |

## The document

Primary data is the diff of the addressed entity. Every entity the comparison
recursed into gets a resource of the same type in `included`, to any depth, so
one request returns the whole tree.

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

Each side's identifier holds the resolved version and revision id in `meta`, and
its `links.related` is the individual JSON:API URL of that version. The `id` uses
revision ids rather than the identifiers the client sent, so two requests
resolving to the same pair share an `id`, and a side the entity is absent from
leaves its segment empty.

### Each field entry

| Key | Holds |
| --- | --- |
| `label` | The field's human label |
| `status` | `added`, `removed`, `changed` or `same` |
| `left`, `right` | That side's value as one string |
| `ops` | Line operations, each `{type, lines}` where `type` is `=`, `-` or `+` |
| `items` | The same keys again per item of the field, each with its `delta` |

A changed line arrives as a `-` followed by a `+`, which is the pair a client
needs to run its own word diff. A field of cardinality one reports one item at
delta 0, and a field's `status` follows from its items: the status they share, or
`changed` when they differ.

A reference field the comparison recursed into is the exception. Its entry answers
whether the list changed, and its values, operations and items are all empty. The
`status` describes the list alone: `changed` when a child was added, removed or
moved, and `same` when every child stayed where it was. An edit inside a child
leaves the list `same` and reaches the counts through that child, so nothing
counts twice.

The module does not add markup. Diff builds each side with the field's own
plugin, so a formatted text field arrives with its HTML and a date field arrives
as a `<time>` element. Escape every line before rendering it as text.

### Each child identifier

| Key | Holds |
| --- | --- |
| `field` | The public name of the reference field |
| `left_delta`, `right_delta` | Its position on that side, or `null` when absent |
| `status` | `same`, `moved`, `added` or `removed` |
| `match` | `id`, `position` or `none` |

`status` describes the reference, not the content, so a block edited in place
reports `same` while its own `tree_summary` reports the change. Read both.

`match` is `id` for the same entity on both sides, which is exact, and `position`
for two different entities paired at one delta whose text agreed, which is an
inference. Filter on `match === 'id'` to act on exact matches only.

Recursion follows Diff's rule, so `entity_reference_revisions` fields recurse and
paragraphs appear as children. A plain `entity_reference` field does not, and its
target is compared as a label on the parent.

### Summaries, fieldsets and caching

Both summaries count fields, never items. `summary` covers the entity's own
fields and its four counts total the entries in `fields`. `tree_summary` adds
every descendant's counts, so on a page built from paragraphs the two differ.

A sparse fieldset trims each diff in the document, the nested ones included:
`?fields[jsonapi_diff--diff]=tree_summary` returns the counts alone. `?include`
is ignored, because the tree is already whole.

Each response includes the cache tags of both revisions and every entity in the
tree, Diff's configuration tags, and cache contexts for the two version
parameters, the fieldset, the user's permissions and the content language. Saving
the entity invalidates the cached diff.

## Access

The module defines no permission. Each side is subject to the decision core
JSON:API makes for that revision on its own individual route: view access to the
entity, plus revision view access for a non-default revision. A denial on either
side is a `403` for the whole diff, and a label-only view counts as a denial.

Inside the tree, a field the user may not view is absent and uncounted, and an
entity the user may not view is absent from `children` and from `included`. No
`tree_summary` above it counts its fields, so a rollup never reports a change the
reader cannot be shown.

The smallest role that reads the bare URL of a moderated article holds:

| Permission | Comes from |
| --- | --- |
| `access content` | Node |
| `view all revisions`, or `view <type> revisions` | Node |
| `view latest version` | Content Moderation |
| Whatever opens the unpublished draft, such as `view own unpublished content` | Node |

Core's node access handler skips its own bypass for revision operations, so
`bypass node access` alone does not open the working copy. Granting `view all
revisions` to anonymous makes every revision of every node public, through core
JSON:API as much as through this route, so grant it to an authenticated editor
role instead.

The route uses whatever authentication the site already applies to JSON:API. With
JSON:API Hypermedia installed, the `diff` link appears only when the user could
follow it, because the provider resolves the same version pair the route does and
checks access to both.

## Limitations

**A field's items are matched by delta.** Nothing else survives a save, because a
field item does not carry an id. That reports an edit in place exactly. Insert an
item at the front, though, and the rest shift, so the field reads as a run of
changed items with one addition at the end. Where insertion order matters, model
those items as referenced entities, which are matched by entity id.

**A positional pair is an inference.** Children that no id matched are paired at
one delta of one field, when they share an entity type and bundle and their text
agrees by at least half its words. That reads a page which kept its
shape. An insertion moves the blocks after it, and a rewritten block no longer
agrees, so both fall back to a removal and an addition. `match: position` marks
every inference.

The issue queue tracks the rest.

## FAQ

**Q: A field is missing from the diff. Why?**

**A:** Diff compares a field when it has a builder plugin for it and that plugin
is not hidden. Without an explicit setting, Diff decides by asking whether the
field appears in any view display, so a field hidden from every display is never
compared. Set the field explicitly at `/admin/config/content/diff/fields`, which
states the intent rather than relying on a side effect. Diff also skips fields
that are not revisionable, and JSON:API can exclude a field from its resource
type.

**Q: Why does an unchanged field send its full text?**

**A:** The client this was built for renders the page from the same data, so
sending it once saves a request. For the tree's shape alone, ask for
`fields[jsonapi_diff--diff]=tree_summary,children` and walk the counts.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
