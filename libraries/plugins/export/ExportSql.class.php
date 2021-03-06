<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Set of functions used to build SQL dumps of tables
 *
 * @package    PhpMyAdmin-Export
 * @subpackage SQL
 */
if (! defined('PHPMYADMIN')) {
    exit;
}

/* Get the export interface */
require_once "libraries/plugins/ExportPlugin.class.php";

/**
 * Handles the export for the SQL class
 *
 * @todo add descriptions for all vars/methods
 * @package PhpMyAdmin-Export
 */
class ExportSql extends ExportPlugin
{
    /**
     * MySQL charset map
     *
     * @var array
     */
    private $_mysqlCharsetMap;

    /**
     * SQL for dropping a table
     *
     * @var string
     */
    private $_sqlDropTable;

    /**
     * SQL Backquotes
     *
     * @var bool
     */
    private $_sqlBackquotes;

    /**
     * SQL Constraints
     *
     * @var string
     */
    private $_sqlConstraints;

    /**
     * The text of the SQL query
     *
     * @var string
     */
    private $_sqlConstraintsQuery;

    /**
     * SQL for dropping foreign keys
     *
     * @var string
     */
    private $_sqlDropForeignKeys;

    /**
     * The number of the current row
     *
     * @var int
     */
    private $_currentRow;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setProperties();

