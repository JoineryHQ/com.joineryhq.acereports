<?php
declare(strict_types = 1);

use CRM_Acereports_ExtensionUtil as E;

class CRM_Acereports_Form_Report_AnnualTaxReceipts extends CRM_Report_Form {

  /**
   * Custom field id for relevant custom field.
   */
  private $_customDataTransactionalData_fieldIdLetterCode = 32;

  /**
   * Values in that custom field (along with null/no-value) to be considered "Deductible".
   */
  private $_customDataTransactionalData_valuesDeductibleOrNull = ['TALL', 'FOAL', 'STOCK', 'GRANT', 'BEQST'];

  /**
   * Values in that custom field to be considered "Third Party".
   */
  private $_customDataTransactionalData_valuesThirdParty = ['TPG', 'Canada', 'IRA', 'CHARGIFT'];

/**
   * Custom-group table name for relevant custom field (auto-populated via api in __construct() )
   */
  private $_customDataTransactionalData_tableName = '';

  /**
   * Custom-field column name for relevant custom field (auto-populated via api in __construct() )
   */
  private $_customDataTransactionalData_column = '';

  /**
   * Fallback value for 'where' value on 'receive_date' field; suitable for use
   * in the same places as the return value of $this->generateFilterClause().
   * We need this because we're inserting this value in from(), but it may sometimes
   * not be specifically un-set by the user in Filters, in which case -- without this --
   * we'd get an sql syntax error for a where clause like "and [blank]"
   */
  private $_fallbackDateWhereClause = "1 /* _generateReceiveDateWhereClause */";

  protected $_addressField = FALSE;

  protected $_emailField = FALSE;

  protected $_summary = NULL;

  protected $_customGroupExtends = array('Contact', 'Individual', 'Organization');

  protected $_customGroupGroupBy = FALSE;

