<?php
namespace Popov\Db\Query;

use Popov\Db\Db;

/**
 * Some ORM for generation WHERE.

 * Class in use
 * 
 * For example:
 * $where = new Query_Where();
 * $where->addAnd('lang_id', 1)->addAnd('section_id', 1);
 * ZEngin::dump($where->buildWhere()); // string(41) " AND `lang_id` = ?  AND `section_id` = ? "
 * 
 * $where->addAnd( $where->addOr(array(
 * 										array('name', 'Senya'),
 *							    		array('name', 'Vasya'),  			
 *									 ) 
 *							    )
 *				  );
 * ZEngin::dump($where->buildWhere()); // string(41) " AND (name = ? OR name = ?) "
 * 
 * $where->addIn('rubric_id', array(2, 6, 7));
 * ZEngin::dump($where->buildWhere()); // string(41) " rubric_id IN(2,6,7) "
 * 
 * $where->addAnd($where->addIn('rubric_id', array(2, 6, 7));
 * ZEngin::dump($where->buildWhere()); // string(41) " AND rubric_id IN(2,6,7) "
 *
 * $where->addAnd ( 'publish', 'y' )
 *		 ->addAnd ( 'simplenews', 'y' )
 *		 ->addAnd ( 'show_on_main', 'y' )
 *		 ->addAnd ( 'rubrica_id', '20' );
 *		
 * $mapper = new News_Mapper_NewsMapper ();
 * $collection = $mapper->findWhere ( $where ); 
 *
 * @author Serzh
 */
class Where {

	/**
	 * Повні умови котрі були добавлені,
	 * для перевірки наявності вже існуючих, щоб не дублювати старі.
	 * 
	 * @var array
	 */
	private $conditionFull = array();
	
	/**
	 * Array that contain condtions for query.
	 * 
	 * @var array
	 */
	private $conditions = array();
	
	/**
	 * Array value suitable condition.
	 * 
	 * @var array
	 */
	private $templates = array();
	
	/**
	 * Тимчасове рішення.
	 * Масив в кортий складаються складні умови для запиту.
	 * 
	 * @deprecated
	 */
	private $complicated = array();
	
	/**
	 * Режим формування запиту. Можливі два варіанти "Named", "Unnamed"
	 * 
	 * @var string
	 */
	private $saveMode = 'Unnamed';
	
	/**
	 * Metasybols pattern for special field name
	 * 
	 * @var string
	 */
	private $fieldMetasymbols = '[?]';
	
	/**
	 * Registered special db word.
	 * 
	 * @var array
	 * @todo create setters and getters for this.
	 */
	public static $specialWords = array();
			
	public function __construct() {
		if(!self::$specialWords) {
			self::$specialWords = Db::$mysqlWords;
		}		
	}
	
	
	/**
	 * AND condtion
	 *
	 * $where->addAnd('name', 'Senya')  								-> 	AND name = "Senya"
	 * $where->addAnd('name', 'Senya')->addAnd('lastname', 'Petrov')  	-> 	AND name = "Senya" AND lastname = "Petrov"
	 *
	 * @param string $field
	 * @param string $value
	 * @param string $comparison
	 * @param string $dbFunction DATE_FORMAT(?, '%Y-%m-%d')
	 * @return $this
	 */
	public function addAnd($field, $value = null, $comparison = '=', $dbFunction = null) {
		$this->process($field, $value, $comparison, $dbFunction, 'AND');
		return $this;
	}
	
	/**
	 * OR condition.
	 *
	 * @todo Можливість додавання будь-якої кількості Or
	 *
	 * @param array|string $data
	 * @param string $value
	 * @param string $comparison
	 * @param string $dbFunction DATE_FORMAT(?, '%Y-%m-%d')
	 * @return $this
	 */
	public function addOr($data, $value = null, $comparison = '=', $dbFunction = null) {
		$this->process($data, $value, $comparison, $dbFunction, 'OR');
		return $this;
	}
	
