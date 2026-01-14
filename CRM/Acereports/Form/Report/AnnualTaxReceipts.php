<?php
declare(strict_types = 1);

use CRM_Acereports_ExtensionUtil as E;

class CRM_Acereports_Form_Report_AnnualTaxReceipts extends CRM_Report_Form {

  // Live data, TBD.
  //  private $_customDataTransactionalData_fieldIdLetterGroup = 32;
  //  private $_customDataTransactionalData_valuesTall = "TALL', 'FOAL'";
  //  private $_customDataTransactionalData_valuesTpg = 'TPG';
  //  private $_customDataTransactionalData_tableName = '';
  //  private $_customDataTransactionalData_column = '';
  //
  // Dev data, to be replaced with live data, above.

  /**
   * Custom field id for relevant custom field.
   */
  private $_customDataTransactionalData_fieldIdLetterGroup = 7;

  /**
   * "TALL/FOAL" values in that custom field. String for use in mysql IN();
   */
  private $_customDataTransactionalData_valuesTall = '1, 2';

  /**
   * "TPG" values in that custom field. String for use in mysql IN();
   */
  private $_customDataTransactionalData_valuesTpg = 3;

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
    $customField = \Civi\Api4\CustomField::get(TRUE)
      ->addWhere('id', '=', $this->_customDataTransactionalData_fieldIdLetterGroup)
      ->setLimit(1)
      ->addChain('custom_group', \Civi\Api4\CustomGroup::get(TRUE)
        ->addWhere('id', '=', '$custom_group_id')
        ->setUseCache(FALSE),
      0)
      ->execute()
      ->first();
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
          'tall_sum' => array(
            'dbAlias' => 'sum(contribution_civireport.total_amount)',
            'title' => E::ts('TALL/FOAL/Null Sum'),
            'default' => TRUE,
          ),
          'tpg_sum' => array(
            'dbAlias' => 't1.tpg_sum',
            'title' => E::ts('TPG Sum'),
            'default' => TRUE,
          ),
          'both_sum' => array(
            'dbAlias' => 't2.both_sum',
            'title' => E::ts('Combined TALL/FOAL/Null and TPG Sum'),
            'default' => TRUE,
          ),
          'total_sum' => array(
            'dbAlias' => 't3.total_sum',
            'title' => E::ts('All Contributions Sum'),
            'default' => TRUE,
          ),
        ),
        'filters' => array(
          'receive_date' => array(
            'title' => E::ts('Relevant Contribution Date'),
            'operatorType' => CRM_Report_Form::OP_DATE,
            'default' => ((date('n') === '1') ? 'previous.year' : 'this.year'),
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

    $receiveDateWhereClause = $this->_generateReceiveDateWhereClause();

    $this->_from = "
        FROM  civicrm_contact {$this->_aliases['civicrm_contact']} {$this->_aclFrom}
          INNER JOIN civicrm_contribution {$this->_aliases['civicrm_contribution']}
            ON {$this->_aliases['civicrm_contact']}.id =
               {$this->_aliases['civicrm_contribution']}.contact_id AND {$this->_aliases['civicrm_contribution']}.is_test = 0
          inner join {$this->_customDataTransactionalData_tableName} di
            on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
            and (di.{$this->_customDataTransactionalData_column} in ({$this->_customDataTransactionalData_valuesTall}) or ifnull(di.{$this->_customDataTransactionalData_column}, '') = '')

          /* sub-query to get totals (for all contacts) for 'TPG' contributions within the given period */
          left join (
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as tpg_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              inner join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
                and (di.{$this->_customDataTransactionalData_column} in ({$this->_customDataTransactionalData_valuesTpg}))
            where
              {$this->_aliases['civicrm_contribution']}.is_test = 0
              and $receiveDateWhereClause
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t1 on t1.contact_id = {$this->_aliases['civicrm_contact']}.id

          /* sub-query to get totals (for all contacts) for 'TPG and TALL/FOAL/NULL' contributions within the given period */
          left join (
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as both_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              inner join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
                and (
                  (di.{$this->_customDataTransactionalData_column} in ({$this->_customDataTransactionalData_valuesTall}) or ifnull(di.{$this->_customDataTransactionalData_column}, '') = '')
                  or (di.{$this->_customDataTransactionalData_column} in ({$this->_customDataTransactionalData_valuesTpg}))
                )
            where
              {$this->_aliases['civicrm_contribution']}.is_test = 0
              and $receiveDateWhereClause
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t2 on t2.contact_id = {$this->_aliases['civicrm_contact']}.id

          /* sub-query to get totals (for all contacts) for ALL contributions within the given period */
          left join (
            select {$this->_aliases['civicrm_contribution']}.contact_id, sum({$this->_aliases['civicrm_contribution']}.total_amount) as total_sum
            from civicrm_contribution {$this->_aliases['civicrm_contribution']}
              inner join {$this->_customDataTransactionalData_tableName} di
                on di.entity_id = {$this->_aliases['civicrm_contribution']}.id
            where
              {$this->_aliases['civicrm_contribution']}.is_test = 0
              and $receiveDateWhereClause
            group by {$this->_aliases['civicrm_contribution']}.contact_id
          ) t3 on t3.contact_id = {$this->_aliases['civicrm_contact']}.id
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

    $statistics['filters'][] = [
      'title' => 'Limited to',
      'value' => 'One row per-contact, only for contacts having a contribution with TALL, FOAL, or (Null) in the "Letter Code" field, within the "Relevant Contribution Date" range.',
    ];
    $statistics['filters'][] = [
      'title' => 'About "Sum" columns',
      'value' => '"Sum" columns reflect contribution totals within the "Relevant Contribution Date" range.',
    ];

    return $statistics;
  }

  public function groupBy() {
    // This report template offers no sorting options, so there's no way for the user
    // to configure a 'group by' setting. Therefore we don't need parent::groupBy() at
    // all, and can simply force 'group by civicrm_contact.id'
    $this->_groupBy = " GROUP BY {$this->_aliases['civicrm_contact']}.id";
  }

}
