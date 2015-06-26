<?php
/**
 * This is the MySQL database abstraction layer.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Database
 */

/**
 * Database abstraction object for MySQL.
 * Defines methods independent on used MySQL extension.
 *
 * @ingroup Database
 * @since 1.22
 * @see Database
 */
abstract class DatabaseMysqlBase extends DatabaseBase {
	/** @var MysqlMasterPos */
	protected $lastKnownSlavePos;

	/** @var null|int */
	protected $mFakeSlaveLag = null;

	protected $mFakeMaster = false;

	/** @var string|null */
	private $serverVersion = null;

	/**
	 * @return string
	 */
	function getType() {
		return 'mysql';
	}

	/**
	 * @param string $server
	 * @param string $user
	 * @param string $password
	 * @param string $dbName
	 * @throws Exception|DBConnectionError
	 * @return bool
	 */
	function open( $server, $user, $password, $dbName ) {
		global $wgAllDBsAreLocalhost, $wgSQLMode;

		# Debugging hack -- fake cluster
		if ( $wgAllDBsAreLocalhost ) {
			$realServer = 'localhost';
		} else {
			$realServer = $server;
		}
		$this->close();
		$this->mServer = $server;
		$this->mUser = $user;
		$this->mPassword = $password;
		$this->mDBname = $dbName;

		# The kernel's default SYN retransmission period is far too slow for us,
		# so we use a short timeout plus a manual retry. Retrying means that a small
		# but finite rate of SYN packet loss won't cause user-visible errors.
		$this->mConn = false;
		$this->installErrorHandler();
		try {
			$this->mConn = $this->mysqlConnect( $realServer );
		} catch ( Exception $ex ) {
			$this->restoreErrorHandler();
			throw $ex;
		}
		$error = $this->restoreErrorHandler();

		# Always log connection errors
		if ( !$this->mConn ) {
			if ( !$error ) {
				$error = $this->lastError();
			}
			wfLogDBError(
				"Error connecting to {db_server}: {error}",
				$this->getLogContext( array(
					'method' => __METHOD__,
					'error' => $error,
				) )
			);
			wfDebug( "DB connection error\n" .
				"Server: $server, User: $user, Password: " .
				substr( $password, 0, 3 ) . "..., error: " . $error . "\n" );

			$this->reportConnectionError( $error );
		}

		if ( $dbName != '' ) {
			MediaWiki\suppressWarnings();
			$success = $this->selectDB( $dbName );
			MediaWiki\restoreWarnings();
			if ( !$success ) {
				wfLogDBError(
					"Error selecting database {db_name} on server {db_server}",
					$this->getLogContext( array(
						'method' => __METHOD__,
					) )
				);
				wfDebug( "Error selecting database $dbName on server {$this->mServer} " .
					"from client host " . wfHostname() . "\n" );

				$this->reportConnectionError( "Error selecting database $dbName" );
			}
		}

		// Tell the server what we're communicating with
		if ( !$this->connectInitCharset() ) {
			$this->reportConnectionError( "Error setting character set" );
		}

		// Abstract over any insane MySQL defaults
		$set = array( 'group_concat_max_len = 262144' );
		// Set SQL mode, default is turning them all off, can be overridden or skipped with null
		if ( is_string( $wgSQLMode ) ) {
			$set[] = 'sql_mode = ' . $this->addQuotes( $wgSQLMode );
		}
		// Set any custom settings defined by site config
		// (e.g. https://dev.mysql.com/doc/refman/4.1/en/innodb-parameters.html)
		foreach ( $this->mSessionVars as $var => $val ) {
			// Escape strings but not numbers to avoid MySQL complaining
			if ( !is_int( $val ) && !is_float( $val ) ) {
				$val = $this->addQuotes( $val );
			}
			$set[] = $this->addIdentifierQuotes( $var ) . ' = ' . $val;
		}

		if ( $set ) {
			// Use doQuery() to avoid opening implicit transactions (DBO_TRX)
			$success = $this->doQuery( 'SET ' . implode( ', ', $set ), __METHOD__ );
			if ( !$success ) {
				wfLogDBError(
					'Error setting MySQL variables on server {db_server} (check $wgSQLMode)',
					$this->getLogContext( array(
						'method' => __METHOD__,
					) )
				);
				$this->reportConnectionError(
					'Error setting MySQL variables on server {db_server} (check $wgSQLMode)' );
			}
		}

		$this->mOpened = true;

		return true;
	}

	/**
	 * Set the character set information right after connection
	 * @return bool
	 */
	protected function connectInitCharset() {
		global $wgDBmysql5;

		if ( $wgDBmysql5 ) {
			// Tell the server we're communicating with it in UTF-8.
			// This may engage various charset conversions.
			return $this->mysqlSetCharset( 'utf8' );
		} else {
			return $this->mysqlSetCharset( 'binary' );
		}
	}