	/**
	 * IN condition.
	 * 
	 * For example:
	 * $where->andAdd('rubric_id', 3)->andAdd( $where->andIn('section_id', array(3, 6, 2) )) 
	 * ZEngin::dump($where->buildWhere()); // string(41) " AND `rubric_id` = 3 AND `section_id` IN(3,6,2) "
	 * 
	 * @return $this
	 */
	public function addIn($field, array $set) {
		
		$template = array();
		foreach($set as $value) {
			$template[] = '?';
			$this->addTemplate($field, $value);
		}
		
		//$_field = '%alias%' . "`{$field}`";
		$_field = $this->formField($field);
		$condition = $_field . ' IN (' . implode(',', $template) . ') ';
		$this->conditions[] = $condition;
		
		return $this;
	}

	public function addNotIn($field, array $set) {

		$template = array();
		foreach($set as $value) {
			$template[] = '?';
			$this->addTemplate($field, $value);
		}

		//$_field = '%alias%' . "`{$field}`";
		$_field = $this->formField($field);
		$condition = $_field . ' NOT IN (' . implode(',', $template) . ') ';
		$this->conditions[] = $condition;

		return $this;
	}

	/**
	 * Select about field which have type 'set'.
	 * 
	 * For example:
	 * $where->addAnd( $where->addSet('hidden,edited', 'options') )
	 * 
	 * @param string $fieldSet 	Field which have type 'set'
	 * @param string $value 	Value/set that select
	 * @param boolean $greedy 	Greedy select or not
	 */
	public function addSet($set, $field, $greedy = false) {
		$_field = $this->formField($field);
		
		if($greedy) {

		} else {  // FIND_IN_SET( 'show_on_main', options ) > 0
			$values = explode(',', $set);
			foreach($values as $value) {
				$not = ($value{0} == '!') ? '!' : ''; // search negative condition
				$_value = ltrim( trim($value), '!'); // delete '!' if given
				$_condition[] = " {$not}FIND_IN_SET('{$_value}', {$_field}) > 0 ";				
			}
			$condition = implode(' AND ', $_condition);
		}
		$this->conditions[] = $condition;
		return $this;
	}
	
	/**
	 * Create condition LIKE in SQL query.
	 * //`name` LIKE '%учи%'
	 * $where->addLike('name', '%учи%'); // учителі, учитель, профтехучилища
	 * $where->addLike('name', '%учи'); // учителі, учитель
	 * 
	 * @param string $field Searching condition
	 */
	public function addLike($field, $value) {
		$this->addTemplate($field, $value);
		$_field = $this->formField($field);
		$condition = $_field . ' LIKE ? ';
		$this->conditions[] = $condition;

		return $this;
	}
	
	/**
	 * Зроблено як тимчасову реалізацію.
	 * Добаляє до основної умови запит без обробки "так як є".
	 * For example:
	 * 		$where->addComplicated("AND ((
			 `dno`.date_from >= NOW()
			 OR DATE_FORMAT( NOW() , '%Y-%m-%d %H:%i' )
			 BETWEEN DATE_FORMAT( `dno`.date_from, '%Y-%m-%d %H:%i' )
			 AND DATE_FORMAT( `dno`.date_to, '%Y-%m-%d %H:%i' )
			 )
			 OR
			 (
			 (
			 `dno`.date_from >= CURDATE( )
			 OR `dno`.date_to >= CURDATE( )
			 )
			 AND `dno`.time_known = 'n'
			 ))")
	 *
	 * @param string $string	Умова котра буде добалена до основного запиту
	 * @todo	Розробити нормальну реалізацію складних умов
	 * @deprecated
	 */
	public function addComplicated($string) {
		$this->complicated[] = $string;
	}
	
	/**
	 * @deprecated
	 */
	private function buildComplicated() {
		return implode(' ', $this->complicated);
	}
	
	/**
	 * Form something field for query.
	 * Pattern %alias% will be replace to normal alias when call Query_Where::buildWhere().
	 * 
	 * @param string $field		Name field
	 * @param string $db_function	Function database. For example: DATE_FORMAT(?, '%Y-%m-%d').
	 * 								Where simbol '?' replace $field.
	 */
	private function formField( $field, $db_function = null ) {
		$_field = (strpos($field, '.') === false) ? '%alias%'."`{$field}`" : $field;
		$_field = is_null($db_function) ? $_field : str_replace('?', $_field, $db_function);
		
		//ZEngine::dump($_field);
		return $_field;
	}
	
