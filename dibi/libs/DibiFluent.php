<?php

/**
 * This file is part of the "dibi" - smart database abstraction layer.
 *
 * Copyright (c) 2005, 2010 David Grudl (http://davidgrudl.com)
 *
 * This source file is subject to the "dibi license", and/or
 * GPL license. For more information please see http://dibiphp.com
 * @package    dibi
 */



/**
 * dibi SQL builder via fluent interfaces. EXPERIMENTAL!
 *
 * @author     David Grudl
 *
 * @property-read string $command
 * @property-read DibiConnection $connection
 * @property-read DibiResultIterator $iterator
 * @method DibiFluent select($field)
 * @method DibiFluent distinct()
 * @method DibiFluent from($table)
 * @method DibiFluent where($cond)
 * @method DibiFluent groupBy($field)
 * @method DibiFluent having($cond)
 * @method DibiFluent orderBy($field)
 * @method DibiFluent limit(int $limit)
 * @method DibiFluent offset(int $offset)
 */
class DibiFluent extends DibiObject implements IDataSource
{
	const REMOVE = FALSE;

	/** @var array */
	public static $masks = array(
		'SELECT' => array('SELECT', 'DISTINCT', 'FROM', 'WHERE', 'GROUP BY',
			'HAVING', 'ORDER BY', 'LIMIT', 'OFFSET'),
		'UPDATE' => array('UPDATE', 'SET', 'WHERE', 'ORDER BY', 'LIMIT'),
		'INSERT' => array('INSERT', 'INTO', 'VALUES', 'SELECT'),
		'DELETE' => array('DELETE', 'FROM', 'USING', 'WHERE', 'ORDER BY', 'LIMIT'),
	);

	/** @var array  default modifiers for arrays */
	public static $modifiers = array(
		'SELECT' => '%n',
		'FROM' => '%n',
		'IN' => '%in',
		'VALUES' => '%l',
		'SET' => '%a',
		'WHERE' => '%and',
		'HAVING' => '%and',
		'ORDER BY' => '%by',
		'GROUP BY' => '%by',
	);

	/** @var array  clauses separators */
	public static $separators = array(
		'SELECT' => ',',
		'FROM' => ',',
		'WHERE' => 'AND',
		'GROUP BY' => ',',
		'HAVING' => 'AND',
		'ORDER BY' => ',',
		'LIMIT' => FALSE,
		'OFFSET' => FALSE,
		'SET' => ',',
		'VALUES' => ',',
		'INTO' => FALSE,
	);

	/** @var array  clauses */
	public static $clauseSwitches = array(
		'JOIN' => 'FROM',
		'INNER JOIN' => 'FROM',
		'LEFT JOIN' => 'FROM',
		'RIGHT JOIN' => 'FROM',
	);

	/** @var DibiConnection */
	private $connection;

	/** @var string */
	private $command;

	/** @var array */
	private $clauses = array();

	/** @var array */
	private $flags = array();

	/** @var array */
	private $cursor;

	/** @var DibiLazyStorage  normalized clauses */
	private static $normalizer;



	/**
	 * @param  DibiConnection
	 */
	public function __construct(DibiConnection $connection)
	{
		$this->connection = $connection;

		if (self::$normalizer === NULL) {
			self::$normalizer = new DibiLazyStorage(array(__CLASS__, '_formatClause'));
		}
	}



