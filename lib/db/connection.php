<?php
/**
 * Copyright (c) 2013 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\DB;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Configuration;
use Doctrine\Common\EventManager;

class Connection extends \Doctrine\DBAL\Connection {
	protected $table_prefix;
	protected $sequence_suffix;

	protected $preparedQueries = array();

	protected $fixup_from;
	protected $fixup_to;

	protected $adapter;

	/**
	 * Initializes a new instance of the Connection class.
	 *
	 * @param array $params  The connection parameters.
	 * @param Driver $driver
	 * @param Configuration $config
	 * @param EventManager $eventManager
	 */
	public function __construct(array $params, Driver $driver, Configuration $config = null,
		EventManager $eventManager = null)
	{
		if (!isset($params['table_prefix'])) {
			throw new Exception('table_prefix not set');
		}
		if (!isset($params['sequence_suffix'])) {
			throw new Exception('sequence_suffix not set');
		}
		if (!isset($params['adapter'])) {
			throw new Exception('adapter not set');
		}
		parent::__construct($params, $driver, $config, $eventManager);
		$this->table_prefix = $params['table_prefix'];
		$this->sequence_suffix = $params['sequence_suffix'];
		if (isset($params['fixups'])) {
			$this->fixup_from = array_keys($params['fixups']);
			$this->fixup_to = array_values($params['fixups']);
		}
		$this->adapter = new $params['adapter']($this);
	}

	/**
	 * Prepares an SQL statement.
	 *
	 * @param string $statement The SQL statement to prepare.
	 * @return \Doctrine\DBAL\Driver\Statement The prepared statement.
	 */
	public function prepare( $statement, $limit=null, $offset=null ) {
		$statement = $this->fixupStatement($statement);

		if ($limit === -1) {
			$limit = null;
		}
		if (!is_null($limit)) {
			$platform = $this->getDatabasePlatform();
			$statement = $platform->modifyLimitQuery($statement, $limit, $offset);
		} else {
			if (isset($this->preparedQueries[$statement])) {
				return $this->preparedQueries[$statement];
			}
		}
		$rawQuery = $statement;
		$result = parent::prepare($statement);
		if ($this->_driver instanceof \Doctrine\DBAL\Driver\PDOSqlite\Driver) {
			// Sqlite doesn't handle query caching and schema changes
			// TODO: find a better way to handle this
			return $result;
		}
		if (is_null($limit)) {
			$this->preparedQueries[$rawQuery] = $result;
		}
		return $result;
	}

	/**
	 * Returns the ID of the last inserted row, or the last value from a sequence object,
	 * depending on the underlying driver.
	 *
	 * Note: This method may not return a meaningful or consistent result across different drivers,
	 * because the underlying database may not even support the notion of AUTO_INCREMENT/IDENTITY
	 * columns or sequences.
	 *
	 * @param string $seqName Name of the sequence object from which the ID should be returned.
	 * @return string A string representation of the last inserted ID.
	 */
	public function lastInsertId($seqName = null)
	{
		if ($seqName) {
			$seqName = $this->replaceTablePrefix($seqName) . $this->sequence_suffix;
		}
		return parent::lastInsertId($seqName);
	}

	/**
	 * @brief Insert a row if a matching row doesn't exists.
	 * @param string $table. The table to insert into in the form '*PREFIX*tableName'
	 * @param array $input. An array of fieldname/value pairs
	 * @returns bool The return value from execute()
	 */
	public function insertIfNotExist($table, $input) {
		return $this->adapter->insertIfNotExist($table, $input);
	}

	// internal use
	protected function replaceTablePrefix($statement) {
		return str_replace( '*PREFIX*', $this->table_prefix, $statement );
	}

	// internal use
	protected function fixupStatement($statement) {
		$statement = $this->replaceTablePrefix($statement);
		if ($this->fixup_from) {
			$statement = str_ireplace( $this->fixup_from, $this->fixup_to, $statement );
		}
		return $statement;
	}
}