	/**
	 * 
	 * Form something condition for query
	 * 
	 * @param string $specOperator 	AND | OR
 	 * @param string $field 	Field name
	 * @param string $comparison 	Operator comparison
	 */
	private function formCondition($specOperator, $field, $comparison) {
		$condition = "$specOperator {$field} {$comparison} ?";
		return $condition;
	}
	
	/**
	 * Перевірка на існування даної умови.
	 * Повертає false якщо не існує, або індекс масиву в контрому зберігається дана умова.
	 * При перевірці повернутого значення потрібно робити тотожну перевіру "==="
	 * 
	 * @param string $method
	 * @param string $field
	 * @param string $operator
	 * @param int|string $value
	 */
	private function existCondition($condition_new) {
		$number = false;
		//ZEngine::dump($condition_new);
		foreach($this->conditions as $key => $condition) {
			
			if($condition === $condition_new) {
				//ZEngine::dump($condition_new);
				$number = $key;
				break;
			}
		}
		
		return $number;
	}
	
	/**
	 * Формувати умову для WHERE
	 * 
	 * @param string $alias	Аліас таблиці
	 */
	public function buildWhere($alias = null) {
		$alias_format = !is_null($alias) ? "`{$alias}`." : '';
		
		$conditions = '';
		foreach($this->conditions as $condition) {
			$condition = str_replace('%alias%', $alias_format, $condition);
			$conditions .= " {$condition} ";
		}
		
		$conditions .= $this->buildComplicated();
		
		return $conditions;		
	}
	
	/**
	 * Встановити, котрий шаблон використовувати для формування запиту.
	 * !!!Warninng на даний момент доступний "Unnamed";
	 * 
	 * @param string $mode Можливі значення 'named' | 'unnamed'
	 */
	public function setSaveMode($mode) {
		$this->saveMode = ucfirst($mode);
	}
	
	protected function addTemplate($name, $value) {
		$method = "add{$this->saveMode}Template";
		$this->$method($name, $value);
	}
	
	protected function updateTemplate($number, $name, $value) {
		$method = "update{$this->saveMode}Template";
		$this->$method($number, $name, $value);
	}
	
	protected function addNamedTemplate($name, $value) {
		$template = ":{$name}";
		$this->templates[$template] = $value;
	}
	
	protected function addUnnamedTemplate($name, $value) {
		$this->templates[] = $value;
	}
	
	/**
	 * Update value template.
	 * 
	 * @param int $number 	Номер елемента умови (знаходитиься в $conditions), значення для якої потрібно оновити.
	 * @param string $name	Назва поля. Тут не використовується.
	 * @param mixed $value	Значення на котре потрібно замінити.
	 */
	protected function updateUnnamedTemplate($number, $name, $value) {
		
		$numberTemplate = $this->getNumberOfTemplate($number);

		$this->templates[$numberTemplate] = $value;
	}
	
	/**
	 * Перебираєм всі доступні умови, але не більше переданого $number раз.
	 * 		Знаходим кількість символів котрі відповідають $pattern (знак '?') в одній умові.
	 * 		Сумуєм знайдену кількість символів. 
	 * 		Знайдена кількість відповдає елементу ($templates) значення котрого потрібно оновити.
	 * Повератає номер потрібного елементу.
	 * 
	 * @return int $numberTemplate
	 */
	private function getNumberOfTemplate($number) {
		$numberTemplate = -1;
		foreach($this->conditions as $key => $condition) {
			if($key <= $number) {
				
				$pattern = '/\?{1}/'; 
				preg_match_all($pattern, $condition, $matches); // find out amount of value about $pattern
  				
  				foreach($matches as $match) {
  					$numberTemplate += count($match);
  				}												
			}
		}
		return $numberTemplate;
	}
	
	
	/**
	 * Clear condtions.
	 * 
	 * @param string $field
	 */
	public function clear($field = null, $value = null) {
		if( is_null($field) ) {
			$this->conditionFull = array();
			$this->conditions = array();
			$this->templates = array();
			
		} else {
			
		}
		return $this;
	}
	
