<?php

declare(strict_types=1);

namespace Drupal\jsonapi_diff_test;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\paragraphs\ParagraphAccessControlHandler;

/**
 * Lets a paragraph's label be read when the paragraph itself may not be.
 *
 * Core answers that case with a label-only resource object, which the diff
 * has to treat as a denial. Paragraphs does not distinguish the two
 * operations, so this handler does, the way core's own user and taxonomy
 * handlers do.
 */
final class TestParagraphAccessControlHandler extends ParagraphAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected $viewLabelOperation = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view label') {
      return AccessResult::allowed();
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