	/**
	 * Open a connection to a MySQL server
	 *
	 * @param string $realServer
	 * @return mixed Raw connection
	 * @throws DBConnectionError
	 */
	abstract protected function mysqlConnect( $realServer );

	/**
	 * Set the character set of the MySQL link
	 *
	 * @param string $charset
	 * @return bool
	 */
	abstract protected function mysqlSetCharset( $charset );

	/**
	 * @param ResultWrapper|resource $res
	 * @throws DBUnexpectedError
	 */
	function freeResult( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}
		MediaWiki\suppressWarnings();
		$ok = $this->mysqlFreeResult( $res );
		MediaWiki\restoreWarnings();
		if ( !$ok ) {
			throw new DBUnexpectedError( $this, "Unable to free MySQL result" );
		}
	}

	/**
	 * Free result memory
	 *
	 * @param resource $res Raw result
	 * @return bool
	 */
	abstract protected function mysqlFreeResult( $res );

	/**
	 * @param ResultWrapper|resource $res
	 * @return stdClass|bool
	 * @throws DBUnexpectedError
	 */
	function fetchObject( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}
		MediaWiki\suppressWarnings();
		$row = $this->mysqlFetchObject( $res );
		MediaWiki\restoreWarnings();

		$errno = $this->lastErrno();
		// Unfortunately, mysql_fetch_object does not reset the last errno.
		// Only check for CR_SERVER_LOST and CR_UNKNOWN_ERROR, as
		// these are the only errors mysql_fetch_object can cause.
		// See http://dev.mysql.com/doc/refman/5.0/en/mysql-fetch-row.html.
		if ( $errno == 2000 || $errno == 2013 ) {
			throw new DBUnexpectedError(
				$this,
				'Error in fetchObject(): ' . htmlspecialchars( $this->lastError() )
			);
		}

		return $row;
	}

	/**
	 * Fetch a result row as an object
	 *
	 * @param resource $res Raw result
	 * @return stdClass
	 */
	abstract protected function mysqlFetchObject( $res );

	/**
	 * @param ResultWrapper|resource $res
	 * @return array|bool
	 * @throws DBUnexpectedError
	 */
	function fetchRow( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}
		MediaWiki\suppressWarnings();
		$row = $this->mysqlFetchArray( $res );
		MediaWiki\restoreWarnings();

		$errno = $this->lastErrno();
		// Unfortunately, mysql_fetch_array does not reset the last errno.
		// Only check for CR_SERVER_LOST and CR_UNKNOWN_ERROR, as
		// these are the only errors mysql_fetch_array can cause.
		// See http://dev.mysql.com/doc/refman/5.0/en/mysql-fetch-row.html.
		if ( $errno == 2000 || $errno == 2013 ) {
			throw new DBUnexpectedError(
				$this,
				'Error in fetchRow(): ' . htmlspecialchars( $this->lastError() )
			);
		}

		return $row;
	}

	/**
	 * Fetch a result row as an associative and numeric array
	 *
	 * @param resource $res Raw result
	 * @return array
	 */
	abstract protected function mysqlFetchArray( $res );

	/**
	 * @throws DBUnexpectedError
	 * @param ResultWrapper|resource $res
	 * @return int
	 */
	function numRows( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}
		MediaWiki\suppressWarnings();
		$n = $this->mysqlNumRows( $res );
		MediaWiki\restoreWarnings();

		// Unfortunately, mysql_num_rows does not reset the last errno.
		// We are not checking for any errors here, since
		// these are no errors mysql_num_rows can cause.
		// See http://dev.mysql.com/doc/refman/5.0/en/mysql-fetch-row.html.
		// See https://bugzilla.wikimedia.org/42430
		return $n;
	}

	/**
	 * Get number of rows in result
	 *
	 * @param resource $res Raw result
	 * @return int
	 */
	abstract protected function mysqlNumRows( $res );

	/**
	 * @param ResultWrapper|resource $res
	 * @return int
	 */
	function numFields( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}

		return $this->mysqlNumFields( $res );
	}

	/**
	 * Get number of fields in result
	 *
	 * @param resource $res Raw result
	 * @return int
	 */
	abstract protected function mysqlNumFields( $res );

	/**
	 * @param ResultWrapper|resource $res
	 * @param int $n
	 * @return string
	 */
	function fieldName( $res, $n ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}

		return $this->mysqlFieldName( $res, $n );
	}

	/**
	 * Get the name of the specified field in a result
	 *
	 * @param ResultWrapper|resource $res
	 * @param int $n
	 * @return string
	 */
	abstract protected function mysqlFieldName( $res, $n );

	/**
	 * mysql_field_type() wrapper
	 * @param ResultWrapper|resource $res
	 * @param int $n
	 * @return string
	 */
	public function fieldType( $res, $n ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}

		return $this->mysqlFieldType( $res, $n );
	}

	/**
	 * Get the type of the specified field in a result
	 *
	 * @param ResultWrapper|resource $res
	 * @param int $n
	 * @return string
	 */
	abstract protected function mysqlFieldType( $res, $n );

	/**
	 * @param ResultWrapper|resource $res
	 * @param int $row
	 * @return bool
	 */
	function dataSeek( $res, $row ) {
		if ( $res instanceof ResultWrapper ) {
			$res = $res->result;
		}

		return $this->mysqlDataSeek( $res, $row );
	}

	/**
	 * Move internal result pointer
	 *
	 * @param ResultWrapper|resource $res
	 * @param int $row
	 * @return bool
	 */
	abstract protected function mysqlDataSeek( $res, $row );

	/**
	 * @return string
	 */
	function lastError() {
		if ( $this->mConn ) {
			# Even if it's non-zero, it can still be invalid
			MediaWiki\suppressWarnings();
			$error = $this->mysqlError( $this->mConn );
			if ( !$error ) {
				$error = $this->mysqlError();
			}
			MediaWiki\restoreWarnings();
		} else {
			$error = $this->mysqlError();
		}
		if ( $error ) {
			$error .= ' (' . $this->mServer . ')';
		}

		return $error;
	}

	/**
	 * Returns the text of the error message from previous MySQL operation
	 *
	 * @param resource $conn Raw connection
	 * @return string
	 */
	abstract protected function mysqlError( $conn = null );

	/**
	 * @param string $table
	 * @param array $uniqueIndexes
	 * @param array $rows
	 * @param string $fname
	 * @return ResultWrapper
	 */
	function replace( $table, $uniqueIndexes, $rows, $fname = __METHOD__ ) {
		return $this->nativeReplace( $table, $rows, $fname );
	}

	/**
	 * Estimate rows in dataset
	 * Returns estimated count, based on EXPLAIN output
	 * Takes same arguments as Database::select()
	 *
	 * @param string|array $table
	 * @param string|array $vars
	 * @param string|array $conds
	 * @param string $fname
	 * @param string|array $options
	 * @return bool|int
	 */
	public function estimateRowCount( $table, $vars = '*', $conds = '',
		$fname = __METHOD__, $options = array()
	) {
		$options['EXPLAIN'] = true;
		$res = $this->select( $table, $vars, $conds, $fname, $options );
		if ( $res === false ) {
			return false;
		}
		if ( !$this->numRows( $res ) ) {
			return 0;
		}

		$rows = 1;
		foreach ( $res as $plan ) {
			$rows *= $plan->rows > 0 ? $plan->rows : 1; // avoid resetting to zero
		}

		return (int)$rows;
	}

	/**
	 * @param string $table
	 * @param string $field
	 * @return bool|MySQLField
	 */
	function fieldInfo( $table, $field ) {
		$table = $this->tableName( $table );
		$res = $this->query( "SELECT * FROM $table LIMIT 1", __METHOD__, true );
		if ( !$res ) {
			return false;
		}
		$n = $this->mysqlNumFields( $res->result );
		for ( $i = 0; $i < $n; $i++ ) {
			$meta = $this->mysqlFetchField( $res->result, $i );
			if ( $field == $meta->name ) {
				return new MySQLField( $meta );
			}
		}

		return false;
	}

	/**
	 * Get column information from a result
	 *
	 * @param resource $res Raw result
	 * @param int $n
	 * @return stdClass
	 */
	abstract protected function mysqlFetchField( $res, $n );

	/**
	 * Get information about an index into an object
	 * Returns false if the index does not exist
	 *
	 * @param string $table
	 * @param string $index
	 * @param string $fname
	 * @return bool|array|null False or null on failure
	 */
	function indexInfo( $table, $index, $fname = __METHOD__ ) {
		# SHOW INDEX works in MySQL 3.23.58, but SHOW INDEXES does not.
		# SHOW INDEX should work for 3.x and up:
		# http://dev.mysql.com/doc/mysql/en/SHOW_INDEX.html
		$table = $this->tableName( $table );
		$index = $this->indexName( $index );

		$sql = 'SHOW INDEX FROM ' . $table;
		$res = $this->query( $sql, $fname );

		if ( !$res ) {
			return null;
		}

		$result = array();

		foreach ( $res as $row ) {
			if ( $row->Key_name == $index ) {
				$result[] = $row;
			}
		}

		return empty( $result ) ? false : $result;
	}

	/**
	 * @param string $s
	 * @return string
	 */
	function strencode( $s ) {
		$sQuoted = $this->mysqlRealEscapeString( $s );

		if ( $sQuoted === false ) {
			$this->ping();
			$sQuoted = $this->mysqlRealEscapeString( $s );
		}

		return $sQuoted;
	}

	/**
	 * MySQL uses `backticks` for identifier quoting instead of the sql standard "double quotes".
	 *
	 * @param string $s
	 * @return string
	 */
	public function addIdentifierQuotes( $s ) {
		// Characters in the range \u0001-\uFFFF are valid in a quoted identifier
		// Remove NUL bytes and escape backticks by doubling
		return '`' . str_replace( array( "\0", '`' ), array( '', '``' ), $s ) . '`';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function isQuotedIdentifier( $name ) {
		return strlen( $name ) && $name[0] == '`' && substr( $name, -1, 1 ) == '`';
	}

	/**
	 * @return bool
	 */
	function ping() {
		$ping = $this->mysqlPing();
		if ( $ping ) {
			// Connection was good or lost but reconnected...
			// @note: mysqlnd (php 5.6+) does not support this (PHP bug 52561)
			return true;
		}

		// Try a full disconnect/reconnect cycle if ping() failed
		$this->closeConnection();
		$this->mOpened = false;
		$this->mConn = false;
		$this->open( $this->mServer, $this->mUser, $this->mPassword, $this->mDBname );

		return true;
	}

	/**
	 * Ping a server connection or reconnect if there is no connection
	 *
	 * @return bool
	 */
	abstract protected function mysqlPing();

	/**
	 * Set lag time in seconds for a fake slave
	 *
	 * @param int $lag
	 */
	public function setFakeSlaveLag( $lag ) {
		$this->mFakeSlaveLag = $lag;
	}

	/**
	 * Make this connection a fake master
	 *
	 * @param bool $enabled
	 */
	public function setFakeMaster( $enabled = true ) {
		$this->mFakeMaster = $enabled;
	}

	/**
	 * Returns slave lag.
	 *
	 * This will do a SHOW SLAVE STATUS
	 *
	 * @return int
	 */
	function getLag() {
		if ( !is_null( $this->mFakeSlaveLag ) ) {
			wfDebug( "getLag: fake slave lagged {$this->mFakeSlaveLag} seconds\n" );

			return $this->mFakeSlaveLag;
		}

		return $this->getLagFromSlaveStatus();
	}

	/**
	 * @return bool|int
	 */
	function getLagFromSlaveStatus() {
		$res = $this->query( 'SHOW SLAVE STATUS', __METHOD__ );
		if ( !$res ) {
			return false;
		}
		$row = $res->fetchObject();
		if ( !$row ) {
			return false;
		}
		if ( strval( $row->Seconds_Behind_Master ) === '' ) {
			return false;
		} else {
			return intval( $row->Seconds_Behind_Master );
		}
	}

	/**
	 * Wait for the slave to catch up to a given master position.
	 * @todo Return values for this and base class are rubbish
	 *
	 * @param DBMasterPos|MySQLMasterPos $pos
	 * @param int $timeout The maximum number of seconds to wait for synchronisation
	 * @return int Zero if the slave was past that position already,
	 *   greater than zero if we waited for some period of time, less than
	 *   zero if we timed out.
	 */
	function masterPosWait( DBMasterPos $pos, $timeout ) {
		if ( $this->lastKnownSlavePos && $this->lastKnownSlavePos->hasReached( $pos ) ) {
			return '0'; // http://dev.mysql.com/doc/refman/5.0/en/miscellaneous-functions.html
		}

		# Commit any open transactions
		$this->commit( __METHOD__, 'flush' );

		if ( !is_null( $this->mFakeSlaveLag ) ) {
			$wait = intval( ( $pos->pos - microtime( true ) + $this->mFakeSlaveLag ) * 1e6 );

			if ( $wait > $timeout * 1e6 ) {
				wfDebug( "Fake slave timed out waiting for $pos ($wait us)\n" );

				return -1;
			} elseif ( $wait > 0 ) {
				wfDebug( "Fake slave waiting $wait us\n" );
				usleep( $wait );

				return 1;
			} else {
				wfDebug( "Fake slave up to date ($wait us)\n" );

				return 0;
			}
		}

		# Call doQuery() directly, to avoid opening a transaction if DBO_TRX is set
		$encFile = $this->addQuotes( $pos->file );
		$encPos = intval( $pos->pos );
		$sql = "SELECT MASTER_POS_WAIT($encFile, $encPos, $timeout)";
		$res = $this->doQuery( $sql );

		$status = false;
		if ( $res && $row = $this->fetchRow( $res ) ) {
			$status = $row[0]; // can be NULL, -1, or 0+ per the MySQL manual
			if ( ctype_digit( $status ) ) { // success
				$this->lastKnownSlavePos = $pos;
			}
		}

		return $status;
	}

	/**
	 * Get the position of the master from SHOW SLAVE STATUS
	 *
	 * @return MySQLMasterPos|bool
	 */
	function getSlavePos() {
		if ( !is_null( $this->mFakeSlaveLag ) ) {
			$pos = new MySQLMasterPos( 'fake', microtime( true ) - $this->mFakeSlaveLag );
			wfDebug( __METHOD__ . ": fake slave pos = $pos\n" );

			return $pos;
		}

		$res = $this->query( 'SHOW SLAVE STATUS', 'DatabaseBase::getSlavePos' );
		$row = $this->fetchObject( $res );

		if ( $row ) {
			$pos = isset( $row->Exec_master_log_pos )
				? $row->Exec_master_log_pos
				: $row->Exec_Master_Log_Pos;

			return new MySQLMasterPos( $row->Relay_Master_Log_File, $pos );
		} else {
			return false;
		}
	}

	/**
	 * Get the position of the master from SHOW MASTER STATUS
	 *
	 * @return MySQLMasterPos|bool
	 */
	function getMasterPos() {
		if ( $this->mFakeMaster ) {
			return new MySQLMasterPos( 'fake', microtime( true ) );
		}

		$res = $this->query( 'SHOW MASTER STATUS', 'DatabaseBase::getMasterPos' );
		$row = $this->fetchObject( $res );

		if ( $row ) {
			return new MySQLMasterPos( $row->File, $row->Position );
		} else {
			return false;
		}
	}

	/**
	 * @param string $index
	 * @return string
	 */
	function useIndexClause( $index ) {
		return "FORCE INDEX (" . $this->indexName( $index ) . ")";
	}

	/**
	 * @return string
	 */
	function lowPriorityOption() {
		return 'LOW_PRIORITY';
	}

	/**
	 * @return string
	 */
	public function getSoftwareLink() {
		// MariaDB includes its name in its version string; this is how MariaDB's version of
		// the mysql command-line client identifies MariaDB servers (see mariadb_connection()
		// in libmysql/libmysql.c).
		$version = $this->getServerVersion();
		if ( strpos( $version, 'MariaDB' ) !== false || strpos( $version, '-maria-' ) !== false ) {
			return '[{{int:version-db-mariadb-url}} MariaDB]';
		}

		// Percona Server's version suffix is not very distinctive, and @@version_comment
		// doesn't give the necessary info for source builds, so assume the server is MySQL.
		// (Even Percona's version of mysql doesn't try to make the distinction.)
		return '[{{int:version-db-mysql-url}} MySQL]';
	}

	/**
	 * @return string
	 */
	public function getServerVersion() {
		// Not using mysql_get_server_info() or similar for consistency: in the handshake,
		// MariaDB 10 adds the prefix "5.5.5-", and only some newer client libraries strip
		// it off (see RPL_VERSION_HACK in include/mysql_com.h).
		if ( $this->serverVersion === null ) {
			$this->serverVersion = $this->selectField( '', 'VERSION()', '', __METHOD__ );
		}
		return $this->serverVersion;
	}

	/**
	 * @param array $options
	 */
	public function setSessionOptions( array $options ) {
		if ( isset( $options['connTimeout'] ) ) {
			$timeout = (int)$options['connTimeout'];
			$this->query( "SET net_read_timeout=$timeout" );
			$this->query( "SET net_write_timeout=$timeout" );
		}
	}

	/**
	 * @param string $sql
	 * @param string $newLine
	 * @return bool
	 */
	public function streamStatementEnd( &$sql, &$newLine ) {
		if ( strtoupper( substr( $newLine, 0, 9 ) ) == 'DELIMITER' ) {
			preg_match( '/^DELIMITER\s+(\S+)/', $newLine, $m );
			$this->delimiter = $m[1];
			$newLine = '';
		}

		return parent::streamStatementEnd( $sql, $newLine );
	}

	/**
	 * Check to see if a named lock is available. This is non-blocking.
	 *
	 * @param string $lockName Name of lock to poll
	 * @param string $method Name of method calling us
	 * @return bool
	 * @since 1.20
	 */
	public function lockIsFree( $lockName, $method ) {
		$lockName = $this->addQuotes( $lockName );
		$result = $this->query( "SELECT IS_FREE_LOCK($lockName) AS lockstatus", $method );
		$row = $this->fetchObject( $result );

		return ( $row->lockstatus == 1 );
	}

	/**
	 * @param string $lockName
	 * @param string $method
	 * @param int $timeout
	 * @return bool
	 */
	public function lock( $lockName, $method, $timeout = 5 ) {
		$lockName = $this->addQuotes( $lockName );
		$result = $this->query( "SELECT GET_LOCK($lockName, $timeout) AS lockstatus", $method );
		$row = $this->fetchObject( $result );

		if ( $row->lockstatus == 1 ) {
			return true;
		} else {
			wfDebug( __METHOD__ . " failed to acquire lock\n" );

			return false;
		}
	}

	/**
	 * FROM MYSQL DOCS:
	 * http://dev.mysql.com/doc/refman/5.0/en/miscellaneous-functions.html#function_release-lock
	 * @param string $lockName
	 * @param string $method
	 * @return bool
	 */
	public function unlock( $lockName, $method ) {
		$lockName = $this->addQuotes( $lockName );
		$result = $this->query( "SELECT RELEASE_LOCK($lockName) as lockstatus", $method );
		$row = $this->fetchObject( $result );

		return ( $row->lockstatus == 1 );
	}

	public function namedLocksEnqueue() {
		return true;
	}

	/**
	 * @param array $read
	 * @param array $write
	 * @param string $method
	 * @param bool $lowPriority
	 * @return bool
	 */
	public function lockTables( $read, $write, $method, $lowPriority = true ) {
		$items = array();

		foreach ( $write as $table ) {
			$tbl = $this->tableName( $table ) .
				( $lowPriority ? ' LOW_PRIORITY' : '' ) .
				' WRITE';
			$items[] = $tbl;
		}
		foreach ( $read as $table ) {
			$items[] = $this->tableName( $table ) . ' READ';
		}
		$sql = "LOCK TABLES " . implode( ',', $items );
		$this->query( $sql, $method );

		return true;
	}

	/**
	 * @param string $method
	 * @return bool
	 */
	public function unlockTables( $method ) {
		$this->query( "UNLOCK TABLES", $method );

		return true;
	}

	/**
	 * Get search engine class. All subclasses of this
	 * need to implement this if they wish to use searching.
	 *
	 * @return string
	 */
	public function getSearchEngine() {
		return 'SearchMySQL';
	}

	/**
	 * @param bool $value
	 */
	public function setBigSelects( $value = true ) {
		if ( $value === 'default' ) {
			if ( $this->mDefaultBigSelects === null ) {
				# Function hasn't been called before so it must already be set to the default
				return;
			} else {
				$value = $this->mDefaultBigSelects;
			}
		} elseif ( $this->mDefaultBigSelects === null ) {
			$this->mDefaultBigSelects = (bool)$this->selectField( false, '@@sql_big_selects' );
		}
		$encValue = $value ? '1' : '0';
		$this->query( "SET sql_big_selects=$encValue", __METHOD__ );
	}

	/**
	 * DELETE where the condition is a join. MySql uses multi-table deletes.
	 * @param string $delTable
	 * @param string $joinTable
	 * @param string $delVar
	 * @param string $joinVar
	 * @param array|string $conds
	 * @param bool|string $fname
	 * @throws DBUnexpectedError
	 * @return bool|ResultWrapper
	 */
	function deleteJoin( $delTable, $joinTable, $delVar, $joinVar, $conds, $fname = __METHOD__ ) {
		if ( !$conds ) {
			throw new DBUnexpectedError( $this, 'DatabaseBase::deleteJoin() called with empty $conds' );
		}

		$delTable = $this->tableName( $delTable );
		$joinTable = $this->tableName( $joinTable );
		$sql = "DELETE $delTable FROM $delTable, $joinTable WHERE $delVar=$joinVar ";

		if ( $conds != '*' ) {
			$sql .= ' AND ' . $this->makeList( $conds, LIST_AND );
		}

		return $this->query( $sql, $fname );
	}

	/**
	 * @param string $table
	 * @param array $rows
	 * @param array $uniqueIndexes
	 * @param array $set
	 * @param string $fname
	 * @return bool
	 */
	public function upsert( $table, array $rows, array $uniqueIndexes,
		array $set, $fname = __METHOD__
	) {
		if ( !count( $rows ) ) {
			return true; // nothing to do
		}

		if ( !is_array( reset( $rows ) ) ) {
			$rows = array( $rows );
		}

		$table = $this->tableName( $table );
		$columns = array_keys( $rows[0] );

		$sql = "INSERT INTO $table (" . implode( ',', $columns ) . ') VALUES ';
		$rowTuples = array();
		foreach ( $rows as $row ) {
			$rowTuples[] = '(' . $this->makeList( $row ) . ')';
		}
		$sql .= implode( ',', $rowTuples );
		$sql .= " ON DUPLICATE KEY UPDATE " . $this->makeList( $set, LIST_SET );

		return (bool)$this->query( $sql, $fname );
	}

	/**
	 * Determines how long the server has been up
	 *
	 * @return int
	 */
	function getServerUptime() {
		$vars = $this->getMysqlStatus( 'Uptime' );

		return (int)$vars['Uptime'];
	}

	/**
	 * Determines if the last failure was due to a deadlock
	 *
	 * @return bool
	 */
	function wasDeadlock() {
		return $this->lastErrno() == 1213;
	}

	/**
	 * Determines if the last failure was due to a lock timeout
	 *
	 * @return bool
	 */
	function wasLockTimeout() {
		return $this->lastErrno() == 1205;
	}

	/**
	 * Determines if the last query error was something that should be dealt
	 * with by pinging the connection and reissuing the query
	 *
	 * @return bool
	 */
	function wasErrorReissuable() {
		return $this->lastErrno() == 2013 || $this->lastErrno() == 2006;
	}

	/**
	 * Determines if the last failure was due to the database being read-only.
	 *
	 * @return bool
	 */
	function wasReadOnlyError() {
		return $this->lastErrno() == 1223 ||
			( $this->lastErrno() == 1290 && strpos( $this->lastError(), '--read-only' ) !== false );
	}

	/**
	 * Get the underlying binding handle, mConn
	 *
	 * Makes sure that mConn is set (disconnects and ping() failure can unset it).
	 * This catches broken callers than catch and ignore disconnection exceptions.
	 * Unlike checking isOpen(), this is safe to call inside of open().
	 *
	 * @return resource|object
	 * @throws DBUnexpectedError
	 * @since 1.26
	 */
	protected function getBindingHandle() {
		if ( !$this->mConn ) {
			throw new DBUnexpectedError(
				$this,
				'DB connection was already closed or the connection dropped.'
			);
		}

		return $this->mConn;
	}

	/**
	 * @param string $oldName
	 * @param string $newName
	 * @param bool $temporary
	 * @param string $fname
	 * @return bool
	 */
	function duplicateTableStructure( $oldName, $newName, $temporary = false, $fname = __METHOD__ ) {
		$tmp = $temporary ? 'TEMPORARY ' : '';
		$newName = $this->addIdentifierQuotes( $newName );
		$oldName = $this->addIdentifierQuotes( $oldName );
		$query = "CREATE $tmp TABLE $newName (LIKE $oldName)";

		return $this->query( $query, $fname );
	}

	/**
	 * List all tables on the database
	 *
	 * @param string $prefix Only show tables with this prefix, e.g. mw_
	 * @param string $fname Calling function name
	 * @return array
	 */
	function listTables( $prefix = null, $fname = __METHOD__ ) {
		$result = $this->query( "SHOW TABLES", $fname );

		$endArray = array();

		foreach ( $result as $table ) {
			$vars = get_object_vars( $table );
			$table = array_pop( $vars );

			if ( !$prefix || strpos( $table, $prefix ) === 0 ) {
				$endArray[] = $table;
			}
		}

		return $endArray;
	}

	/**
	 * @param string $tableName
	 * @param string $fName
	 * @return bool|ResultWrapper
	 */
	public function dropTable( $tableName, $fName = __METHOD__ ) {
		if ( !$this->tableExists( $tableName, $fName ) ) {
			return false;
		}

		return $this->query( "DROP TABLE IF EXISTS " . $this->tableName( $tableName ), $fName );
	}

	/**
	 * @return array
	 */
	protected function getDefaultSchemaVars() {
		$vars = parent::getDefaultSchemaVars();
		$vars['wgDBTableOptions'] = str_replace( 'TYPE', 'ENGINE', $GLOBALS['wgDBTableOptions'] );
		$vars['wgDBTableOptions'] = str_replace(
			'CHARSET=mysql4',
			'CHARSET=binary',
			$vars['wgDBTableOptions']
		);

		return $vars;
	}

	/**
	 * Get status information from SHOW STATUS in an associative array
	 *
	 * @param string $which
	 * @return array
	 */
	function getMysqlStatus( $which = "%" ) {
		$res = $this->query( "SHOW STATUS LIKE '{$which}'" );
		$status = array();

		foreach ( $res as $row ) {
			$status[$row->Variable_name] = $row->Value;
		}

		return $status;
	}

	/**
	 * Lists VIEWs in the database
	 *
	 * @param string $prefix Only show VIEWs with this prefix, eg.
	 * unit_test_, or $wgDBprefix. Default: null, would return all views.
	 * @param string $fname Name of calling function
	 * @return array
	 * @since 1.22
	 */
	public function listViews( $prefix = null, $fname = __METHOD__ ) {

		if ( !isset( $this->allViews ) ) {

			// The name of the column containing the name of the VIEW
			$propertyName = 'Tables_in_' . $this->mDBname;

			// Query for the VIEWS
			$result = $this->query( 'SHOW FULL TABLES WHERE TABLE_TYPE = "VIEW"' );
			$this->allViews = array();
			while ( ( $row = $this->fetchRow( $result ) ) !== false ) {
				array_push( $this->allViews, $row[$propertyName] );
			}
		}

		if ( is_null( $prefix ) || $prefix === '' ) {
			return $this->allViews;
		}

		$filteredViews = array();
		foreach ( $this->allViews as $viewName ) {
			// Does the name of this VIEW start with the table-prefix?
			if ( strpos( $viewName, $prefix ) === 0 ) {
				array_push( $filteredViews, $viewName );
			}
		}

		return $filteredViews;
	}

	/**
	 * Differentiates between a TABLE and a VIEW.
	 *
	 * @param string $name Name of the TABLE/VIEW to test
	 * @param string $prefix
	 * @return bool
	 * @since 1.22
	 */
	public function isView( $name, $prefix = null ) {
		return in_array( $name, $this->listViews( $prefix ) );
	}
}