	/**
	 * Отримати шаблон запиту.
	 * @return	array 	$templates 	Масив іменованих шаблонів з значеннями	array(':name' => 'Vanya')
	 * 								Масив неіменованих шаблонів з значеннями	array('Vanya')
	 */
	public function getTemplate() {
		return $this->templates;
	}
	
	/**
	 * Decide that to do.
	 * This method work with addAnd/addOr
	 */
	private function process($data, $value = null, $comparison = '=', $dbFunction = null, $specOperator) {
		
		$type = gettype($data);
		$specMethod = 'process' . ucfirst($type);
		
		$class = get_class($this); // Query_Where
		if( $type === 'object' AND $data instanceof $class ) { //@todo Можна реалізувати AbstactFactory по типах (array | object | string). Цим  самим розширити можливості.
			$this->$specMethod($specOperator);
			
		} elseif($type === 'string') {
			$this->$specMethod($data, $value, $comparison, $dbFunction, $specOperator);
	
		} elseif($type === 'array') {
			$this->$specMethod($data, $value, $comparison, $dbFunction, $specOperator);
		}
			
	}
	
	/**
	 * Process received object.
	 * Витягується останній доданий елемент, заключається в умову (AND()) і додається назад в набір.
	 * 
	 * @param string $operator 	AND | OR 
	 */
	private function processObject($specOperator) {
		$condition = array_pop($this->conditions); 
		$this->conditions[] = "$specOperator ( {$condition} )";
	}
	
	/**
	 * Process received array.
	 * Витягується останній доданий елемент, заключається в умову (AND()) і додається назад в набір.
	 * @param string $operator 	AND | OR 
	 */
	private function processString($field, $value, $comparison, $dbFunction, $specOperator) {
		$field = $this->formField($field, $dbFunction);
			
		//$condition = "$specOperator {$field} {$comparison} ?";
		$condition = $this->formCondition($specOperator, $field, $comparison);
		
		$this->confirmCondition($condition, $field, $value);

		/*
		$number = $this->existCondition($condition);
		if( $number !== false ) { // перевірка, чи не добавялась раніше така умова
			$this->conditions[$number] = $condition;
			$this->updateTemplate($number, $field, $value);
			
		} else {
			$this->conditions[] = $condition;
			$this->addTemplate($field, $value); //@FIXME Перевірити, що буде коли добавити два однакових поля, тільки одне з функцією, інше без.
		}
		*/
	}
	
	/**
	 * Process received string.
	 * Витягується останній доданий елемент, заключається в умову (AND()) і додається назад в набір.
	 * 
	 * @param string $operator 	AND | OR 
	 */
	private function processArray($data, $value, $comparison, $dbFunction, $specOperator) {
		$condition = array();
		foreach($data as $key => $same_or) {
			$field = array_shift($same_or);
			$value = array_shift($same_or);
			$condition[] = "{$field} {$comparison} ?";
			
			$this->addTemplate($field, $value);
			//$condition_second = '"' . implode( '"="', array_shift($array) ) . '"';
		}
		
		$conditions = implode(" {$specOperator} ", $condition);
		
		$this->conditions[] = $conditions;
	}
	
	/**
	 * Review condition, field and value for create right query to db.
	 * Check value exsist in registered db word.
	 * 
	 * @param unknown_type $condition
	 * @param unknown_type $field
	 * @param unknown_type $value
	 */
	protected function confirmCondition($condition, $field, $value) {
		
		//die();
		
		$number = $this->existCondition($condition);
		
		if($this->isSpecialWord($value)){
			$condition = str_replace('?', $value, $condition);
			$this->conditions[] = $condition;
			
		} elseif( $number !== false ) { // перевірка, чи не добавялась раніше така умова
			$this->conditions[$number] = $condition;
			$this->updateTemplate($number, $field, $value);
			
		} else {
			$this->conditions[] = $condition;
			$this->addTemplate($field, $value); //@FIXME Перевірити, що буде коли добавити два однакових поля, тільки одне з функцією, інше без.
		}	
		
		//ZEngine::dump($condition);
	}
	
	/**
	 * If checking word exist in specail word list then return true otherwise false
	 * 
	 * @param string $word
	 * @return boolean 	true | false
	 */
	public function isSpecialWord($word) {
		return in_array($word, self::$specialWords);
	}	
}