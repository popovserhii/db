<?php
/**
 * Class for working with database
 *
 * @category Popov
 * @package Popov_Db
 * @author Serhii Popov <popow.serhii@gmail.com>
 * @datetime: 23.07.2013 14:16
 */
namespace Popov\Db;

use PDO;
use PDOStatement;

class Db
{
    private $config = [];

    private $dsn = '';

    /** @var PDO */
    protected $pdo = null;

    protected $query;

    protected $numRows;

    protected $result;

    /** Array with all data for execute query */
    protected $values;

    public function __construct(array $config = null)
    {
        $this->config = $config;
        $this->queryMaker = new QueryMaker($this);
    }

    /**
     * Connect to database
     *
     * @param string $config
     * @return Db
     */
    public static function connectDb($config)
    {
        return new self($config);
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

        $this->dsn = $this->prepareDsn();
        $this->pdo = new \PDO(
            $this->dsn,
            $this->config['username'],
            $this->config['password'],
            $this->config['options'] ?? []
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $this->pdo;
    }

    protected function prepareDsn()
    {
        $portDsn = '';
        if (isset($this->config['port'])) {
            $portDsn = ";port={$this->config['port']}";
        }

        $dsn = sprintf(
            'mysql:dbname=%s;host=%s%s;charset=%s',
            $this->config['database'],
            $this->config['hostname'],
            $portDsn,
            $this->config['charset'] ?? 'utf8',
        );

        return $dsn;
    }

    public function getQueryMaker()
    {
        return $this->queryMaker;
    }
    
    public function query($query)
    {
        #$this->query = $query;
        $stm = $this->lazyLoad()->query($query);
        /*if (!$stm) {
            if ($this->pdo->errorCode() != 0000) {
                throw new \RuntimeException(implode(' | ', $this->pdo->errorInfo()));
            }
        }*/
        //$this->result = $stm;
        // @see https://stackoverflow.com/a/883382/1335142
        #$this->numRows = $stm->rowCount();

        return $stm;
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
     * Unlike the $this->fetchAll(), the value is not added to the new array,
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

    /**
     * Returns last inserted id in the database
     *
     * @return string
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function count($table, $field = '*')
    {
        return $this->query("SELECT COUNT($field) as table_rows FROM {$table}")->fetchColumn();
    }

    public function minVal($table, $field)
    {
        return $this->query("SELECT MIN({$field}) FROM {$table}")->fetchColumn();
    }

    public function maxVal($table, $field)
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

    /**
     * Ger refernces per table in current database
     *
     * @return array
     */
    public function listReferences()
    {
        $schema = $this->fetchDatabase();

        $references = $this->fetchAll("SELECT
            kcu.referenced_table_name, 
            kcu.referenced_column_name,
            kcu.table_name AS foreign_table_name,    
            kcu.column_name AS foreign_column_name,    
            kcu.constraint_name
        FROM
            information_schema.key_column_usage kcu
        WHERE
            kcu.referenced_table_name IS NOT NULL
            AND kcu.table_schema = '{$schema}'
        ORDER BY kcu.referenced_table_name, kcu.referenced_column_name
        ");

        $grouped = [];
        foreach ($references as $reference) {
            $grouped[$reference['referenced_table_name']][] = $reference;
        }

        return $grouped;
    }

    /**
     * Get list of database's tables
     *
     * @return array
     */
    public function listTables()
    {
        $schema = $this->fetchDatabase();

        $rows = $this->fetchAll("SELECT table_name, table_rows 
            FROM information_schema.tables 
            WHERE table_schema = '{$schema}' 
            ORDER BY table_name
        ");

        $tables = [];
        foreach ($rows as $row) {
            $tables[$row['table_name']] = $row['table_rows'];
        }

        return $tables;
    }

    /**
     * Get list of table's columns
     *
     * @param string $table
     *
     * @return mixed
     */
    public function listColumns(string $table)
    {
        static $columns = [];

        if (isset($columns[$table])) {
            return $columns[$table];
        }

        $schema = $this->fetchDatabase();
        $result = $this->originConn->fetchAll("SELECT table_name, column_name, column_default
            FROM information_schema.columns
            WHERE table_schema = '{$schema}'
              AND table_name = '{$table}'
        ");

        foreach ($result as $column) {
            $columns[$column['table_name']][] = $column['column_name'];
        }

        return $columns[$table];
    }

    /**
     * Fetch current selected database
     *
     * @return string
     */
    public function fetchDatabase()
    {
        return $this->query('SELECT DATABASE()')->fetchColumn();
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
     *
     * @return mixed
     */
    public function fetchOne(string $sql, array $parameters = [])
    {
        $statement = $this->lazyLoad()->prepare($sql, [\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY]);
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
        // @see https://stackoverflow.com/a/883382/1335142
        #$this->numRows = $stm->rowCount();

        return $array;
    }

    /**
     * Return value need for generate <select />
     *
     * $sql = "SELECT id, name FROM table WHERE id = ?";
     * $parameters = array(524);
     *
     * @param string $sql
     * @param array $parameters
     *
     * @return array
     */
    public function fetchDropDown(string $sql, array $parameters = [])
    {
        $result = $this->fetchAll($sql, $parameters);
        $data = [];
        foreach ($result as $field) {
            $data[array_shift($field)] = array_shift($field);
        }

        return $data;
    }

    /**
     * INSERT new row into a table
     *
     * @param string $table
     * @param array $fields
     *
     * @return string
     */
    public function add(string $table, array $fields)
    {
        $this->values = [];
        //$sql = 'INSERT INTO `' . $table . '` SET ' . $this->addSet($fields);
        $sql = $this->queryMaker->insert($table, $fields);

        //$this->exec($query);
        $values = array_values($fields); // reset array indexes
        $query = $this->lazyLoad()->prepare($sql);
        $query->execute($values);

        return $this->lastInsertId();
    }

    /**
     * UPDATE a table accorging to WHERE condition
     *
     * @param string $table
     * @param array $fields
     * @param string $where
     *
     * @return int
     */
    public function update(string $table, array $fields, $where = '1>0')
    {
        $this->values = [];
        //$sql = 'UPDATE `' . $table . '` SET (' . $this->_addValue($fields) . ') WHERE ' . $where;
        //$sql = 'UPDATE `' . $table . '` SET ' . $this->addSet($fields) . ' WHERE ' . $where;
        $sql = $this->queryMaker->update($table, $fields, $where);

        $values = array_values($fields); // reset array indexes
        $query = $this->lazyLoad()->prepare($sql);
        $query->execute($values);

        return $query->rowCount();
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

        //$sql = "INSERT INTO `{$table}` ( {$this->addFields($field)} ) VALUES ({$this->_addValue($field)}) ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($field), $idField)}";
        $sql = $this->queryMaker->save($table, $field, $idField = 'id');

        $fields = array_values($field); // reset array indexes
        $query = $this->lazyLoad()->prepare($sql);
        $query->execute($fields);

        return $query->rowCount();
    }

    /**
     * Multiple INSERT/UPDATE.
     * In the $fields transferred multidimensional array.
     * One item which represents the right table in the database.
     *
     * WARNING! Db::multipleUpdate() works only when Db::TEMPLATES_WITHOUT
     *
     * @param string $table Table to insert data
     * @param array $fields Multidimensional array
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
        //$sql = "INSERT INTO `{$table}` ( {$this->addFields($fields[0])} ) VALUES {$this->addValues($fields)} ON DUPLICATE KEY UPDATE {$this->_onDuplicateKeyUpdateField($this->addFields($fields[0]), $idField)};";
        $sql = $this->queryMaker->multipleSave($table, $field, $idField = 'id');
        //\Zend\Debug\Debug::dump([$fields, $this->values, $sql]); die(__METHOD__);
        // run sql
        $query = $this->lazyLoad()->prepare($sql);

        return $query->execute($this->values);
    }

    /**
     * Set the type INSERT/UPDATE.
     * Available options, see. const TEMPLATE_*
     *
     * @param string $method
     */
    public function setSaveMode($method)
    {
        $this->queryMaker->setSaveMode($method);

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
        return $this->queryMaker->getSaveMode();
    }

    /**
     * For example, $db->prepare($param), equivalent to $db->getPdo()->prepare($param).
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
