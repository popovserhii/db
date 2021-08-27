<?php
/**
 * The MIT License (MIT)
 * Copyright (c) 2021 Serhii Popov
 * This source file is subject to The MIT License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @category Popov
 * @package Popov_Dumper
 * @author Serhii Popov <popow.serhii@gmail.com>
 * @license https://opensource.org/licenses/MIT The MIT License (MIT)
 */

namespace Popov\Db;

class QueryMaker
{
    /**
     * Mode for INSERT/UPDATE
     *
     * @var string
     */
    private $_updateMode = QueryMaker::TEMPLATES_UNNAMED;

    /**
     * Named template.
     * The value corresponds to the name of the method that will be invoked to convert
     *
     * @var string
     */
    const TEMPLATES_NAMED = 'createTemplatesNamed';

    /**
     * Unnamed template.
     * The value corresponds to the name of the method that will be invoked to convert
     *
     * @var string
     */
    const TEMPLATES_UNNAMED = 'createTemplatesUnnamed';

    /**
     * Lack of templates - a direct path to SQL-injection.
     * The value corresponds to the name of the method that will be invoked to convert
     *
     * @var string
     */
    const TEMPLATES_WITHOUT = 'createTemplatesWithout';

    /**
     * @var Db 
     */
    protected $db;
    
    public function __construct(Db $db)
    {
        $this->db = $db;
    }
    
    /**
     * Set the type INSERT/UPDATE.
     * Available options, see. const TEMPLATE_*
     *
     * @param string $method
     */
    public function setSaveMode($method)
    {
        $this->_updateMode = $method;

        return $this;
    }

    /**
     * Get type to INSERT/UPDATE.
     * Available options, see. const TEMPLATE_*.
     * Default returns TEMPLATES_UNNAMED
     *
     * @return string
     */
    public function getSaveMode()
    {
        return $this->_updateMode;
    }

    /**
     * @param $table
     * @param $fields
     * @param string $idField
     *
     * @return string
     */
    public function insert($table, $fields, $idField = 'id')
    {
        $fields = $this->isMulti($fields) ? $fields : [$fields];
        return "INSERT INTO `{$table}` ( {$this->addFields($fields[0])} ) VALUES {$this->addValues($fields)};";
    }

    public function update($table, $fields, $where = '1>0')
    {
        return 'UPDATE `' . $table . '` SET ' . $this->addUpdateValues($fields) . ' WHERE ' . $where;
    }

    /**
     * @param $table
     * @param $fields
     * @param string $idField
     *
     * @return string
     */
    public function save($table, $fields, $idField = 'id')
    {
        return "INSERT INTO `{$table}` ( {$this->addFields($field)} ) VALUES ({$this->_addValue($field)}) ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($field), $idField)};";
    }

    /**
     * WARNING! Db::multipleUpdate() works only when Db::TEMPLATES_WITHOUT
     *
     * @param $table
     * @param $fields
     * @param string $idField
     *
     * @return string
     */
    public function multipleSave($table, $fields, $idField = 'id')
    {
        return "INSERT INTO `{$table}` ( {$this->addFields($fields[0])} ) VALUES {$this->addValues($fields)} ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($fields[0]), $idField)};";
    }

    /**
     * Build the CREATE TABLE DDL
     *
     * @param string $table
     *
     * @return string
     */
    public function createTable($table)
    {
        $sql = "SHOW CREATE TABLE `{$table}`";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch();

        return $result['Create Table'] . ';';
    }

    public function dropTable($table) 
    {
        return "DROP TABLE IF EXISTS `{$table}`;";
    }

    /**
     * Build the FOREIGN KEY DDL for the table
     *
     * @param string $table
     *
     * @return string
     */
    public function createForeignKey(string $table)
    {
        $dbname = $this->db->fetchDatabase();

        $sql = "SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = '{$table}' 
              AND CONSTRAINT_SCHEMA = '{$dbname}' 
              AND CONSTRAINT_NAME != 'PRIMARY'";

        $result = '';
        $stmt = $this->db->query($sql);
        while (false != ($fk = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            if ($fk['REFERENCED_COLUMN_NAME']) {
                $result .= "ALTER TABLE `{$fk['TABLE_NAME']}` ADD CONSTRAINT `{$fk['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fk['COLUMN_NAME']}`) REFERENCES `{$fk['REFERENCED_TABLE_NAME']}` (`{$fk['REFERENCED_COLUMN_NAME']}`);\n";
            }
        }

        return rtrim($result, "\n");
    }

    /**
     * Generates a set of fields that need to be updated or inserted.
     * For the terms "INSERT INTO".
     * @param $fields
     * @return string
     */
    protected function addFields($fields)
    {
        $fieldsArray = array_keys($fields);
        /*foreach ($fields as $field => $value) {
            $fieldsArray[] = $field;
        }*/

        return '`' . implode('`, `', $fieldsArray) . '`';
    }

    /**
     * Handles multi-dimensional array of data and generates a set of groups "(1, 1), (2, 12), (3, 0.5)"
     *
     * @param $fields
     * @return string
     */
    protected function addValues($fields)
    {
        $valuesArray = [];
        foreach ($fields as $values) {
            $valuesArray[] = '(' . $this->_addValue($values) . ')';
        }

        return implode(',', $valuesArray);
    }

    /**
     * Handles multi-dimensional array of data and generates a string like "status = 2, price = 250"
     *
     * @param $fields
     * @return string
     */
    protected function addUpdateValues($fields)
    {
        $method = $this->getSaveMode();

        $valuesArray = [];
        foreach ($fields as $field => $value) {
            $valuesArray[] = '`' . $field . '`=' . $this->$method($field, $value);

            //$valuesArray[] = '(' . $this->_addValue($values) . ')';
        }

        return implode(',', $valuesArray);
    }

    /**
     * Handles one-dimensional array of data and generates a set for one group.
     *
     * @param array $values
     * @return string
     */
    protected function _addValue($values)
    {
        $method = $this->getSaveMode();
        $valueArray = [];
        foreach ($values as $key => $value) {
            $valueArray[] = $this->$method($key, $value);
        }

        return implode(',', $valueArray);
    }

    /**
     * Generates a named template.
     * $db->prepare("INSERT INTO folks (name, addr, city) value (:name, :addr, :city)");
     *
     * @param string $key Array key
     * @param string $value Array value
     * @return string $pattern
     */
    protected function createTemplatesNamed($key, $value = null)
    {
        return " :{$key}";
    }

    /**
     * Generates a unnamed template.
     *
     * @param string $key Array key
     * @param string $value Array value
     * @return string $pattern
     */
    protected function createTemplatesUnnamed($key = null, $value = null)
    {
        $this->values[] = $value;

        return ' ? ';
    }

    /**
     * Generates no template SQL.
     * Open to attacks by building SQL injection.
     *
     * @param string $key Array key
     * @param string $value Array value
     * @return string $pattern
     */
    protected function createTemplatesWithout($key, $value)
    {
        return '"' . $value . '"'; // @todo Add some native PDO escapers
    }

    public function isMulti(array $array):bool
    {
        return is_array($array[array_key_first($array)]);
    }
}
