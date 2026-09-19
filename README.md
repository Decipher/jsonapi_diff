# JSON:API Diff

[![Pipeline](https://git.drupalcode.org/project/jsonapi_diff/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/jsonapi_diff/-/pipelines)
[![Test](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/jsonapi_diff/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/jsonapi_diff/branch/1.0.x)

Exposes the difference between two revisions of an entity as a JSON:API
document, computed by the Diff module and served on a sibling JSON:API route,
so a decoupled frontend can show an editor what changed between the published
revision and a draft.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/jsonapi_diff).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/jsonapi_diff).

## Table of contents

- Requirements
- Installation
- The endpoint
- Maintainers

## Requirements

- PHP 8.3 or later
- Drupal 10.5 or 11
- JSON:API (Drupal core)
- [Diff](https://www.drupal.org/project/diff)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Installation

1. Download and install via Composer:

   ```bash
   composer require drupal/jsonapi_diff
   ```

2. Enable the module:

   ```bash
   drush en jsonapi_diff
   ```

## The endpoint

Documentation of the route and the document it returns follows in a later
change.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
