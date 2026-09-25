# Changelog

Every change to JSON:API Diff that a site would notice is documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-alpha1] - Unreleased

First release.

### Added

- `GET /jsonapi/diff/{entity_type}/{bundle}/{uuid}`, a read-only route for any
  revisionable entity type JSON:API exposes, registered through JSON:API
  Resources.
- `leftVersion` and `rightVersion` query parameters in core JSON:API's resource
  version grammar, resolved by core's negotiator. They default to the published
  revision and the working copy.
- One `jsonapi_diff--diff` resource per entity in the tree: the addressed entity
  as primary data, and everything the comparison recursed into under `included`.
- `left` and `right` relationships naming the compared entity, with the resolved
  version and revision id in `meta` and the individual JSON:API URL of that
  version as `links.related`.
- `children` relationships listing the nested diffs, each identifier recording
  the reference field, both deltas, a `status` of `same`, `moved`, `added` or
  `removed`, and a `match` of `id`, `position` or `none`.
- `attributes.fields`, one entry per compared field keyed by JSON:API public
  name, holding the label, a status, both sides as strings, plain line
  operations, and the same per item of the field with its delta.
- `attributes.summary` and `attributes.tree_summary`, counting the entity's own
  fields and the whole subtree by status.
- An entry in `attributes.fields` for each reference field the comparison
  recursed into, whose status says whether the list changed. A reorder, and a
  child that reports no fields of its own, reach the counts through it.
- Positional alignment of the children no entity id matched, so a draft that
  replaced its paragraphs with new entities reports the block it edited. A pair
  sits at one delta of one field, shares an entity type and bundle, and agrees on
  at least half its words, measured over the fields the reader may view and
  ignoring markup.
- JSON:API sparse fieldsets on the diff type, which trim every diff in the
  document including the nested ones.
- Entity and field access on every entity in the tree, taken for the account the
  compared revisions were resolved for. A label-only result counts as a denial,
  and a denied entity leaves both sides.
- A `diff` link on every revisionable resource object when
  [JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia) is
  installed, offered only where the route would answer.
- Cache tags for both revisions and every entity in the tree, Diff's
  configuration tags, and cache contexts for the version parameters, the
  fieldset, the user's permissions and the content language.

### Known limitations

- A field's items are matched by delta, because a field item has no id that
  survives a save. An item inserted at the front shifts the rest.
- A positional pair is an inference, marked `match: position`. An insertion moves
  the blocks after it and a rewritten block no longer agrees, so both report as a
  removal and an addition.