/**
 * Utility class.
 * @ingroup Database
 */
class MySQLField implements Field {
	private $name, $tablename, $default, $max_length, $nullable,
		$is_pk, $is_unique, $is_multiple, $is_key, $type, $binary,
		$is_numeric, $is_blob, $is_unsigned, $is_zerofill;

	function __construct( $info ) {
		$this->name = $info->name;
		$this->tablename = $info->table;
		$this->default = $info->def;
		$this->max_length = $info->max_length;
		$this->nullable = !$info->not_null;
		$this->is_pk = $info->primary_key;
		$this->is_unique = $info->unique_key;
		$this->is_multiple = $info->multiple_key;
		$this->is_key = ( $this->is_pk || $this->is_unique || $this->is_multiple );
		$this->type = $info->type;
		$this->binary = isset( $info->binary ) ? $info->binary : false;
		$this->is_numeric = isset( $info->numeric ) ? $info->numeric : false;
		$this->is_blob = isset( $info->blob ) ? $info->blob : false;
		$this->is_unsigned = isset( $info->unsigned ) ? $info->unsigned : false;
		$this->is_zerofill = isset( $info->zerofill ) ? $info->zerofill : false;
	}

	/**
	 * @return string
	 */
	function name() {
		return $this->name;
	}

	/**
	 * @return string
	 */
	function tableName() {
		return $this->tableName;
	}