        // Avoids undefined variables, use NULL so isset() returns false
        if (! isset($GLOBALS['sql_backquotes'])) {
            $GLOBALS['sql_backquotes'] = null;
        }
    }

    /**
     * Initialize the local variables that are used specific for export SQL
     *
     * @global array  $mysql_charset_map
     * @global string $sql_drop_table
     * @global bool   $sql_backquotes
     * @global string $sql_constraints
     * @global string $sql_constraints_query
     * @global string $sql_drop_foreign_keys
     * @global int    $current_row
     *
     * @return void
     */
    protected function initSpecificVariables()
    {
        global $sql_drop_table;
        global $sql_backquotes;
        global $sql_constraints;
        global $sql_constraints_query;
        global $sql_drop_foreign_keys;
        $this->_setSqlDropTable($sql_drop_table);
        $this->_setSqlBackquotes($sql_backquotes);
        $this->_setSqlConstraints($sql_constraints);
        $this->_setSqlConstraintsQuery($sql_constraints_query);
        $this->_setSqlDropForeignKeys($sql_drop_foreign_keys);
    }

    /**
     * Sets the export SQL properties
     *
     * @return void
     */
    protected function setProperties()
    {
        global $plugin_param;
        $this->setPluginParam($plugin_param);

        $hide_sql = false;
        $hide_structure = false;
        if ($plugin_param['export_type'] == 'table'
            && ! $plugin_param['single_table']
        ) {
            $hide_structure = true;
            $hide_sql = true;
        }
        if (! $hide_sql) {
            $this->properties = array(
                'text' => __('SQL'),
                'extension' => 'sql',
                'mime_type' => 'text/x-sql',
                'options' => array(),
                'options_text' => __('Options')
            );

            $this->properties['options'][] = array(
                'type' => 'begin_group',
                'name' => 'general_opts'
            );

            /* comments */
            $this->properties['options'][] = array(
                'type' => 'begin_subgroup',
                'subgroup_header' => array(
                    'type' => 'bool',
                    'name' => 'include_comments',
                    'text' => __(
                        'Display comments <i>(includes info such as export'
                        . ' timestamp, PHP version, and server version)</i>'
                    )
                )
            );
            $this->properties['options'][] = array(
                'type' => 'text',
                'name' => 'header_comment',
                'text' => __('Additional custom header comment (\n splits lines):')
            );
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'dates',
                'text' => __(
                    'Include a timestamp of when databases were created, last'
                    . ' updated, and last checked'
                )
            );
            if (! empty($GLOBALS['cfgRelation']['relation'])) {
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'relation',
                    'text' => __('Display foreign key relationships')
                );
            }
            if (! empty($GLOBALS['cfgRelation']['mimework'])) {
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'mime',
                    'text' => __('Display MIME types')
                );
            }
            $this->properties['options'][] = array(
                'type' => 'end_subgroup'
            );
            /* end comments */

            /* enclose in a transaction */
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'use_transaction',
                'text' => __('Enclose export in a transaction'),
                'doc' => array(
                    'programs',
                    'mysqldump',
                    'option_mysqldump_single-transaction'
                )
            );

            /* disable foreign key checks */
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'disable_fk',
                'text' => __('Disable foreign key checks'),
                'doc' => array(
                    'manual_MySQL_Database_Administration',
                    'server-system-variables',
                    'sysvar_foreign_key_checks'
                )
            );

            /* compatibility maximization */
            $compats = PMA_DBI_getCompatibilities();
            if (count($compats) > 0) {
                $values = array();
                foreach ($compats as $val) {
                    $values[$val] = $val;
                }
                $this->properties['options'][] = array(
                    'type' => 'select',
                    'name' => 'compatibility',
                    'text' => __(
                        'Database system or older MySQL server to maximize output'
                        . ' compatibility with:'
                    ),
                    'values' => $values,
                    'doc' => array(
                        'manual_MySQL_Database_Administration',
                        'Server_SQL_mode'
                    )
                );
                unset($values);
            }

            /* server export options */
            if ($plugin_param['export_type'] == 'server') {
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'drop_database',
                    'text' => sprintf(
                        __('Add %s statement'), '<code>DROP DATABASE</code>'
                    )
                );
            }

            /* what to dump (structure/data/both) */
            $this->properties['options'][] = array(
                'type' => 'begin_subgroup',
                'subgroup_header' => array(
                    'type' => 'message_only',
                    'text' => __('Dump table')
                )
            );
            $this->properties['options'][] = array(
                'type' => 'radio',
                'name' => 'structure_or_data',
                'values' => array(
                    'structure' => __('structure'),
                    'data' => __('data'),
                    'structure_and_data' => __('structure and data')
                )
            );
            $this->properties['options'][] = array(
                'type' => 'end_subgroup'
            );

            $this->properties['options'][] = array(
                'type' => 'end_group'
            );

            /* begin Structure options */
            if (! $hide_structure) {
                $this->properties['options'][] = array(
                    'type' => 'begin_group',
                    'name' => 'structure',
                    'text' => __('Object creation options'),
                    'force' => 'data'
                );

                /* begin SQL Statements */
                $this->properties['options'][] = array(
                    'type' => 'begin_subgroup',
                    'subgroup_header' => array(
                        'type' => 'message_only',
                        'name' => 'add_statements',
                        'text' => __('Add statements:')
                    )
                );
                if ($plugin_param['export_type'] == 'table') {
                    if (PMA_Table::isView($GLOBALS['db'], $GLOBALS['table'])) {
                        $drop_clause = '<code>DROP VIEW</code>';
                    } else {
                        $drop_clause = '<code>DROP TABLE</code>';
                    }
                } else {
                    if (PMA_DRIZZLE) {
                        $drop_clause = '<code>DROP TABLE</code>';
                    } else {
                        $drop_clause = '<code>DROP TABLE / VIEW / PROCEDURE'
                            . ' / FUNCTION</code>';
                        if (PMA_MYSQL_INT_VERSION > 50100) {
                            $drop_clause .= '<code> / EVENT</code>';
                        }
                    }
                }
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'drop_table',
                    'text' => sprintf(__('Add %s statement'), $drop_clause)
                );
                // Drizzle doesn't support procedures and functions
                if (! PMA_DRIZZLE) {
                    $this->properties['options'][] = array(
                        'type' => 'bool',
                        'name' => 'procedure_function',
                        'text' => sprintf(
                            __('Add %s statement'),
                            '<code>CREATE PROCEDURE / FUNCTION'
                            . (PMA_MYSQL_INT_VERSION > 50100
                            ? ' / EVENT</code>' : '</code>')
                        )
                    );
                }

                /* begin CREATE TABLE statements*/
                $this->properties['options'][] = array(
                    'type' => 'begin_subgroup',
                    'subgroup_header' => array(
                        'type' => 'bool',
                        'name' => 'create_table_statements',
                        'text' => __('<code>CREATE TABLE</code> options:')
                    )
                );
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'if_not_exists',
                    'text' => '<code>IF NOT EXISTS</code>'
                );
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'auto_increment',
                    'text' => '<code>AUTO_INCREMENT</code>'
                );
                $this->properties['options'][] = array(
                    'type' => 'end_subgroup'
                );
                /* end CREATE TABLE statements */

                $this->properties['options'][] = array(
                    'type' => 'end_subgroup'
                );
                /* end SQL statements */

                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'backquotes',
                    'text' => __(
                        'Enclose table and column names with backquotes '
                        . '<i>(Protects column and table names formed with'
                        . ' special characters or keywords)</i>'
                    )
                );

                $this->properties['options'][] = array(
                    'type' => 'end_group'
                );
            }
            /* end Structure options */

            /* begin Data options */
            $this->properties['options'][] = array(
                'type' => 'begin_group',
                'name' => 'data',
                'text' => __('Data dump options'),
                'force' => 'structure'
            );
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'truncate',
                'text' => __('Truncate table before insert')
            );
            /* begin SQL statements */
            $this->properties['options'][] = array(
                'type' => 'begin_subgroup',
                'subgroup_header' => array(
                    'type' => 'message_only',
                    'text' => __('Instead of <code>INSERT</code> statements, use:')
                )
            );
            // Not supported in Drizzle
            if (! PMA_DRIZZLE) {
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'delayed',
                    'text' => __('<code>INSERT DELAYED</code> statements'),
                    'doc' => array(
                        'manual_MySQL_Database_Administration',
                        'insert_delayed'
                    )
                );
            }
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'ignore',
                'text' => __('<code>INSERT IGNORE</code> statements'),
                'doc' => array(
                    'manual_MySQL_Database_Administration',
                    'insert'
                )
            );
            $this->properties['options'][] = array(
                'type' => 'end_subgroup'
            );
            /* end SQL statements */

            /* Function to use when dumping data */
            $this->properties['options'][] = array(
                'type' => 'select',
                'name' => 'type',
                'text' => __('Function to use when dumping data:'),
                'values' => array(
                    'INSERT' => 'INSERT',
                    'UPDATE' => 'UPDATE',
                    'REPLACE' => 'REPLACE'
                )
            );

            /* Syntax to use when inserting data */
            $this->properties['options'][] = array(
                'type' => 'begin_subgroup',
                'subgroup_header' => array(
                    'type' => 'message_only',
                    'text' => __('Syntax to use when inserting data:')
                )
            );
            $this->properties['options'][] = array(
                'type' => 'radio',
                'name' => 'insert_syntax',
                'values' => array(
                    'complete' => __(
                        'include column names in every <code>INSERT</code> statement'
                        . ' <br /> &nbsp; &nbsp; &nbsp; Example: <code>INSERT INTO'
                        . ' tbl_name (col_A,col_B,col_C) VALUES (1,2,3)</code>'
                    ),
                    'extended' => __(
                        'insert multiple rows in every <code>INSERT</code> statement'
                        . '<br /> &nbsp; &nbsp; &nbsp; Example: <code>INSERT INTO'
                        . ' tbl_name VALUES (1,2,3), (4,5,6), (7,8,9)</code>'
                    ),
                    'both' => __(
                        'both of the above<br /> &nbsp; &nbsp; &nbsp; Example:'
                        . ' <code>INSERT INTO tbl_name (col_A,col_B) VALUES (1,2,3),'
                        . ' (4,5,6), (7,8,9)</code>'
                    ),
                    'none' => __(
                        'neither of the above<br /> &nbsp; &nbsp; &nbsp; Example:'
                        . ' <code>INSERT INTO tbl_name VALUES (1,2,3)</code>'
                    )
                )
            );
              $this->properties['options'][] = array(
                'type' => 'end_subgroup'
              );

            /* Max length of query */
            $this->properties['options'][] = array(
                'type' => 'text',
                'name' => 'max_query_size',
                'text' => __('Maximal length of created query')
                );

            /* Dump binary columns in hexadecimal */
            $this->properties['options'][] = array(
                'type' => 'bool',
                'name' => 'hex_for_blob',
                'text' => __(
                    'Dump binary columns in hexadecimal notation'
                    . ' <i>(for example, "abc" becomes 0x616263)</i>'
                )
            );

            // Drizzle works only with UTC timezone
            if (! PMA_DRIZZLE) {
                /* Dump time in UTC */
                $this->properties['options'][] = array(
                    'type' => 'bool',
                    'name' => 'utc_time',
                    'text' => __(
                        'Dump TIMESTAMP columns in UTC <i>(enables TIMESTAMP columns'
                        . ' to be dumped and reloaded between servers in different'
                        . ' time zones)</i>'
                    )
                );
            }

            $this->properties['options'][] = array(
                'type' => 'end_group'
            );
            /* end Data options */
        }
    }

    /**
     * This method is called when any PluginManager to which the observer
     * is attached calls PluginManager::notify()
     *
     * @param SplSubject $subject The PluginManager notifying the observer
     *                            of an update.
     *
     * @return void
     */
    public function update (SplSubject $subject)
    {
    }

    /**
     * Exports routines (procedures and functions)
     *
     * @param string $db Database
     *
     * @return bool Whether it succeeded
     */
    public function exportRoutines($db)
    {
        global $crlf;
        $this->setCrlf($crlf);

        $common_functions = PMA_CommonFunctions::getInstance();
        $text = '';
        $delimiter = '$$';

        $procedure_names = PMA_DBI_get_procedures_or_functions($db, 'PROCEDURE');
        $function_names = PMA_DBI_get_procedures_or_functions($db, 'FUNCTION');

        if ($procedure_names || $function_names) {
            $text .= $crlf
                . 'DELIMITER ' . $delimiter . $crlf;
        }

        if ($procedure_names) {
            $text .=
                $this->_exportComment()
              . $this->_exportComment(__('Procedures'))
              . $this->_exportComment();

            foreach ($procedure_names as $procedure_name) {
                if (! empty($GLOBALS['sql_drop_table'])) {
                    $text .= 'DROP PROCEDURE IF EXISTS '
                        . $common_functions->backquote($procedure_name)
                        . $delimiter . $crlf;
                }
                $text .= PMA_DBI_get_definition($db, 'PROCEDURE', $procedure_name)
                    . $delimiter . $crlf . $crlf;
            }
        }

        if ($function_names) {
            $text .=
                $this->_exportComment()
              . $this->_exportComment(__('Functions'))
              . $this->_exportComment();

            foreach ($function_names as $function_name) {
                if (! empty($GLOBALS['sql_drop_table'])) {
                    $text .= 'DROP FUNCTION IF EXISTS '
                        . $common_functions->backquote($function_name)
                        . $delimiter . $crlf;
                }
                $text .= PMA_DBI_get_definition($db, 'FUNCTION', $function_name)
                    . $delimiter . $crlf . $crlf;
            }
        }

        if ($procedure_names || $function_names) {
            $text .= 'DELIMITER ;' . $crlf;
        }

        if (! empty($text)) {
            return PMA_exportOutputHandler($text);
        } else {
            return false;
        }
    }

    /**
     * Possibly outputs comment
     *
     * @param string $text Text of comment
     *
     * @return string The formatted comment
     */
    private function _exportComment($text = '')
    {
        if (isset($GLOBALS['sql_include_comments'])
            && $GLOBALS['sql_include_comments']
        ) {
            // see http://dev.mysql.com/doc/refman/5.0/en/ansi-diff-comments.html
            return '--' . (empty($text) ? '' : ' ') . $text . $GLOBALS['crlf'];
        } else {
            return '';
        }
    }

    /**
     * Possibly outputs CRLF
     *
     * @return string $crlf or nothing
     */
    private function _possibleCRLF()
    {
        if (isset($GLOBALS['sql_include_comments'])
            && $GLOBALS['sql_include_comments']
        ) {
            return $GLOBALS['crlf'];
        } else {
            return '';
        }
    }

    /**
     * Outputs export footer
     *
     * @return bool Whether it succeeded
     */
    public function exportFooter()
    {
        global $crlf;
        $this->setCrlf($crlf);
        $mysql_charset_map = $this->_getMysqlCharsetMap();

        $foot = '';

        if (isset($GLOBALS['sql_disable_fk'])) {
            $foot .=  'SET FOREIGN_KEY_CHECKS=1;' . $crlf;
        }

        if (isset($GLOBALS['sql_use_transaction'])) {
            $foot .=  'COMMIT;' . $crlf;
        }

        // restore connection settings
        $charset_of_file = isset($GLOBALS['charset_of_file'])
            ? $GLOBALS['charset_of_file'] : '';
        if (! empty($GLOBALS['asfile'])
            && isset($mysql_charset_map[$charset_of_file])
            && ! PMA_DRIZZLE
        ) {
            $foot .=  $crlf
                . '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;'
                . $crlf
                . '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;'
                . $crlf
                . '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;'
                . $crlf;
        }

        /* Restore timezone */
        if (isset($GLOBALS['sql_utc_time']) && $GLOBALS['sql_utc_time']) {
            PMA_DBI_query('SET time_zone = "' . $GLOBALS['old_tz'] . '"');
        }

        return PMA_exportOutputHandler($foot);
    }

    /**
     * Outputs export header. It is the first method to be called, so all
     * the required variables are initialized here.
     *
     * @return bool Whether it succeeded
     */
    public function exportHeader()
    {
        global $crlf, $cfg;
        global $mysql_charset_map;
        $this->setCrlf($crlf);
        $this->setCfg($cfg);
        $this->_setMysqlCharsetMap($mysql_charset_map);

        if (isset($GLOBALS['sql_compatibility'])) {
            $tmp_compat = $GLOBALS['sql_compatibility'];
            if ($tmp_compat == 'NONE') {
                $tmp_compat = '';
            }
            PMA_DBI_try_query('SET SQL_MODE="' . $tmp_compat . '"');
            unset($tmp_compat);
        }
        $head  =  $this->_exportComment('phpMyAdmin SQL Dump')
               .  $this->_exportComment('version ' . PMA_VERSION)
               .  $this->_exportComment('http://www.phpmyadmin.net')
               .  $this->_exportComment();
        $host_string = __('Host') . ': ' .  $cfg['Server']['host'];
        if (! empty($cfg['Server']['port'])) {
            $host_string .= ':' . $cfg['Server']['port'];
        }
        $head .= $this->_exportComment($host_string);
        $head .=
            $this->_exportComment(
                __('Generation Time') . ': '
                .  PMA_CommonFunctions::getInstance()->localisedDate()
            )
            .  $this->_exportComment(
                __('Server version') . ': ' . PMA_MYSQL_STR_VERSION
            )
            .  $this->_exportComment(__('PHP Version') . ': ' . phpversion())
            .  $this->_possibleCRLF();

        if (isset($GLOBALS['sql_header_comment'])
            && ! empty($GLOBALS['sql_header_comment'])
        ) {
            // '\n' is not a newline (like "\n" would be), it's the characters
            // backslash and n, as explained on the export interface
            $lines = explode('\n', $GLOBALS['sql_header_comment']);
            $head .= $this->_exportComment();
            foreach ($lines as $one_line) {
                $head .= $this->_exportComment($one_line);
            }
            $head .= $this->_exportComment();
        }

        if (isset($GLOBALS['sql_disable_fk'])) {
            $head .= 'SET FOREIGN_KEY_CHECKS=0;' . $crlf;
        }

        // We want exported AUTO_INCREMENT columns to have still same value,
        // do this only for recent MySQL exports
        if ((! isset($GLOBALS['sql_compatibility'])
            || $GLOBALS['sql_compatibility'] == 'NONE')
            && ! PMA_DRIZZLE
        ) {
            $head .= 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";' . $crlf;
        }

        if (isset($GLOBALS['sql_use_transaction'])) {
            $head .= 'SET AUTOCOMMIT = 0;' . $crlf
                   . 'START TRANSACTION;' . $crlf;
        }

        /* Change timezone if we should export timestamps in UTC */
        if (isset($GLOBALS['sql_utc_time']) && $GLOBALS['sql_utc_time']) {
            $head .= 'SET time_zone = "+00:00";' . $crlf;
            $GLOBALS['old_tz'] = PMA_DBI_fetch_value('SELECT @@session.time_zone');
            PMA_DBI_query('SET time_zone = "+00:00"');
        }

        $head .= $this->_possibleCRLF();

        if (! empty($GLOBALS['asfile']) && ! PMA_DRIZZLE) {
            // we are saving as file, therefore we provide charset information
            // so that a utility like the mysql client can interpret
            // the file correctly
            if (isset($GLOBALS['charset_of_file'])
                && isset($mysql_charset_map[$GLOBALS['charset_of_file']])
            ) {
                // we got a charset from the export dialog
                $set_names = $mysql_charset_map[$GLOBALS['charset_of_file']];
            } else {
                // by default we use the connection charset
                $set_names = $mysql_charset_map['utf-8'];
            }
            $head .=  $crlf
                . '/*!40101 SET @OLD_CHARACTER_SET_CLIENT='
                . '@@CHARACTER_SET_CLIENT */;' . $crlf
                . '/*!40101 SET @OLD_CHARACTER_SET_RESULTS='
                . '@@CHARACTER_SET_RESULTS */;' . $crlf
                . '/*!40101 SET @OLD_COLLATION_CONNECTION='
                . '@@COLLATION_CONNECTION */;'. $crlf
                . '/*!40101 SET NAMES ' . $set_names . ' */;' . $crlf . $crlf;
        }

        return PMA_exportOutputHandler($head);
    }

    /**
     * Outputs CREATE DATABASE statement
     *
     * @param string $db Database name
     *
     * @return bool Whether it succeeded
     */
    public function exportDBCreate($db)
    {
        global $crlf;
        
        $common_functions = PMA_CommonFunctions::getInstance();
        $this->setCrlf($crlf);
        
        if (isset($GLOBALS['sql_drop_database'])) {
            if (! PMA_exportOutputHandler(
                'DROP DATABASE '
                . (isset($GLOBALS['sql_backquotes'])
                ? $common_functions->backquote($db) : $db)
                . ';' . $crlf
            )) {
                return false;
            }
        }
        $create_query = 'CREATE DATABASE '
            . (isset($GLOBALS['sql_backquotes']) ? $common_functions->backquote($db) : $db);
        $collation = PMA_getDbCollation($db);
        if (PMA_DRIZZLE) {
            $create_query .= ' COLLATE ' . $collation;
        } else {
            if (strpos($collation, '_')) {
                $create_query .= ' DEFAULT CHARACTER SET '
                    . substr($collation, 0, strpos($collation, '_'))
                    . ' COLLATE ' . $collation;
            } else {
                $create_query .= ' DEFAULT CHARACTER SET ' . $collation;
            }
        }
        $create_query .= ';' . $crlf;
        if (! PMA_exportOutputHandler($create_query)) {
            return false;
        }
        if (isset($GLOBALS['sql_backquotes'])
            && ((isset($GLOBALS['sql_compatibility'])
            && $GLOBALS['sql_compatibility'] == 'NONE')
            || PMA_DRIZZLE)
        ) {
            $result = PMA_exportOutputHandler(
                'USE ' . $common_functions->backquote($db) . ';' . $crlf
            );
        } else {
            $result = PMA_exportOutputHandler('USE ' . $db . ';' . $crlf);
        }

        return $result;
    }

    /**
     * Outputs database header
     *
     * @param string $db Database name
     *
     * @return bool Whether it succeeded
     */
    public function exportDBHeader($db)
    {
        $head = $this->_exportComment()
            . $this->_exportComment(
                __('Database') . ': '
                . (isset($GLOBALS['sql_backquotes'])
                ? PMA_CommonFunctions::getInstance()->backquote($db) : '\'' . $db . '\'')
            )
            . $this->_exportComment();
        return PMA_exportOutputHandler($head);
    }

    /**
     * Outputs database footer
     *
     * @param string $db Database name
     *
     * @return bool Whether it succeeded
     */
    public function exportDBFooter($db)
    {
        global $crlf;
        $this->setCrlf($crlf);

        $common_functions = PMA_CommonFunctions::getInstance();
        $result = true;
        if (isset($GLOBALS['sql_constraints'])) {
            $result = PMA_exportOutputHandler($GLOBALS['sql_constraints']);
            unset($GLOBALS['sql_constraints']);
        }

        if (($GLOBALS['sql_structure_or_data'] == 'structure'
            || $GLOBALS['sql_structure_or_data'] == 'structure_and_data')
            && isset($GLOBALS['sql_procedure_function'])
        ) {
            $text = '';
            $delimiter = '$$';

            if (PMA_MYSQL_INT_VERSION > 50100) {
                $event_names = PMA_DBI_fetch_result(
                    'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE'
                    . ' EVENT_SCHEMA= \''
                    . $common_functions->sqlAddSlashes($db, true)
                    . '\';'
                );
            } else {
                $event_names = array();
            }

            if ($event_names) {
                $text .= $crlf
                  . 'DELIMITER ' . $delimiter . $crlf;

                $text .=
                    $this->_exportComment()
                    . $this->_exportComment(__('Events'))
                    . $this->_exportComment();

                foreach ($event_names as $event_name) {
                    if (! empty($GLOBALS['sql_drop_table'])) {
                        $text .= 'DROP EVENT ' . $common_functions->backquote($event_name)
                            . $delimiter . $crlf;
                    }
                    $text .= PMA_DBI_get_definition($db, 'EVENT', $event_name)
                        . $delimiter . $crlf . $crlf;
                }

                $text .= 'DELIMITER ;' . $crlf;
            }

            if (! empty($text)) {
                $result = PMA_exportOutputHandler($text);
            }
        }
        return $result;
    }

    /**
     * Returns a stand-in CREATE definition to resolve view dependencies
     *
     * @param string $db   the database name
     * @param string $view the view name
     * @param string $crlf the end of line sequence
     *
     * @return string resulting definition
     */
    public function getTableDefStandIn($db, $view, $crlf)
    {
        
        $common_functions = PMA_CommonFunctions::getInstance();
        $create_query = '';
        if (! empty($GLOBALS['sql_drop_table'])) {
            $create_query .= 'DROP VIEW IF EXISTS ' . $common_functions->backquote($view)
                . ';' . $crlf;
        }

        $create_query .= 'CREATE TABLE ';

        if (isset($GLOBALS['sql_if_not_exists'])
            && $GLOBALS['sql_if_not_exists']
        ) {
            $create_query .= 'IF NOT EXISTS ';
        }
        $create_query .= $common_functions->backquote($view) . ' (' . $crlf;
        $tmp = array();
        $columns = PMA_DBI_get_columns_full($db, $view);
        foreach ($columns as $column_name => $definition) {
            $tmp[] = $common_functions->backquote($column_name) . ' ' . $definition['Type'] . $crlf;
        }
        $create_query .= implode(',', $tmp) . ');';
        return($create_query);
    }

    /**
     * Returns $table's CREATE definition
     *
     * @param string $db            the database name
     * @param string $table         the table name
     * @param string $crlf          the end of line sequence
     * @param string $error_url     the url to go back in case of error
     * @param bool   $show_dates    whether to include creation/update/check dates
     * @param bool   $add_semicolon whether to add semicolon and end-of-line at
     *                              the end
     * @param bool   $view          whether we're handling a view
     *
     * @return string resulting schema
     */
    public function getTableDef(
        $db,
        $table,
        $crlf,
        $error_url,
        $show_dates = false,
        $add_semicolon = true,
        $view = false
    ) {
        $this->initSpecificVariables();
        
        $sql_drop_table = $this->_getSqlDropTable();
        $sql_backquotes = $this->_getSqlBackquotes();
        $sql_constraints = $this->_getSqlConstraints();
        $sql_constraints_query = $this->_getSqlConstraintsQuery();
        $sql_drop_foreign_keys = $this->_getSqlDropForeignKeys();

        $common_functions = PMA_CommonFunctions::getInstance();
        $schema_create = '';
        $auto_increment = '';
        $new_crlf = $crlf;

        // need to use PMA_DBI_QUERY_STORE with PMA_DBI_num_rows() in mysqli
        $result = PMA_DBI_query(
            'SHOW TABLE STATUS FROM ' . $common_functions->backquote($db) . ' LIKE \''
            . $common_functions->sqlAddSlashes($table, true) . '\'',
            null,
            PMA_DBI_QUERY_STORE
        );
        if ($result != false) {
            if (PMA_DBI_num_rows($result) > 0) {
                $tmpres = PMA_DBI_fetch_assoc($result);
                if (PMA_DRIZZLE && $show_dates) {
                    // Drizzle doesn't give Create_time and Update_time in
                    // SHOW TABLE STATUS, add it
                    $sql ="SELECT
                            TABLE_CREATION_TIME AS Create_time,
                            TABLE_UPDATE_TIME AS Update_time
                        FROM data_dictionary.TABLES
                        WHERE TABLE_SCHEMA = '" . $common_functions->sqlAddSlashes($db) . "'
                          AND TABLE_NAME = '" . $common_functions->sqlAddSlashes($table) . "'";
                    $tmpres = array_merge(PMA_DBI_fetch_single_row($sql), $tmpres);
                }
                // Here we optionally add the AUTO_INCREMENT next value,
                // but starting with MySQL 5.0.24, the clause is already included
                // in SHOW CREATE TABLE so we'll remove it below
                // It's required for Drizzle because SHOW CREATE TABLE uses
                // the value from table's creation time
                if (isset($GLOBALS['sql_auto_increment'])
                    && ! empty($tmpres['Auto_increment'])
                ) {
                    $auto_increment .= ' AUTO_INCREMENT='
                        . $tmpres['Auto_increment'] . ' ';
                }

                if ($show_dates
                    && isset($tmpres['Create_time'])
                    && ! empty($tmpres['Create_time'])
                ) {
                    $schema_create .= $this->_exportComment(
                        __('Creation') . ': '
                        . $common_functions->localisedDate(
                            strtotime($tmpres['Create_time'])
                        )
                    );
                    $new_crlf = $this->_exportComment() . $crlf;
                }

                if ($show_dates
                    && isset($tmpres['Update_time'])
                    && ! empty($tmpres['Update_time'])
                ) {
                    $schema_create .= $this->_exportComment(
                        __('Last update') . ': '
                        . $common_functions->localisedDate(
                            strtotime($tmpres['Update_time'])
                        )
                    );
                    $new_crlf = $this->_exportComment() . $crlf;
                }

                if ($show_dates
                    && isset($tmpres['Check_time'])
                    && ! empty($tmpres['Check_time'])
                ) {
                    $schema_create .= $this->_exportComment(
                        __('Last check') . ': '
                        . $common_functions->localisedDate(
                            strtotime($tmpres['Check_time'])
                        )
                    );
                    $new_crlf = $this->_exportComment() . $crlf;
                }
            }
            PMA_DBI_free_result($result);
        }

        $schema_create .= $new_crlf;

        // no need to generate a DROP VIEW here, it was done earlier
        if (! empty($sql_drop_table) && ! PMA_Table::isView($db, $table)) {
            $schema_create .= 'DROP TABLE IF EXISTS '
                . $common_functions->backquote($table, $sql_backquotes) . ';' . $crlf;
        }

        // Complete table dump,
        // Whether to quote table and column names or not
        // Drizzle always quotes names
        if (! PMA_DRIZZLE) {
            if ($sql_backquotes) {
                PMA_DBI_query('SET SQL_QUOTE_SHOW_CREATE = 1');
            } else {
                PMA_DBI_query('SET SQL_QUOTE_SHOW_CREATE = 0');
            }
        }

        // I don't see the reason why this unbuffered query could cause problems,
        // because SHOW CREATE TABLE returns only one row, and we free the
        // results below. Nonetheless, we got 2 user reports about this
        // (see bug 1562533) so I removed the unbuffered mode.
        // $result = PMA_DBI_query('SHOW CREATE TABLE ' . backquote($db)
        // . '.' . backquote($table), null, PMA_DBI_QUERY_UNBUFFERED);
        //
        // Note: SHOW CREATE TABLE, at least in MySQL 5.1.23, does not
        // produce a displayable result for the default value of a BIT
        // column, nor does the mysqldump command. See MySQL bug 35796
        $result = PMA_DBI_try_query(
            'SHOW CREATE TABLE ' . $common_functions->backquote($db) . '.' . $common_functions->backquote($table)
        );
        // an error can happen, for example the table is crashed
        $tmp_error = PMA_DBI_getError();
        if ($tmp_error) {
            return $this->_exportComment(__('in use') . '(' . $tmp_error . ')');
        }

        if ($result != false && ($row = PMA_DBI_fetch_row($result))) {
            $create_query = $row[1];
            unset($row);

            // Convert end of line chars to one that we want (note that MySQL
            // doesn't return query it will accept in all cases)
            if (strpos($create_query, "(\r\n ")) {
                $create_query = str_replace("\r\n", $crlf, $create_query);
            } elseif (strpos($create_query, "(\n ")) {
                $create_query = str_replace("\n", $crlf, $create_query);
            } elseif (strpos($create_query, "(\r ")) {
                $create_query = str_replace("\r", $crlf, $create_query);
            }

            /*
             * Drop database name from VIEW creation.
             *
             * This is a bit tricky, but we need to issue SHOW CREATE TABLE with
             * database name, but we don't want name to show up in CREATE VIEW
             * statement.
             */
            if ($view) {
                $create_query = preg_replace(
                    '/' . $common_functions->backquote($db) . '\./',
                    '',
                    $create_query
                );
            }

            // Should we use IF NOT EXISTS?
            if (isset($GLOBALS['sql_if_not_exists'])) {
                $create_query = preg_replace(
                    '/^CREATE TABLE/',
                    'CREATE TABLE IF NOT EXISTS',
                    $create_query
                );
            }

            // Drizzle (checked on 2011.03.13) returns ROW_FORMAT surrounded
            // with quotes, which is not accepted by parser
            if (PMA_DRIZZLE) {
                $create_query = preg_replace(
                    '/ROW_FORMAT=\'(\S+)\'/',
                    'ROW_FORMAT=$1',
                    $create_query
                );
            }

            // are there any constraints to cut out?
            if (preg_match('@CONSTRAINT|FOREIGN[\s]+KEY@', $create_query)) {

                // Split the query into lines, so we can easily handle it.
                // We know lines are separated by $crlf (done few lines above).
                $sql_lines = explode($crlf, $create_query);
                $sql_count = count($sql_lines);

                // lets find first line with constraints
                for ($i = 0; $i < $sql_count; $i++) {
                    if (preg_match(
                        '@^[\s]*(CONSTRAINT|FOREIGN[\s]+KEY)@',
                        $sql_lines[$i]
                    )) {
                        break;
                    }
                }

                // If we really found a constraint
                if ($i != $sql_count) {

                    // remove, from the end of create statement
                    $sql_lines[$i - 1] = preg_replace(
                        '@,$@',
                        '',
                        $sql_lines[$i - 1]
                    );

                    // prepare variable for constraints
                    if (! isset($sql_constraints)) {
                        if (isset($GLOBALS['no_constraints_comments'])) {
                            $sql_constraints = '';
                        } else {
                            $sql_constraints = $crlf
                                . $this->_exportComment()
                                . $this->_exportComment(
                                    __('Constraints for dumped tables')
                                )
                                . $this->_exportComment();
                        }
                    }

                    // comments for current table
                    if (! isset($GLOBALS['no_constraints_comments'])) {
                        $sql_constraints .= $crlf
                        . $this->_exportComment()
                        . $this->_exportComment(
                            __('Constraints for table')
                            . ' '
                            . $common_functions->backquote($table)
                        )
                        . $this->_exportComment();
                    }

                    // let's do the work
                    $sql_constraints_query .= 'ALTER TABLE '
                        . $common_functions->backquote($table) . $crlf;
                    $sql_constraints .= 'ALTER TABLE '
                        . $common_functions->backquote($table) . $crlf;
                    $sql_drop_foreign_keys .= 'ALTER TABLE '
                        . $common_functions->backquote($db) . '.'
                        . $common_functions->backquote($table) . $crlf;

                    $first = true;
                    for ($j = $i; $j < $sql_count; $j++) {
                        if (preg_match(
                            '@CONSTRAINT|FOREIGN[\s]+KEY@',
                            $sql_lines[$j]
                        )) {
                            if (! $first) {
                                $sql_constraints .= $crlf;
                            }
                            if (strpos($sql_lines[$j], 'CONSTRAINT') === false) {
                                $tmp_str = preg_replace(
                                    '/(FOREIGN[\s]+KEY)/',
                                    'ADD \1',
                                    $sql_lines[$j]
                                );
                                $sql_constraints_query .= $tmp_str;
                                $sql_constraints .= $tmp_str;
                            } else {
                                $tmp_str = preg_replace(
                                    '/(CONSTRAINT)/',
                                    'ADD \1',
                                    $sql_lines[$j]
                                );
                                $sql_constraints_query .= $tmp_str;
                                $sql_constraints .= $tmp_str;
                                preg_match(
                                    '/(CONSTRAINT)([\s])([\S]*)([\s])/',
                                    $sql_lines[$j],
                                    $matches
                                );
                                if (! $first) {
                                    $sql_drop_foreign_keys .= ', ';
                                }
                                $sql_drop_foreign_keys .= 'DROP FOREIGN KEY '
                                    . $matches[3];
                            }
                            $first = false;
                        } else {
                            break;
                        }
                    }
                    $sql_constraints .= ';' . $crlf;
                    $sql_constraints_query .= ';';

                    $create_query = implode(
                        $crlf,
                        array_slice($sql_lines, 0, $i)
                    )
                    . $crlf
                    . implode(
                        $crlf,
                        array_slice($sql_lines, $j, $sql_count - 1)
                    );
                    unset($sql_lines);
                }
            }
            $schema_create .= $create_query;
        }

        // remove a possible "AUTO_INCREMENT = value" clause
        // that could be there starting with MySQL 5.0.24
        // in Drizzle it's useless as it contains the value given at table
        // creation time
        $schema_create = preg_replace(
            '/AUTO_INCREMENT\s*=\s*([0-9])+/',
            '',
            $schema_create
        );

        $schema_create .= $auto_increment;

        PMA_DBI_free_result($result);
        return $schema_create . ($add_semicolon ? ';' . $crlf : '');
    } // end of the 'getTableDef()' function

    /**
     * Returns $table's comments, relations etc.
     *
     * @param string $db          database name
     * @param string $table       table name
     * @param string $crlf        end of line sequence
     * @param bool   $do_relation whether to include relation comments
     * @param bool   $do_mime     whether to include mime comments
     *
     * @return string resulting comments
     */
    private function _getTableComments(
        $db,
        $table,
        $crlf,
        $do_relation = false,
        $do_mime = false
    ) {
        global $cfgRelation;

        $common_functions = PMA_CommonFunctions::getInstance();
        $this->setCfgRelation($cfgRelation);
        $sql_backquotes = $this->_getSqlBackquotes();

        $schema_create = '';

        // Check if we can use Relations
        if ($do_relation && ! empty($cfgRelation['relation'])) {
            // Find which tables are related with the current one and write it in
            // an array
            $res_rel = PMA_getForeigners($db, $table);

            if ($res_rel && count($res_rel) > 0) {
                $have_rel = true;
            } else {
                $have_rel = false;
            }
        } else {
               $have_rel = false;
        } // end if

        if ($do_mime && $cfgRelation['mimework']) {
            if (! ($mime_map = PMA_getMIME($db, $table, true))) {
                unset($mime_map);
            }
        }

        if (isset($mime_map) && count($mime_map) > 0) {
            $schema_create .= $this->_possibleCRLF()
            . $this->_exportComment()
            . $this->_exportComment(
                __('MIME TYPES FOR TABLE'). ' '
                . $common_functions->backquote($table, $sql_backquotes) . ':'
            );
            @reset($mime_map);
            foreach ($mime_map AS $mime_field => $mime) {
                $schema_create .=
                    $this->_exportComment(
                        '  '
                        . $common_functions->backquote($mime_field, $sql_backquotes)
                    )
                    . $this->_exportComment(
                        '      '
                        . $common_functions->backquote($mime['mimetype'], $sql_backquotes)
                    );
            }
            $schema_create .= $this->_exportComment();
        }

        if ($have_rel) {
            $schema_create .= $this->_possibleCRLF()
                . $this->_exportComment()
                . $this->_exportComment(
                    __('RELATIONS FOR TABLE') . ' '
                    . $common_functions->backquote($table, $sql_backquotes)
                    . ':'
                );
            foreach ($res_rel AS $rel_field => $rel) {
                $schema_create .=
                    $this->_exportComment(
                        '  '
                        . $common_functions->backquote($rel_field, $sql_backquotes)
                    )
                    . $this->_exportComment(
                        '      '
                        . $common_functions->backquote($rel['foreign_table'], $sql_backquotes)
                        . ' -> '
                        . $common_functions->backquote($rel['foreign_field'], $sql_backquotes)
                    );
            }
            $schema_create .= $this->_exportComment();
        }

        return $schema_create;

    } // end of the '_getTableComments()' function

    /**
     * Outputs table's structure
     *
     * @param string $db          database name
     * @param string $table       table name
     * @param string $crlf        the end of line sequence
     * @param string $error_url   the url to go back in case of error
     * @param string $export_mode 'create_table','triggers','create_view',
     *                            'stand_in'
     * @param string $export_type 'server', 'database', 'table'
     * @param bool   $relation    whether to include relation comments
     * @param bool   $comments    whether to include the pmadb-style column comments
     *                            as comments in the structure; this is deprecated
     *                            but the parameter is left here because export.php
     *                            calls exportStructure() also for other export
     *                            types which use this parameter
     * @param bool   $mime        whether to include mime comments
     * @param bool   $dates       whether to include creation/update/check dates
     *
     * @return bool Whether it succeeded
     */
    public function exportStructure(
        $db,
        $table,
        $crlf,
        $error_url,
        $export_mode,
        $export_type,
        $relation = false,
        $comments = false,
        $mime = false,
        $dates = false
    ) {
        
        $common_functions = PMA_CommonFunctions::getInstance();
        
        $formatted_table_name = (isset($GLOBALS['sql_backquotes']))
            ? $common_functions->backquote($table) : '\'' . $table . '\'';
        $dump = $this->_possibleCRLF()
            . $this->_exportComment(str_repeat('-', 56))
            . $this->_possibleCRLF()
            . $this->_exportComment();

        switch($export_mode) {
        case 'create_table':
            $dump .= $this->_exportComment(
                __('Table structure for table') . ' '. $formatted_table_name
            );
            $dump .= $this->_exportComment();
            $dump .= $this->getTableDef($db, $table, $crlf, $error_url, $dates);
            $dump .= $this->_getTableComments($db, $table, $crlf, $relation, $mime);
            break;
        case 'triggers':
            $dump = '';
            $triggers = PMA_DBI_get_triggers($db, $table);
            if ($triggers) {
                $dump .=  $this->_possibleCRLF()
                    . $this->_exportComment()
                    . $this->_exportComment(
                        __('Triggers') . ' ' . $formatted_table_name
                    )
                    . $this->_exportComment();
                $delimiter = '//';
                foreach ($triggers as $trigger) {
                    $dump .= $trigger['drop'] . ';' . $crlf;
                    $dump .= 'DELIMITER ' . $delimiter . $crlf;
                    $dump .= $trigger['create'];
                    $dump .= 'DELIMITER ;' . $crlf;
                }
            }
            break;
        case 'create_view':
            $dump .=
                $this->_exportComment(
                    __('Structure for view')
                    . ' '
                    . $formatted_table_name
                )
                . $this->_exportComment();
            // delete the stand-in table previously created (if any)
            if ($export_type != 'table') {
                $dump .= 'DROP TABLE IF EXISTS '
                    . $common_functions->backquote($table) . ';' . $crlf;
            }
            $dump .= $this->getTableDef(
                $db, $table, $crlf, $error_url, $dates, true, true
            );
            break;
        case 'stand_in':
            $dump .=
                $this->_exportComment(
                    __('Stand-in structure for view') . ' ' . $formatted_table_name
                )
                . $this->_exportComment();
            // export a stand-in definition to resolve view dependencies
            $dump .= getTableDefStandIn($db, $table, $crlf);
        } // end switch

        // this one is built by getTableDef() to use in table copy/move
        // but not in the case of export
        unset($GLOBALS['sql_constraints_query']);

        return PMA_exportOutputHandler($dump);
    }

    /**
     * Outputs the content of a table in SQL format
     *
     * @param string $db        database name
     * @param string $table     table name
     * @param string $crlf      the end of line sequence
     * @param string $error_url the url to go back in case of error
     * @param string $sql_query SQL query for obtaining data
     *
     * @return bool Whether it succeeded
     */
    public function exportData($db, $table, $crlf, $error_url, $sql_query)
    {
        global $current_row;
        $this->_setCurrentRow($current_row);
        $sql_backquotes = $this->_getSqlBackquotes();

        $common_functions = PMA_CommonFunctions::getInstance();
        $formatted_table_name = (isset($GLOBALS['sql_backquotes']))
            ? $common_functions->backquote($table)
            : '\'' . $table . '\'';

        // Do not export data for a VIEW
        // (For a VIEW, this is called only when exporting a single VIEW)
        if (PMA_Table::isView($db, $table)) {
            $head = $this->_possibleCRLF()
              . $this->_exportComment()
              . $this->_exportComment('VIEW ' . ' ' . $formatted_table_name)
              . $this->_exportComment(__('Data') . ': ' . __('None'))
              . $this->_exportComment()
              . $this->_possibleCRLF();

            if (! PMA_exportOutputHandler($head)) {
                return false;
            }
            return true;
        }

        // analyze the query to get the true column names, not the aliases
        // (this fixes an undefined index, also if Complete inserts
        //  are used, we did not get the true column name in case of aliases)
        $analyzed_sql = PMA_SQP_analyze(PMA_SQP_parse($sql_query));

        $result = PMA_DBI_try_query($sql_query, null, PMA_DBI_QUERY_UNBUFFERED);
        // a possible error: the table has crashed
        $tmp_error = PMA_DBI_getError();
        if ($tmp_error) {
            return PMA_exportOutputHandler(
                $this->_exportComment(
                    __('Error reading data:') . ' (' . $tmp_error . ')'
                )
            );
        }

        if ($result != false) {
            $fields_cnt = PMA_DBI_num_fields($result);

            // Get field information
            $fields_meta = PMA_DBI_get_fields_meta($result);
            $field_flags = array();
            for ($j = 0; $j < $fields_cnt; $j++) {
                $field_flags[$j] = PMA_DBI_field_flags($result, $j);
            }

            for ($j = 0; $j < $fields_cnt; $j++) {
                if (isset($analyzed_sql[0]['select_expr'][$j]['column'])) {
                    $field_set[$j] = $common_functions->backquote(
                        $analyzed_sql[0]['select_expr'][$j]['column'],
                        $sql_backquotes
                    );
                } else {
                    $field_set[$j] = $common_functions->backquote(
                        $fields_meta[$j]->name,
                        $sql_backquotes
                    );
                }
            }

            if (isset($GLOBALS['sql_type'])
                && $GLOBALS['sql_type'] == 'UPDATE'
            ) {
                // update
                $schema_insert  = 'UPDATE ';
                if (isset($GLOBALS['sql_ignore'])) {
                    $schema_insert .= 'IGNORE ';
                }
                // avoid EOL blank
                $schema_insert .= $common_functions->backquote($table, $sql_backquotes) . ' SET';
            } else {
                // insert or replace
                if (isset($GLOBALS['sql_type'])
                    && $GLOBALS['sql_type'] == 'REPLACE'
                ) {
                    $sql_command = 'REPLACE';
                } else {
                    $sql_command = 'INSERT';
                }

                // delayed inserts?
                if (isset($GLOBALS['sql_delayed'])) {
                    $insert_delayed = ' DELAYED';
                } else {
                    $insert_delayed = '';
                }

                // insert ignore?
                if (isset($GLOBALS['sql_type'])
                    && $GLOBALS['sql_type'] == 'INSERT'
                    && isset($GLOBALS['sql_ignore'])
                ) {
                    $insert_delayed .= ' IGNORE';
                }
                //truncate table before insert
                if (isset($GLOBALS['sql_truncate'])
                    && $GLOBALS['sql_truncate']
                    && $sql_command == 'INSERT'
                ) {
                    $truncate = 'TRUNCATE TABLE '
                        . $common_functions->backquote($table, $sql_backquotes) . ";";
                    $truncatehead = $this->_possibleCRLF()
                        . $this->_exportComment()
                        . $this->_exportComment(
                            __('Truncate table before insert') . ' '
                            . $formatted_table_name
                        )
                        . $this->_exportComment()
                        . $crlf;
                    PMA_exportOutputHandler($truncatehead);
                    PMA_exportOutputHandler($truncate);
                } else {
                    $truncate = '';
                }
                // scheme for inserting fields
                if ($GLOBALS['sql_insert_syntax'] == 'complete'
                    || $GLOBALS['sql_insert_syntax'] == 'both'
                ) {
                    $fields        = implode(', ', $field_set);
                    $schema_insert = $sql_command . $insert_delayed .' INTO '
                        . $common_functions->backquote($table, $sql_backquotes)
                        // avoid EOL blank
                        . ' (' . $fields . ') VALUES';
                } else {
                    $schema_insert = $sql_command . $insert_delayed .' INTO '
                        . $common_functions->backquote($table, $sql_backquotes)
                        . ' VALUES';
                }
            }

            //\x08\\x09, not required
            $search      = array("\x00", "\x0a", "\x0d", "\x1a");
            $replace     = array('\0', '\n', '\r', '\Z');
            $current_row = 0;
            $query_size  = 0;
            if (($GLOBALS['sql_insert_syntax'] == 'extended'
                || $GLOBALS['sql_insert_syntax'] == 'both')
                && (! isset($GLOBALS['sql_type'])
                || $GLOBALS['sql_type'] != 'UPDATE')
            ) {
                $separator      = ',';
                $schema_insert .= $crlf;
            } else {
                $separator      = ';';
            }

            while ($row = PMA_DBI_fetch_row($result)) {
                if ($current_row == 0) {
                    $head = $this->_possibleCRLF()
                        . $this->_exportComment()
                        . $this->_exportComment(
                            __('Dumping data for table') . ' '
                            . $formatted_table_name
                        )
                        . $this->_exportComment()
                        . $crlf;
                    if (! PMA_exportOutputHandler($head)) {
                        return false;
                    }
                }
                $current_row++;
                for ($j = 0; $j < $fields_cnt; $j++) {
                    // NULL
                    if (! isset($row[$j]) || is_null($row[$j])) {
                        $values[] = 'NULL';
                    } elseif ($fields_meta[$j]->numeric
                        && $fields_meta[$j]->type != 'timestamp'
                        && ! $fields_meta[$j]->blob
                    ) {
                        // a number
                        // timestamp is numeric on some MySQL 4.1, BLOBs are
                        // sometimes numeric
                        $values[] = $row[$j];
                    } elseif (stristr($field_flags[$j], 'BINARY')
                        && $fields_meta[$j]->blob
                        && isset($GLOBALS['sql_hex_for_blob'])
                    ) {
                        // a true BLOB
                        // - mysqldump only generates hex data when the --hex-blob
                        //   option is used, for fields having the binary attribute
                        //   no hex is generated
                        // - a TEXT field returns type blob but a real blob
                        //   returns also the 'binary' flag

                        // empty blobs need to be different, but '0' is also empty
                        // :-(
                        if (empty($row[$j]) && $row[$j] != '0') {
                            $values[] = '\'\'';
                        } else {
                            $values[] = '0x' . bin2hex($row[$j]);
                        }
                    } elseif ($fields_meta[$j]->type == 'bit') {
                        // detection of 'bit' works only on mysqli extension
                        $values[] = "b'" . $common_functions->sqlAddSlashes(
                            $common_functions->printableBitValue(
                                $row[$j], $fields_meta[$j]->length
                            )
                        )
                            . "'";
                    } else {
                        // something else -> treat as a string
                        $values[] = '\''
                            . str_replace(
                                $search, $replace, $common_functions->sqlAddSlashes($row[$j])
                            )
                            . '\'';
                    } // end if
                } // end for

                // should we make update?
                if (isset($GLOBALS['sql_type'])
                    && $GLOBALS['sql_type'] == 'UPDATE'
                ) {

                    $insert_line = $schema_insert;
                    for ($i = 0; $i < $fields_cnt; $i++) {
                        if (0 == $i) {
                            $insert_line .= ' ';
                        }
                        if ($i > 0) {
                            // avoid EOL blank
                            $insert_line .= ',';
                        }
                        $insert_line .= $field_set[$i] . ' = ' . $values[$i];
                    }

                    list($tmp_unique_condition, $tmp_clause_is_unique)
                        = $common_functions->getUniqueCondition(
                            $result,
                            $fields_cnt,
                            $fields_meta,
                            $row
                        );
                    $insert_line .= ' WHERE ' . $tmp_unique_condition;
                    unset($tmp_unique_condition, $tmp_clause_is_unique);

                } else {

                    // Extended inserts case
                    if ($GLOBALS['sql_insert_syntax'] == 'extended'
                        || $GLOBALS['sql_insert_syntax'] == 'both'
                    ) {
                        if ($current_row == 1) {
                            $insert_line  = $schema_insert . '('
                                . implode(', ', $values) . ')';
                        } else {
                            $insert_line  = '(' . implode(', ', $values) . ')';
                            $sql_max_size = $GLOBALS['sql_max_query_size'];
                            if (isset($sql_max_size)
                                && $sql_max_size > 0
                                && $query_size + strlen($insert_line) > $sql_max_size
                            ) {
                                if (! PMA_exportOutputHandler(';' . $crlf)) {
                                    return false;
                                }
                                $query_size  = 0;
                                $current_row = 1;
                                $insert_line = $schema_insert . $insert_line;
                            }
                        }
                        $query_size += strlen($insert_line);
                        // Other inserts case
                    } else {
                        $insert_line = $schema_insert
                            . '('
                            . implode(', ', $values)
                            . ')';
                    }
                }
                unset($values);

                if (! PMA_exportOutputHandler(
                    ($current_row == 1 ? '' : $separator . $crlf)
                    . $insert_line
                )) {
                    return false;
                }

            } // end while
            if ($current_row > 0) {
                if (! PMA_exportOutputHandler(';' . $crlf)) {
                    return false;
                }
            }
        } // end if ($result != false)
        PMA_DBI_free_result($result);

        return true;
    } // end of the 'exportData()' function


    /* ~~~~~~~~~~~~~~~~~~~~ Getters and Setters ~~~~~~~~~~~~~~~~~~~~ */

    /**
     * Gets the MySQL charset map
     *
     * @return array
     */
    private function _getMysqlCharsetMap()
    {
        return $this->_mysqlCharsetMap;
    }

    /**
     * Sets the MySQL charset map
     *
     * @param string $mysqlCharsetMap file charset
     *
     * @return void
     */
    private function _setMysqlCharsetMap($mysqlCharsetMap)
    {
        $this->_mysqlCharsetMap = $mysqlCharsetMap;
    }

    /**
     * Gets the SQL for dropping a table
     *
     * @return string
     */
    private function _getSqlDropTable()
    {
        return $this->_sqlDropTable;
    }

    /**
     * Sets the SQL for dropping a table
     *
     * @param string $sqlDropTable SQL for dropping a table
     *
     * @return void
     */
    private function _setSqlDropTable($sqlDropTable)
    {
        $this->_sqlDropTable = $sqlDropTable;
    }

    /**
     * Gets the SQL Backquotes
     *
     * @return bool
     */
    private function _getSqlBackquotes()
    {
        return $this->_sqlBackquotes;
    }

    /**
     * Sets the SQL Backquotes
     *
     * @param string $sqlBackquotes SQL Backquotes
     *
     * @return void
     */
    private function _setSqlBackquotes($sqlBackquotes)
    {
        $this->_sqlBackquotes = $sqlBackquotes;
    }

    /**
     * Gets the SQL Constraints
     *
     * @return void
     */
    private function _getSqlConstraints()
    {
        return $this->_sqlConstraints;
    }

    /**
     * Sets the SQL Constraints
     *
     * @param string $sqlConstraints SQL Constraints
     *
     * @return void
     */
    private function _setSqlConstraints($sqlConstraints)
    {
        $this->_sqlConstraints = $sqlConstraints;
    }

    /**
     * Gets the text of the SQL constraints query
     *
     * @return void
     */
    private function _getSqlConstraintsQuery()
    {
        return $this->_sqlConstraintsQuery;
    }

    /**
     * Sets the text of the SQL constraints query
     *
     * @param string $sqlConstraintsQuery text of the SQL constraints query
     *
     * @return void
     */
    private function _setSqlConstraintsQuery($sqlConstraintsQuery)
    {
        $this->_sqlConstraintsQuery = $sqlConstraintsQuery;
    }

    /**
     * Gets the SQL for dropping foreign keys
     *
     * @return void
     */
    private function _getSqlDropForeignKeys()
    {
        return $this->_sqlDropForeignKeys;
    }

    /**
     * Sets the SQL SQL for dropping foreign keys
     *
     * @param string $sqlDropForeignKeys SQL for dropping foreign keys
     *
     * @return void
     */
    private function _setSqlDropForeignKeys($sqlDropForeignKeys)
    {
        $this->_sqlDropForeignKeys = $sqlDropForeignKeys;
    }

    /**
     * The number of the current row
     *
     * @return int
     */
    private function _getCurrentRow()
    {
        return $this->_currentRow;
    }

    /**
     * Sets the number of the current row
     *
     * @param string $currentRow number of the current row
     *
     * @return void
     */
    private function _setCurrentRow($currentRow)
    {
        $this->_currentRow = $currentRow;
    }
}