	/**
	 * Appends new argument to the clause.
	 * @param  string clause name
	 * @param  array  arguments
	 * @return DibiFluent  provides a fluent interface
	 */
	public function __call($clause, $args)
	{
		$clause = self::$normalizer->$clause;

		// lazy initialization
		if ($this->command === NULL) {
			if (isset(self::$masks[$clause])) {
				$this->clauses = array_fill_keys(self::$masks[$clause], NULL);
			}
			$this->cursor = & $this->clauses[$clause];
			$this->cursor = array();
			$this->command = $clause;
		}

		// auto-switch to a clause
		if (isset(self::$clauseSwitches[$clause])) {
			$this->cursor = & $this->clauses[self::$clauseSwitches[$clause]];
		}

		if (array_key_exists($clause, $this->clauses)) {
			// append to clause
			$this->cursor = & $this->clauses[$clause];

			// TODO: really delete?
			if ($args === array(self::REMOVE)) {
				$this->cursor = NULL;
				return $this;
			}

			if (isset(self::$separators[$clause])) {
				$sep = self::$separators[$clause];
				if ($sep === FALSE) { // means: replace
					$this->cursor = array();

				} elseif (!empty($this->cursor)) {
					$this->cursor[] = $sep;
				}
			}

		} else {
			// append to currect flow
			if ($args === array(self::REMOVE)) {
				return $this;
			}

			$this->cursor[] = $clause;
		}

		if ($this->cursor === NULL) {
			$this->cursor = array();
		}

		// special types or argument
		if (count($args) === 1) {
			$arg = $args[0];
			// TODO: really ignore TRUE?
			if ($arg === TRUE) { // flag
				return $this;

			} elseif (is_string($arg) && preg_match('#^[a-z:_][a-z0-9_.:]*$#i', $arg)) { // identifier
				$args = array('%n', $arg);

			} elseif ($arg instanceof self) {
				$args = array_merge(array('('), $arg->_export(), array(')'));

			} elseif (is_array($arg) || $arg instanceof Traversable) { // any array
				if (isset(self::$modifiers[$clause])) {
					$args = array(self::$modifiers[$clause], $arg);

				} elseif (is_string(key($arg))) { // associative array
					$args = array('%a', $arg);
				}
			} // case $arg === FALSE is handled above
		}

		foreach ($args as $arg) $this->cursor[] = $arg;

		return $this;
	}



	/**
	 * Switch to a clause.
	 * @param  string clause name
	 * @return DibiFluent  provides a fluent interface
	 */
	public function clause($clause, $remove = FALSE)
	{
		$this->cursor = & $this->clauses[self::$normalizer->$clause];

		if ($remove) { // deprecated, use removeClause
			trigger_error(__METHOD__ . '(..., TRUE) is deprecated; use removeClause() instead.', E_USER_NOTICE);
			$this->cursor = NULL;

		} elseif ($this->cursor === NULL) {
			$this->cursor = array();
		}

		return $this;
	}



	/**
	 * Removes a clause.
	 * @param  string clause name
	 * @return DibiFluent  provides a fluent interface
	 */
	public function removeClause($clause)
	{
		$this->clauses[self::$normalizer->$clause] = NULL;
		return $this;
	}



	/**
	 * Change a SQL flag.
	 * @param  string  flag name
	 * @param  bool  value
	 * @return DibiFluent  provides a fluent interface
	 */
	public function setFlag($flag, $value = TRUE)
	{
		$flag = strtoupper($flag);
		if ($value) {
			$this->flags[$flag] = TRUE;
		} else {
			unset($this->flags[$flag]);
		}
		return $this;
	}



	/**
	 * Is a flag set?
	 * @param  string  flag name
	 * @return bool
	 */
	final public function getFlag($flag)
	{
		return isset($this->flags[strtoupper($flag)]);
	}



	/**
	 * Returns SQL command.
	 * @return string
	 */
	final public function getCommand()
	{
		return $this->command;
	}



	/**
	 * Returns the dibi connection.
	 * @return DibiConnection
	 */
	final public function getConnection()
	{
		return $this->connection;
	}



	/********************* executing ****************d*g**/



	/**
	 * Generates and executes SQL query.
	 * @param  mixed what to return?
	 * @return DibiResult|int  result set object (if any)
	 * @throws DibiException
	 */
	public function execute($return = NULL)
	{
		$res = $this->connection->query($this->_export());
		return $return === dibi::IDENTIFIER ? $this->connection->getInsertId() : $res;
	}



	/**
	 * Generates, executes SQL query and fetches the single row.
	 * @return DibiRow|FALSE  array on success, FALSE if no next record
	 */
	public function fetch()
	{
		if ($this->command === 'SELECT') {
			return $this->connection->query($this->_export(NULL, array('%lmt', 1)))->fetch();
		} else {
			return $this->connection->query($this->_export())->fetch();
		}
	}



