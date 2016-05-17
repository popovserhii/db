<?php
/**
 * Class for working with database
 *
 * @category Agere
 * @package Agere_Db
 * @author Popov Sergiy <popov@agere.com.ua>
 * @datetime: 23.07.2013 14:16
 */
namespace Agere\Db;

use PDO;
use PDOStatement;
use Zend\Stdlib\Exception;

class Db
{
    /**
     * Mode for INSERT/UPDATE
     *
     * @var string
     */
    private $_updateMode = Db::TEMPLATES_UNNAMED;

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
     * Lack of templates - a direct path SQL-injection.
     * The value corresponds to the name of the method that will be invoked to convert
     *
     * @var string
     */
    const TEMPLATES_WITHOUT = 'createTemplatesWithout';

    private $db = '';

    private $port = '';

    private $host = '';

    private $user = '';

    private $pass = '';

    private $dsn = '';

    /** @var PDO */
    protected $pdo = null;

    protected $query;

    protected $numRows;

    protected $result;

    /** Array with all data for execute query */
    protected $values;

    /**
     * MySQL keywords
     *
     * @var array
     */
    public static $mysqlWords = ["CURRENT_TIMESTAMP", "NOW()", "NULL"];

    private function __construct($db = '', $host = '', $user = '', $pass = '', $port = 3306)
    {
        $this->db = $db;
        $this->port = $port;
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * Connect to database
     *
     * @param string $db
     * @param string $host
     * @param string $username
     * @param string $pass
     * @param int $port
     * @return Db
     */
    public static function connectDb($db = '', $host = '', $username = '', $pass = '', $port = 3306)
    {
        return new self($db, $host, $username, $pass, $port);
    }

    public function setPdo($pdo)
    {
        $this->pdo = $pdo;

        return $this;
    }

    public function getPdo()
    {
        return $this->lazyLoad();
    }

    protected function lazyLoad()
    {
        if ($this->pdo != null) {
            return $this->pdo;
        }

        $options = [
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            \PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ];

        $this->dsn = $this->prepareDsn();
        $this->pdo = new \PDO($this->dsn, $this->user, $this->pass, $options);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $this->pdo;
    }

    protected function prepareDsn()
    {
        $portDsn = ";port={$this->port}";
        if (!$this->port) {
            $portDsn = '';
        }
        $dsn = "mysql:dbname={$this->db};host={$this->host}{$portDsn}";

        return $dsn;
    }

    public function query($query)
    {
        $this->query = $query;
        $res = $this->lazyLoad()->query($query);
        if (!$res) {
            if ($this->pdo->errorCode() != 0000) {
                throw new Exception\RuntimeException(implode(' | ', $this->pdo->errorInfo()));
            }
        }
        $this->result = $res;
        $this->numRows = $res->rowCount();

        return $res;
    }

    /**
     * PDO::exec() method is used to execute a query that does not return the sample data.
     *
     * @param $query
     * @return int
     */
    public function exec($query)
    {
        $result = $this->lazyLoad()->exec($query);

        return $result;
    }

    /**
     * Return associate array with all data of one row in table
     *
     * @param $query
     * @return mixed
     */
    public function fetch($query)
    {
        $result = $this->query($query);

        return $result->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns an associative array of values from one sample.
     * Unlike the $ this-> arAll (), the value is not added to the new array,
     * but rather for the unique ID number of the array is set to the first value
     * in the "SELECT first, second FROM table"
     *
     * @param string $query
     * @return array $allData
     */
    public function fetchAssoc($query)
    {
        $data = [];
        $result = $this->query($query);
        $keyArray = false;
        while (false != ($row = $result->fetch(\PDO::FETCH_ASSOC))) {
            $first = 1;
            foreach ($row as $key => $value) {
                if ($first === 1) {
                    $keyArray = $value;
                    $first++;
                    continue;
                }
                $data[$keyArray][$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @see http://ua2.php.net/manual/en/function.addslashes.php#107661
     * @param string $str
     * @return string
     */
    public function escapeQuery($str)
    {
        return strtr($str, [
            "\0" => "",
            "'" => "&#39;",
            "\"" => "&#34;",
            "\\" => "&#92;",
            // more secure
            "<" => "&lt;",
            ">" => "&gt;",
        ]);
    }

    public function addSet($fields)
    {
        $set = [];
        foreach ($fields as $field => $value) {
            (!in_array($value, self::$mysqlWords))
                ? $set[] = "`" . $field . "`='" . $this->escapeQuery($value) . "'"
                : $set[] = "`" . $field . "`=" . $value;
        }

        return implode(',', $set);
    }

    public function addField($table, $fields, $htmlAdaptation = null)
    {
        if ($htmlAdaptation === true) {
            foreach ($fields as $key => $value) {
                $value = htmlspecialchars_decode($value, ENT_QUOTES);
                $fields [$key] = htmlspecialchars($value, ENT_QUOTES);
            }
        }
        $query = 'INSERT INTO `' . $table . '` SET ' . $this->addSet($fields);
        $this->exec($query);

        return $this->lastInsertId();
    }

    public function updateField($table, $fields, $where = '1>0', $htmlAdaptation = null)
    {
        if ($htmlAdaptation === true) {
            foreach ($fields as $key => $value) {
                $value = htmlspecialchars_decode($value, ENT_QUOTES);
                $fields [$key] = htmlspecialchars($value, ENT_QUOTES);
            }
        }
        $query = 'UPDATE `' . $table . '` SET ' . $this->addSet($fields) . ' WHERE ' . $where;

        return $this->exec($query);
    }

    /**
     * Returns last inserted id in the database
     *
     * @return string
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function minVal($field, $table)
    {
        return $this->query("SELECT MIN({$field}) FROM {$table}")->fetchColumn();
    }

    public function maxVal($field, $table)
    {
        return $this->query("SELECT MAX({$field}) FROM {$table}")->fetchColumn();
    }

    public function numRows($query = null)
    {
        if (!is_null($query)) {
            $this->query($query);
        }

        return $this->numRows;
    }

    /*public function error($key = 2)
    {
        $error = $this->pdo->errorInfo();
        //\Rotor\ZEngine::dump($error_array);
        //$this->Pdo->errorCode () != 0000)
        // в случае ошибки SQL выражения выведем сообщене об ошибке
        return $error[$key];
    }*/

    /**
     * @todo
     */
    public function listTables()
    {
        // not implemented yed
    }

    /**
     * @todo
     */
    function listFields($tableName)
    {
        // not implemented yet
    }

    /**
     * Returns the first record of the sample.
     * If passed only the first parameter is returned SQL statement to transfer only necessary to sample data.
     *    Execute a prepared statement by passing an array of values
     *    For example:
     *        $sql = 'SELECT name, colour, calories FROM fruit WHERE calories < :calories AND colour = :colour';
     *        $sth = $dbh->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY)); // SQL statement
     *        $sth->execute(array(':calories' => 150, ':colour' => 'red'));
     *        $red = $sth->fetch();
     *        $sth->execute(array(':calories' => 175, ':colour' => 'yellow'));
     *        $yellow = $sth->fetch();
     * The second parameter passed array template on which to do sampling by default - "named template"
     *    For example:
     *    $data = array( 'name' => 'Mishel', 'addr' => 'str Shevchenko', 'city' => 'Kyiv' );
     *
     * @param string $sql
     * @param array $parameters
     * @return mixed
     */
    public function fetchOne($sql, array $parameters = [])
    {
        $statement = $this->lazyLoad()->prepare($sql, [\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY]);
        //$statement = $this->lazyLoad()->prepare($sql);
        $statement->execute($parameters);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        return $result;
    }

    /**
     * $sql = "SELECT id FROM table WHERE id = ?";
     * $parameters = array(524);
     *
     * @param string $sql
     * @param array $parameters
     * @return array
     */
    public function fetchAll($sql, array $parameters = [])
    {
        $stm = $this->lazyLoad()->prepare($sql);
        $stm->execute($parameters);
        $array = $stm->fetchAll(\PDO::FETCH_ASSOC);

        return $array;
    }

    /**
     * Return value need for generate <select />
     *
     * $sql = "SELECT id, name FROM table WHERE id = ?";
     * $parameters = array(524);
     *
     * @param $sql
     * @param array $parameters
     * @return array
     */
    public function fetchDropDown($sql, array $parameters = [])
    {
        $result = $this->fetchAll($sql, $parameters);
        $data = [];
        foreach ($result as $field) {
            $data[array_shift($field)] = array_shift($field);
        }

        return $data;
    }

    /**
     * Regular UPDATE/INSERT
     * In the field $field transferred one-dimensional array,
     * which keys correspond to fields in the database table, and the value of the array - according to data insertion.
     * If UPDATE then the first value in the array $field must be a unique value field (eg: id).
     *
     * @param string $table
     * @param array $field
     * @param string $idField
     * @return int
     */
    public function save($table, $field, $idField = 'id')
    {
        unset($this->values);
        $sql = "INSERT INTO `{$table}` ( {$this->addFields($field)} ) VALUES ({$this->_addValue($field)}) ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($field), $idField)}";
        $fields = array_values($field); // reset array indexes
        $query = $this->lazyLoad()->prepare($sql);
        $query->execute($fields);

        return $query->rowCount();
    }

    /**
     * Multiple INSERT/UPDATE.
     * In the $fields transferred multidimensional array.
     * One item which represents the right table in the database.
     * WARNING! Db::multipleUpdate() works only while Db::TEMPLATES_WITHOUT
     *
     * @param string $table Таблиця для вставки даних
     * @param array $fields Многомірний масив даних
     *  1 - array(0 => array('id' => 65, 'name' => Senya, 'lang' => 'ua'), // If the field is a database will UPDATE
     *  2 - array('id' => 0, 'name' => Senya, 'lang' => 'ua')) // If the field is not in the database will INSERT
     *
     * @param $table
     * @param $fields
     * @param string $idField
     * @return bool
     */
    public function multipleSave($table, $fields, $idField = 'id')
    {
        unset($this->values);
        $sql = "INSERT INTO `{$table}` ( {$this->addFields($fields[0])} ) VALUES {$this->addValues($fields)} ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($fields[0]), $idField)};";
        //\Zend\Debug\Debug::dump([$fields, $this->values, $sql]); die(__METHOD__);
        // run sql
        $query = $this->lazyLoad()->prepare($sql);

        return $query->execute($this->values);
    }

    /**
     * Gets tape field names for the condition "ON DUPLICATE KEY UPDATE".
     * Ignore the first field, as it should be unique and should not be changed.
     * Generates set to ON DUPLICATE KEY UPDATE, the type field = VALUES (field), field2 = VALUES (field2), ...
     */
    private function _onDuplicateKeyUpdateField($fields_str, $idField)
    {
        $fields_array = explode(',', $fields_str);
        $fields = [];
        foreach ($fields_array as $field) {
            if ($field == $idField) {
                continue;
            }
            $fields[] = " {$field} = VALUES({$field})";
        }

        return (string) implode(',', $fields);
    }

    /**
     * Generates a set of fields that need to be updated or inserted.
     * For the terms "INSERT INTO".
     * @param $fields
     * @return string
     */
    protected function addFields($fields)
    {
        $fields_array = [];
        foreach ($fields as $field => $value) {
            $fields_array[] = $field;
        }

        return (string) implode(',', $fields_array);
    }

    /**
     * Handles multi-dimensional array of data and generates a set of groups "(1, 1), (2, 12), (3, 0.5)"
     *
     * @param $fields
     * @return string
     */
    protected function addValues($fields)
    {
        $values_array = [];
        foreach ($fields as $values) {
            $values_array[] = '(' . $this->_addValue($values) . ')';
        }

        return implode(',', $values_array);
    }

    /**
     * Handles one-dimensional array of data and generates a set for one group.
     *
     * @param array $values
     * @return string
     */
    private function _addValue($values)
    {
        $method = $this->getSaveMode();
        $value_array = [];
        foreach ($values as $key => $value) {
            $value_array[] = $this->$method($key, $value);
        }

        return implode(',', $value_array);
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
        return '"' . $value . '"'; // @todo use PDO think about
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
     * For example, when you call $db->prepare($param), return $this->pdo->prepare($param).
     *
     * @param string $name
     * @param mixed $arguments
     * @return PDOStatement|null
     */
    public function __call($name, $arguments)
    {
        $pdo = $this->lazyLoad();
        //return $pdo->{$name}($arguments[0]); //@FIXME
        return call_user_func_array([$pdo, $name], $arguments);
    }
}
