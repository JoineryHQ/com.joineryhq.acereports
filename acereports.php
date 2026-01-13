<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'acereports.civix.php';
// phpcs:enable

use CRM_Acereports_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function acereports_civicrm_config(\CRM_Core_Config $config): void {
  _acereports_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function acereports_civicrm_install(): void {
  _acereports_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function acereports_civicrm_enable(): void {
  _acereports_civix_civicrm_enable();
}
