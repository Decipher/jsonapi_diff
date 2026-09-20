<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff_test;

/**
 * The names and cacheability the test access rules use.
 *
 * A contributed per-field access rule, such as field_permissions, decides
 * per user and says so in its access result. This module does the same, so
 * a test can prove the decision's cacheability reached the response.
 */
final class TestAccess {

  /**
   * The field no user may view.
   */
  public const string RESTRICTED_FIELD = 'field_secret';

  /**
   * The cache tag every field access decision carries.
   */
  public const string CACHE_TAG = 'jsonapi_diff_test.field_access';

}