	/**
	 * @return string
	 */
	function type() {
		return $this->type;
	}

	/**
	 * @return bool
	 */
	function isNullable() {
		return $this->nullable;
	}

	function defaultValue() {
		return $this->default;
	}

	/**
	 * @return bool
	 */
	function isKey() {
		return $this->is_key;
	}

	/**
	 * @return bool
	 */
	function isMultipleKey() {
		return $this->is_multiple;
	}

	/**
	 * @return bool
	 */
	function isBinary() {
		return $this->binary;
	}

	/**
	 * @return bool
	 */
	function isNumeric() {
		return $this->is_numeric;
	}

	/**
	 * @return bool
	 */
	function isBlob() {
		return $this->is_blob;
	}

	/**
	 * @return bool
	 */
	function isUnsigned() {
		return $this->is_unsigned;
	}

	/**
	 * @return bool
	 */
	function isZerofill() {
		return $this->is_zerofill;
	}
}

class MySQLMasterPos implements DBMasterPos {
	/** @var string */
	public $file;
	/** @var int Position */
	public $pos;
	/** @var float UNIX timestamp */
	public $asOfTime = 0.0;

	function __construct( $file, $pos ) {
		$this->file = $file;
		$this->pos = $pos;
		$this->asOfTime = microtime( true );
	}

	function __toString() {
		// e.g db1034-bin.000976/843431247
		return "{$this->file}/{$this->pos}";
	}

	/**
	 * @return array|bool (int, int)
	 */
	protected function getCoordinates() {
		$m = array();
		if ( preg_match( '!\.(\d+)/(\d+)$!', (string)$this, $m ) ) {
			return array( (int)$m[1], (int)$m[2] );
		}

		return false;
	}

	function hasReached( MySQLMasterPos $pos ) {
		$thisPos = $this->getCoordinates();
		$thatPos = $pos->getCoordinates();

		return ( $thisPos && $thatPos && $thisPos >= $thatPos );
	}

	function asOfTime() {
		return $this->asOfTime;
	}
}