	/**
	 * Like fetch(), but returns only first field.
	 * @return mixed  value on success, FALSE if no next record
	 */
	public function fetchSingle()
	{
		if ($this->command === 'SELECT') {
			return $this->connection->query($this->_export(NULL, array('%lmt', 1)))->fetchSingle();
		} else {
			return $this->connection->query($this->_export())->fetchSingle();
		}
	}



	/**
	 * Fetches all records from table.
	 * @param  int  offset
	 * @param  int  limit
	 * @return array
	 */
	public function fetchAll($offset = NULL, $limit = NULL)
	{
		return $this->connection->query($this->_export(NULL, array('%ofs %lmt', $offset, $limit)))->fetchAll();
	}



	/**
	 * Fetches all records from table and returns associative tree.
	 * @param  string  associative descriptor
	 * @return array
	 */
	public function fetchAssoc($assoc)
	{
		return $this->connection->query($this->_export())->fetchAssoc($assoc);
	}



	/**
	 * Fetches all records from table like $key => $value pairs.
	 * @param  string  associative key
	 * @param  string  value
	 * @return array
	 */
	public function fetchPairs($key = NULL, $value = NULL)
	{
		return $this->connection->query($this->_export())->fetchPairs($key, $value);
	}



	/**
	 * Required by the IteratorAggregate interface.
	 * @param  int  offset
	 * @param  int  limit
	 * @return DibiResultIterator
	 */
	public function getIterator($offset = NULL, $limit = NULL)
	{
		return $this->connection->query($this->_export(NULL, array('%ofs %lmt', $offset, $limit)))->getIterator();
	}



	/**
	 * Generates and prints SQL query or it's part.
	 * @param  string clause name
	 * @return bool
	 */
	public function test($clause = NULL)
	{
		return $this->connection->test($this->_export($clause));
	}



	/**
	 * @return int
	 */
	public function count()
	{
		return (int) $this->connection->query(
			'SELECT COUNT(*) FROM (%ex', $this->_export(), ') AS [data]'
		)->fetchSingle();
	}



	/********************* exporting ****************d*g**/



	/**
	 * @return DibiDataSource
	 */
	public function toDataSource()
	{
		return new DibiDataSource($this->connection->translate($this->_export()), $this->connection);
	}



	/**
	 * Returns SQL query.
	 * @return string
	 */
	final public function __toString()
	{
		return $this->connection->translate($this->_export());
	}



	/**
	 * Generates parameters for DibiTranslator.
	 * @param  string clause name
	 * @return array
	 */
	protected function _export($clause = NULL, $args = array())
	{
		if ($clause === NULL) {
			$data = $this->clauses;

		} else {
			$clause = self::$normalizer->$clause;
			if (array_key_exists($clause, $this->clauses)) {
				$data = array($clause => $this->clauses[$clause]);
			} else {
				return array();
			}
		}

		foreach ($data as $clause => $statement) {
			if ($statement !== NULL) {
				$args[] = $clause;
				if ($clause === $this->command && $this->flags) {
					$args[] = implode(' ', array_keys($this->flags));
				}
				foreach ($statement as $arg) $args[] = $arg;
			}
		}

		return $args;
	}



	/**
	 * Format camelCase clause name to UPPER CASE.
	 * @param  string
	 * @return string
	 * @internal
	 */
	public static function _formatClause($s)
	{
		if ($s === 'order' || $s === 'group') {
			$s .= 'By';
			trigger_error("Did you mean '$s'?", E_USER_NOTICE);
		}
		return strtoupper(preg_replace('#[a-z](?=[A-Z])#', '$0 ', $s));
	}



	public function __clone()
	{
		// remove references
		foreach ($this->clauses as $clause => $val) {
			$this->clauses[$clause] = & $val;
			unset($val);
		}
		$this->cursor = & $foo;
	}

}
