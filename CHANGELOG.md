# Changelog

All notable changes to JSON:API Diff are documented in this file.

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
- Access per side from core JSON:API's own decision for that revision, including
  content moderation's latest-version rule. Either side denied is a `403` for
  the whole diff, and the module adds no permission of its own.
- Complete cacheability on every response: the tags of every compared and nested
  entity, Diff's configuration tags, and the cacheability of every access
  decision.
- An optional `diff` discovery link on revisionable resource objects, through
  JSON:API Hypermedia, present only when the user could follow it. The route
  works without that module.

### Known limitations

- Nested entities are matched by entity id, so a workflow that creates new
  paragraph entities per draft reports every block as removed and added.
- A multi-value field is reported with one status for the whole field, because
  Diff joins its values into one string.
- A reference field that recurses appears as `children` and has no entry of its
  own in `fields`.
