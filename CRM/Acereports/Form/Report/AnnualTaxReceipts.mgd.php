<?php

// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
return [
  [
    'name' => 'CRM_Acereports_Form_Report_AnnualTaxReceipts',
    'entity' => 'ReportTemplate',
    'params' => [
      'version' => 3,
      'label' => 'Annual Tax Receipts',
      'description' => 'Annual Tax Receipts (com.joineryhq.acereports)',
      'class_name' => 'CRM_Acereports_Form_Report_AnnualTaxReceipts',
      'report_url' => 'com.joineryhq.acereports/annualtaxreceipts',
      'component' => 'CiviContribute',
    ],
  ],
];
