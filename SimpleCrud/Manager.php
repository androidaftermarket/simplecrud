<?php
/**
 * SimpleCrud\Manager
 * 
 * Base class that manages all entities
 */
namespace SimpleCrud;

use PDO;

class Manager {
	protected $connection;
	protected $inTransaction = false;
	protected $debug;
	protected $entityFactory;
	protected $entities = [];


	public function __construct (PDO $connection, EntityFactory $entityFactory) {
		$this->entityFactory = $entityFactory;
		$this->connection = $connection;
		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}


	/**
	 * Magic method to initialize the entities in lazy mode
	 * 
	 * @param string $name The entity name
	 * 
	 * @return SimpleCrud\Entity
	 */
	public function __get ($name) {
		if (isset($this->entities[$name])) {
			return $this->entities[$name];
		}

		return $this->entities[$name] = $this->entityFactory->create($this, $name);
	}


	/**
	 * Execute a query and returns the statement object with the result
	 * 
	 * @param string $query The Mysql query to execute
	 * @param array $marks The marks passed to the statement
	 *
	 * @throws Exception On error preparing or executing the statement
	 * 
	 * @return PDOStatement The result
	 */
	public function execute ($query, array $marks = null) {
		$query = (string)$query;

		if (!empty($marks)) {
			foreach ($marks as $name => $mark) {
				if (is_array($mark)) {
					foreach ($mark as &$val) {
						$val = $this->connection->quote($val);
					}

					$query = str_replace($name, implode(', ', $mark), $query);
					unset($marks[$name]);
				}
			}
			if (empty($marks)) {
				$marks = null;
			}
		}

		try {
			$statement = $this->connection->prepare($query);
			$statement->execute($marks);
		} catch (\Exception $exception) {
			if (is_array($this->debug)) {
				$this->debug[] = [
					'error' => $exception,
					'statement' => $statement,
					'marks' => $marks
				];
			}

			throw $exception;
		}

		if (is_array($this->debug)) {
			$this->debug[] = [
				'statement' => $statement,
				'marks' => $marks,
				'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)
			];
		}

		return $statement;
	}


	/**
	 * Execute a callable inside a transaction
	 * 
	 * @param callable $callable The function with all operations
	 * 
	 * @return mixed The callable returned value
	 */
	public function executeTransaction (callable $callable) {
		try {
			$transaction = $this->beginTransaction();

			$return = $callable();

			if ($transaction) {
				$this->commit();
			}
		} catch (\Exception $exception) {
			if ($transaction) {
				$this->rollBack();
			}

			throw $exception;
		}

		return $return;
	}


	/**
	 * Returns the last insert id
	 * 
	 * @return int
	 */
	public function lastInsertId () {
		return $this->connection->lastInsertId();
	}


	/**
	 * Starts a transaction if it's not started yet
	 * 
	 * @return boolean True if a the transaction is started or false if its not started
	 */
	public function beginTransaction () {
		if (($this->inTransaction === false) && ($this->connection->inTransaction() === false)) {
			$this->connection->beginTransaction();
			return $this->inTransaction = true;
		}

		return false;
	}


	/**
	 * Commits the changes of the transaction to the database
	 */
	public function commit () {
		if (($this->inTransaction === true) && ($this->connection->inTransaction() === true)) {
			$this->connection->commit();
			$this->inTransaction = false;
		}
	}


	/**
	 * rollBack a transaction
	 */
	public function rollBack () {
		if (($this->inTransaction === true) && ($this->connection->inTransaction() === true)) {
			$this->connection->rollBack();
			$this->inTransaction = false;
		}
	}


	/**
	 * Enable, disable, get debugs
	 *
	 * @param bool Enable or disable. Null to get the debug result
	 * 
	 * @return array or null
	 */
	public function debug ($enabled = null) {
		$debug = $this->debug;

		if ($enabled === true) {
			$this->debug = is_array($this->debug) ? $this->debug : [];
		} else if ($enabled === false) {
			$this->debug = false;
		}

		return $debug;
	}
}