  public function __construct() {
    // These are local-dev values, to be commented out before commit to live!
    // $this->_customDataTransactionalData_fieldIdLetterCode = 7;
    // $this->_customDataTransactionalData_valuesDeductibleOrNull = [1, 2];
    // $this->_customDataTransactionalData_valuesThirdParty = [3];

    $customField = \Civi\Api4\CustomField::get(TRUE)
      ->addWhere('id', '=', $this->_customDataTransactionalData_fieldIdLetterCode)
      ->setLimit(1)
      ->addChain('custom_group', \Civi\Api4\CustomGroup::get(TRUE)
        ->addWhere('id', '=', '$custom_group_id')
        ->setUseCache(FALSE),
      0)
      ->execute()
      ->first();
    if (empty($customField['column_name'])) {
      $this->_fatalError('Could not find the relevant custom field; this is a bug in the report which requires developer attention.');
    }
    $this->_customDataTransactionalData_column = $customField['column_name'];
    $this->_customDataTransactionalData_tableName = $customField['custom_group']['table_name'];

    $this->_columns = array(
      'civicrm_contact' => array(
        'dao' => 'CRM_Contact_DAO_Contact',
        'fields' => array(

          'optional_contact_id' => array(
            'name' => 'id',
            'title' => E::ts('Contact ID'),
            'default' => TRUE,
          ),
          'id' => array(
            'no_display' => TRUE,
            'required' => TRUE,
          ),
          'sort_name' => array(
            'title' => E::ts('Contact Name'),
            'default' => TRUE,
            'no_repeat' => TRUE,
          ),
          'first_name' => array(
            'title' => E::ts('First Name'),
            'no_repeat' => TRUE,
          ),
          'last_name' => array(
            'title' => E::ts('Last Name'),
            'no_repeat' => TRUE,
          ),
        ),
        'filters' => array(
          'sort_name' => array(
            'title' => E::ts('Contact Name'),
            'operator' => 'like',
          ),
          'id' => array(
            'no_display' => TRUE,
          ),
        ),
        'grouping' => 'contact-fields',
      ),
      'civicrm_address' => array(
        'dao' => 'CRM_Core_DAO_Address',
        'fields' => array(
          'street_address' => array('default' => TRUE),
          'city' => array('default' => TRUE),
          'postal_code' => array('default' => TRUE),
          'state_province_id' => array(
            'title' => E::ts('State/Province'),
            'default' => TRUE,
          ),
          'country_id' => array(
            'title' => E::ts('Country'),
            'default' => TRUE,
          ),
        ),
        'grouping' => 'contact-fields',
      ),
      'civicrm_email' => array(
        'dao' => 'CRM_Core_DAO_Email',
        'fields' => array(
          'email' => array(
            'default' => TRUE,
          ),
        ),
        'grouping' => 'contact-fields',
      ),
      'civicrm_contribution' => array(
        'dao' => 'CRM_Contribute_DAO_Contribution',
        'fields' => array(
          'deductible_sum' => array(
            'dbAlias' => 'ifnull(t_deductible.sub_sum, 0)',
            'title' => E::ts('Deductible Sum'),
            'default' => TRUE,
          ),
          'third_sum' => array(
            'dbAlias' => 'ifnull(t_third.sub_sum, 0)',
            'title' => E::ts('Third-Party Sum'),
            'default' => TRUE,
          ),
          'both_sum' => array(
            'dbAlias' => 'ifnull(t_both.sub_sum, 0)',
            'title' => E::ts('Combined Dedubctible and Third-Party Sum'),
            'default' => TRUE,
          ),
          'total_sum' => array(
            'dbAlias' => 'ifnull(t_all.sub_sum, 0)',
            'title' => E::ts('All Contributions Sum'),
            'default' => TRUE,
          ),
        ),
        'filters' => array(
          'receive_date' => array(
            'title' => E::ts('Relevant Contribution Date'),
            'operatorType' => CRM_Report_Form::OP_DATE,
            'default' => ((date('n') === '1') ? 'previous.year' : 'this.year'),
            // pseudofield: this filter should not be applied by civireport; we'll use it
            // in our own way -- mainly in _from().
            'pseudofield' => TRUE,
          ),
        ),
        'grouping' => 'contribution-fields',
      ),
    );
    $this->_groupFilter = TRUE;
    $this->_tagFilter = TRUE;
    parent::__construct();

    // We've specifically defined a default-enabled 'contact id' column, so we don't need this one,
    // which parent::_contstruct() seems to have added on its own.
    unset($this->_columns['civicrm_contact']['fields']['exposed_id']);
  }

  /**
   * Calculate (only once per run) the where clause for the 'receive_date' filter.
   * (This is re-used in a few places, esp in from().)
   *
   * @staticvar String $ret
   * @return String
   */
  private function _generateReceiveDateWhereClause() {
    static $ret;
    if (empty($ret)) {
      $clause = $this->generateFilterClause($this->_columns['civicrm_contribution']['filters']['receive_date'], 'receive_date');
      if (empty($clause)) {
        $ret = $this->_fallbackDateWhereClause;
      }
      else {
        $ret = $clause;
      }
    }
    return $ret;
  }

