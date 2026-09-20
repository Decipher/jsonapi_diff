# Changelog

Every change to JSON:API Diff that a site would notice is documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-alpha1] - Unreleased

First release. The module exposes the difference between two revisions of an
entity as a JSON:API document, so a decoupled frontend can show an editor what
changed without rendering Drupal's admin theme.

### Added

- A read-only route, `GET /jsonapi/diff/{entity_type}/{bundle}/{uuid}`,
  registered through JSON:API Resources, for any entity type that keeps
  revisions and that JSON:API exposes.
- `leftVersion` and `rightVersion` query parameters in core JSON:API's own
  resource version grammar, `id:<revision id>`, `rel:latest-version` and
  `rel:working-copy`, resolved by core's version negotiator. They default to the
  published revision and the working copy, so the bare URL answers the editor's
  question.
- One diff resource per entity in the tree: the addressed entity as primary
  data, and every entity the comparison recursed into as a resource of the same
  type in `included`.
- `left` and `right` relationships carrying the compared entity, the resolved
  version and revision id in `meta`, and the individual JSON:API URL of that
  version as `links.related`.
- A `children` relationship recording each nested entity's reference field, its
  position on both sides, and a status of `same`, `moved`, `added` or `removed`.
- `attributes.fields`, keyed by JSON:API public field name, with each field's
  label, status, both values and plain line operations, beside an
  `attributes.summary` counting the entity's own fields by status.
- `items` on every field entry, reporting a delta, a status, both values and
  that item's own line operations per item of the field. A gallery with one
  image swapped names the image, so a comparison UI highlights the item rather
  than the whole field. A field of cardinality one reports one item at delta 0,
  and the field's own status agrees with its items by construction. `summary`
  and `tree_summary` go on counting fields, not items.
- `attributes.tree_summary`, the same four counts for the entity and every
  entity below it. `summary` answers "did this entity change" and
  `tree_summary` answers "did this entity or anything inside it change", which
  on a page built from paragraphs is the question a comparison UI asks. An
  entity dropped from the document for access reasons is counted in no rollup.
- Access per side from core JSON:API's own decision for that revision, including
  content moderation's latest-version rule. Either side denied is a `403` for
  the whole diff, and the module adds no permission of its own.
- Complete cacheability on every response: the tags of every compared and nested
  entity, Diff's configuration tags, and the cacheability of every access
  decision.
- An optional `diff` discovery link on revisionable resource objects, through
  JSON:API Hypermedia, present only when the user could follow it. The route
  works without that module.
- JSON:API sparse fieldsets on the diff type, so
  `?fields[jsonapi_diff--diff]=tree_summary` returns the counts alone. A fieldset
  trims every diff in the document, the nested ones as well as the primary data,
  and two fieldsets are cached separately.
- Positional alignment of the children no entity id matched. A draft that
  replaced its paragraphs with new entities, which a script or an import does,
  used to report every block as removed and added. The children left over after
  the id pass are now paired by the position they hold, so such a draft reports
  the block it edited and leaves the rest alone. A pair must sit at the same
  delta of the same field, be of the same entity type and bundle, and share at
  least half its words with the other side, measured over the fields the reader
  may view. Below that the two stay an honest removal and addition.
- `match` on every child identifier's `meta`, saying how the two sides were
  brought together: `id` for the same entity on both sides, `position` for a
  pair the module inferred, and `none` for a child only one side has. A
  positional pair also names two different entities in its `left` and `right`
  relationships and carries no `self` link, because the route compares
  revisions of one entity and cannot restate such a pair.

### Known limitations

- Positional alignment reads a page that kept its shape. An insertion or a
  deletion shifts every block after it out of its position, a block rewritten
  from scratch no longer shares enough words to be paired, and two replaced
  blocks that swapped places are not seen as moved. Each of those falls back to
  removed and added.
- A positional pair is a guess, and `match: position` is the only warning the
  document gives. Two blocks of one bundle that happen to share their words are
  paired.
- A field's items are paired by delta, because a field item carries no id. An
  item inserted before another shifts it, so the positions after an insertion
  read as changed rather than as one insertion.
- A reference field that recurses appears as `children` and has no entry of its
  own in `fields`.
- A sparse fieldset trims a resource's members and not the tree, so there is no
  way to keep `fields` and leave out the entries whose status is `same`.