  public function from() {
    $this->_from = NULL;

    // Store a where clause for our 'pseudofield' filter, 'receive_date'.
    $receiveDateWhereClause = $this->_generateReceiveDateWhereClause();

    // Store a collection of WHERE components for all "sum" sub-queries.
    $subTotalsBaseWhere = "
      {$this->_aliases['civicrm_contribution']}.is_test = 0
      and {$this->_aliases['civicrm_contribution']}.contribution_status_id = 1
      and $receiveDateWhereClause
    ";

    // Convert arrays to strings suitable for mysql IN() operator.
    $deductibleValuesIn = $this->_buildInClauseValues($this->_customDataTransactionalData_valuesDeductibleOrNull);
    $thirdPartyValuesIn = $this->_buildInClauseValues($this->_customDataTransactionalData_valuesThirdParty);


    $this->_from = "
        FROM  civicrm_contact {$this->_aliases['civicrm_contact']} {$this->_aclFrom}
          left join (
            /* sub-query to get totals (for all contacts) for 'Deductible' contributions within the given period */
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as sub_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              left join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
            where
              (
                di.{$this->_customDataTransactionalData_column} in ({$deductibleValuesIn})
                or ifnull(di.{$this->_customDataTransactionalData_column}, '') = ''
              )
              and $subTotalsBaseWhere
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t_deductible on t_deductible.contact_id = {$this->_aliases['civicrm_contact']}.id

          left join (
            /* sub-query to get totals (for all contacts) for 'third-party' contributions within the given period */
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as sub_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              inner join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
                  and di.{$this->_customDataTransactionalData_column} in ({$thirdPartyValuesIn})
            where
              $subTotalsBaseWhere
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t_third on t_third.contact_id = {$this->_aliases['civicrm_contact']}.id

          left join (
            /* sub-query to get totals (for all contacts) for 'deductible' AND 'third-party' contributions within the given period */
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as sub_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              left join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
            where
              (
                di.{$this->_customDataTransactionalData_column} in ({$deductibleValuesIn})
                or ifnull(di.{$this->_customDataTransactionalData_column}, '') = ''
                or di.{$this->_customDataTransactionalData_column} in ({$thirdPartyValuesIn})
              )
              and $subTotalsBaseWhere
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t_both on t_both.contact_id = {$this->_aliases['civicrm_contact']}.id

          left join (
            /* sub-query to get totals (for all contacts) for ALL contributions within the given period */
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as sub_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
            where
              $subTotalsBaseWhere
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t_all on t_all.contact_id = {$this->_aliases['civicrm_contact']}.id
      ";
    $this->joinAddressFromContact();
    $this->joinEmailFromContact();
  }

  public function alterDisplay(&$rows) {
    // custom code to alter rows
    $entryFound = FALSE;
    $checkList = array();
    foreach ($rows as $rowNum => $row) {

      if (!empty($this->_noRepeats) && $this->_outputMode != 'csv') {
        // not repeat contact display names if it matches with the one
        // in previous row
        $repeatFound = FALSE;
        foreach ($row as $colName => $colVal) {
          if (($checkList[$colName] ?? NULL) &&
            is_array($checkList[$colName]) &&
            in_array($colVal, $checkList[$colName])
          ) {
            $rows[$rowNum][$colName] = "";
            $repeatFound = TRUE;
          }
          if (in_array($colName, $this->_noRepeats)) {
            $checkList[$colName][] = $colVal;
          }
        }
      }

      if (array_key_exists('civicrm_address_state_province_id', $row)) {
        if ($value = $row['civicrm_address_state_province_id']) {
          $rows[$rowNum]['civicrm_address_state_province_id'] = CRM_Core_PseudoConstant::stateProvince($value, FALSE);
        }
        $entryFound = TRUE;
      }

      if (array_key_exists('civicrm_address_country_id', $row)) {
        if ($value = $row['civicrm_address_country_id']) {
          $rows[$rowNum]['civicrm_address_country_id'] = CRM_Core_PseudoConstant::country($value, FALSE);
        }
        $entryFound = TRUE;
      }

      if (array_key_exists('civicrm_contact_sort_name', $row) &&
        $rows[$rowNum]['civicrm_contact_sort_name'] &&
        array_key_exists('civicrm_contact_id', $row)
      ) {
        $url = CRM_Utils_System::url("civicrm/contact/view",
          'reset=1&cid=' . $row['civicrm_contact_id'],
          $this->_absoluteUrl
        );
        $rows[$rowNum]['civicrm_contact_sort_name_link'] = $url;
        $rows[$rowNum]['civicrm_contact_sort_name_hover'] = E::ts("View Contact Summary for this Contact.");
        $entryFound = TRUE;
      }

      if (!$entryFound) {
        break;
      }
    }
  }

  /**
   * Append our own explanatory rows to the 'statistics/used-filters' block.
   *
   * @param Array $rows
   * @return Array
   */
  public function statistics(&$rows) {
    $statistics = parent::statistics($rows);

    if ($this->_generateReceiveDateWhereClause() == $this->_fallbackDateWhereClause) {
      $statistics['filters'][] = [
        'title' => $this->_columns['civicrm_contribution']['filters']['receive_date']['title'],
        'value' => '[No date range specified]',
      ];
    }

    // Get custom field properties for display.
    $letterCodeField = \Civi\Api4\CustomField::get()
      ->addWhere('id', '=', $this->_customDataTransactionalData_fieldIdLetterCode)
      ->execute()
      ->first();

    $statistics['filters'][] = [
      'title' => 'Limited to',
      'value' => E::ts(
        'One row per-contact, only for contacts having at least one completed contribution with a Deductible or Third-Party value in the "%1" field, within the "Relevant Contribution Date" range.',
        ['%1' => $letterCodeField['label']],
      ),
    ];
    $statistics['filters'][] = [
      'title' => 'About "Sum" columns',
      'value' => '"Sum" columns reflect totals of completed contributions within the "Relevant Contribution Date" range.',
    ];

    $options = $this->_getCustomFieldOptions($letterCodeField['option_group_id']);
    $statistics['filters'][] = [
      'title' => '"Deductible" Letter Codes are:',
      'value' => implode('; ', array_intersect_key($options, array_flip($this->_customDataTransactionalData_valuesDeductibleOrNull))) . '; (Null)',
    ];
    $statistics['filters'][] = [
      'title' => '"Third-Party" Letter Codes are:',
      'value' => implode('; ', array_intersect_key($options, array_flip($this->_customDataTransactionalData_valuesThirdParty))),
    ];


    return $statistics;
  }

  public function where() {
    parent::where();
    // Among all other filters, add our own requirement that the contact must
    // have a "both sum" > 0.
    $this->_where .= "
      and t_both.sub_sum > 0
    ";
  }

  public function groupBy() {
    // This report template offers no sorting options, so there's no way for the user
    // to configure a 'group by' setting. Therefore we don't need parent::groupBy() at
    // all, and can simply force 'group by civicrm_contact.id'
    $this->_groupBy = " GROUP BY {$this->_aliases['civicrm_contact']}.id";
  }

  public function orderBy() {
    // This report template offers no sorting options, so we don't need parent::orderBy().
    // We'll just hard-code sorting by contact.sort_name
    $this->_orderBy = "ORDER BY {$this->_aliases['civicrm_contact']}.sort_name";
  }

  private function _fatalError($msg) {
    \Civi::log()->critical($msg);
    throw new \CRM_Core_Exception($msg);
  }

  /**
   * Get a list of options for the give optionGroup.
   * @param int $optionGroupId
   * @return array
   */
  private function _getCustomFieldOptions($optionGroupId) {
    $options = \Civi\Api4\OptionValue::get()
      ->addWhere('option_group_id', '=', $optionGroupId)
      ->addWhere('is_active', '=', 1)
      ->addOrderBy('label')
      ->execute()
      ->indexBy('value')
      ->column('label');

    return $options;
  }

  /**
   * For an array of strings, convert them to a single sql-safe string suitable for
   * use in the IN() operator.
   *
   * @param array $values
   * @return string
   */
  private function _buildInClauseValues(array $values): string {
    // If array is empty, make sure we return a sql-valid IN() argument.
    if (empty($values)) {
      return 'NULL';
    }

    // Build prams and placeholders to be used in composeQuery().
    $params = [];
    $placeholders = [];

    $i = 0;
    foreach ($values as $value) {
      $i++;
      $params[$i] = [$value, 'String'];
      $placeholders[] = "%{$i}";
    }

    // Compile the template sql and parse it into valid sql.
    $query = implode(',', $placeholders);
    $queryComposed = CRM_Core_DAO::composeQuery($query, $params);

    return $queryComposed;
  }
}
