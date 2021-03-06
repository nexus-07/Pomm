<?php

namespace Pomm\Connection;

use Pomm\Exception\ConnectionException;
use Pomm\Connection\Database;
use Pomm\Identity\IdentityMapperInterface;
use Pomm\Object\BaseObjectMap;
use Pomm\Query\PreparedQuery;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Pomm\Connection\Connection
 *
 * Manage a connection to the database.
 * Connection is a pool of Map and PreparedQuery instances. It holds the IdentityMapper.
 * Connection also proposes handy methods to deal with transactions.
 *
 * @package Pomm
 * @version $id$
 * @copyright 2011 - 2013 Grégoire HUBERT
 * @author Grégoire HUBERT <hubert.greg@gmail.com>
 * @license X11 {@link http://opensource.org/licenses/mit-license.php}
 */
class Connection implements LoggerAwareInterface
{
    const ISOLATION_READ_COMMITTED = "READ COMMITTED";
    const ISOLATION_READ_REPEATABLE = "READ REPEATABLE"; // from Pg 9.1
    const ISOLATION_SERIALIZABLE = "SERIALIZABLE";

    protected $handler;
    protected $database;
    protected $parameter_holder;
    protected $isolation;
    protected $identity_mapper;
    protected $maps = array();
    protected $queries = array();
    protected $logger;

    /**
     * __construct()
     *
     * Connection instance to the specified database.
     *
     * @access public
     * @param Database                  $database   The Database instance.
     * @param IdentityMapperInterface   $mapper     The optional identity mapper instance.
     */
    public function __construct(Database $database, IdentityMapperInterface $mapper = null)
    {
        $this->database = $database;
        $this->parameter_holder = $database->getParameterHolder();

        $this->parameter_holder->setDefaultValue('isolation', self::ISOLATION_READ_COMMITTED);
        $this->parameter_holder->mustBeOneOf('isolation',
            array(self::ISOLATION_READ_COMMITTED, self::ISOLATION_SERIALIZABLE, self::ISOLATION_READ_REPEATABLE)
        );

        $this->isolation = $this->parameter_holder['isolation'];
        $this->parameter_holder->setDefaultValue('identity_mapper', false);

        if (is_null($mapper))
        {
            if ($this->parameter_holder['identity_mapper'] !== false)
            {
                $identity_class = $this->parameter_holder['identity_mapper'] === true ? 'Pomm\Identity\IdentityMapperSmart' : $this->parameter_holder['identity_mapper'];

                $this->identity_mapper = new $identity_class();
            }
        }
        else
        {
            $this->identity_mapper = $mapper;
        }
    }

    /**
     * launch
     *
     * Open a connection on the database.
     *
     * @access protected
     */
    protected function launch()
    {
        $connect_parameters = array(sprintf("user=%s dbname=%s", $this->parameter_holder['user'], $this->parameter_holder['database']));

        if ($this->parameter_holder['host'] !== '')
        {
            $connect_parameters[] = sprintf('host=%s', $this->parameter_holder['host']);
        }

        if ($this->parameter_holder['port'] !== '')
        {
            $connect_parameters[] = sprintf('port=%s', $this->parameter_holder['port']);
        }

        if ($this->parameter_holder['pass'] !== '')
        {
            $connect_parameters[] = sprintf('password=%s', addslashes($this->parameter_holder['pass']));
        }

        $this->handler = pg_connect(join(' ', $connect_parameters), \PGSQL_CONNECT_FORCE_NEW);

        if ($this->handler === false)
        {
            $this->throwConnectionException(sprintf("Error connecting to the database with dsn '%s'.", join(' ', $connect_parameters)), LogLevel::ALERT);
        }

        $sql = '';

        foreach ($this->database->getConfiguration() as $setting => $value)
        {
            $sql .= sprintf("SET %s = \"%s\";", $setting, $value);
        }

        if (pg_query($this->handler, $sql) === false)
        {
            $this->throwConnectionException(sprintf("Error while applying settings '%s'.", $sql), LogLevel::CRITICAL);
        }
    }

    /**
     * __destruct
     *
     * Destroy the database connection resource.
     *
     * @return void
     */
    public function __destruct()
    {
        unset($this->handler);
    }

    /**
     * getHandler
     *
     * Returns the resource of the associated connection.
     *
     * @access public
     * @return Resource
     */
    public function getHandler()
    {
        if (!isset($this->handler))
        {
            $this->launch();
        }

        return $this->handler;
    }

    /**
     * getMapFor
     *
     * Returns a Map instance of the given model name from the pool. If such
     * instance does not exist, create it.
     *
     * @access public
     * @param  String           $class The fully qualified class name of the associated entity.
     * @param  Bool             $force Force the creation of a new Map instance.
     * @return BaseObjectMap
     */
    public function getMapFor($class, $force = false)
    {
        $class = trim($class, '\\');
        $class_name = $class.'Map';

        if ($force === true or !array_key_exists($class, $this->maps))
        {
            $this->maps[$class] = new $class_name($this);
        }

        return $this->maps[$class];
    }

    /**
     * getDatabase
     *
     * Returns the connection's database.
     *
     * @access public
     * @return Database
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * setLogger
     *
     * Register a logger.
     * Unfortunately, the specification says this method returns null. This
     * prevents us from returning a handy $this.
     *
     * @access public
     * @param LoggerInterface
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * begin
     *
     * Start a new transaction.
     *
     * @access public
     * @return Connection $this
     */
    public function begin()
    {
        if ($this->executeAnonymousQuery(sprintf("BEGIN TRANSACTION ISOLATION LEVEL %s", $this->isolation)) === false)
        {
            $this->throwConnectionException(sprintf("Cannot begin transaction (isolation level '%s').", $this->isolation), LogLevel::ERROR);
        }

        return $this;
    }

    /**
     * commit
     *
     * Commit a transaction in the database.
     *
     * @access public
     * @return Connection $this
     */
    public function commit()
    {
        if ($this->executeAnonymousQuery('COMMIT TRANSACTION') === false)
        {
            $this->throwConnectionException(sprintf("Cannot commit transaction (isolation level '%s').", $this->isolation), LogLevel::ERROR);
        }

        return $this;
    }

    /**
     * rollback
     *
     * Rollback a transaction. This can be the whole transaction
     * or, if a savepoint name is specified, only the queries since
     * this savepoint.
     *
     * @access public
     * @param  String       $name Optional name of the savepoint.
     * @return Connection $this
     */
    public function rollback($name = null)
    {
        if (is_null($name))
        {
            $ret = $this->executeAnonymousQuery('ROLLBACK TRANSACTION');
        }
        else
        {
            $ret = $this->executeAnonymousQuery(sprintf("ROLLBACK TO SAVEPOINT %s", $this->escapeIdentifier($name)));
        }

        if ($ret === false)
        {
            $this->throwConnectionException(sprintf("Cannot rollback transaction (isolation level '%s').", $this->isolation), LogLevel::ERROR);
        }

        return $this;
    }

    /**
     * setSavepoint
     *
     * Set a new savepoint with the given name.
     *
     * @access public
     * @param  String       $name Savepoint's name.
     * @return Connection $this
     */
    public function setSavepoint($name)
    {
        if ($this->executeAnonymousQuery(sprintf("SAVEPOINT %s", $this->escapeIdentifier($name))) === false)
        {
            throw new ConnectionException(sprintf("Cannot set savepoint '%s'.", $name), LogLevel::ERROR);
        }

        return $this;
    }

    /**
     * releaseSavepoint
     *
     * Forget the specified savepoint.
     *
     * @access public
     * @param  String       $name the savepoint's name.
     * @return Connection $this
     */
    public function releaseSavepoint($name)
    {
        if ($this->executeAnonymousQuery(sprintf("RELEASE SAVEPOINT %s", $this->escapeIdentifier($name))) === false)
        {
            throw new ConnectionException(sprintf("Cannot release savepoint named '%s'.", $name), LogLevel::ERROR);
        }

        return $this;
    }

    /**
     * isInTransaction
     *
     * Check if we are in transaction mode.
     *
     * @access public
     * @return boolean
     */
    public function isInTransaction()
    {
        return (bool) (pg_transaction_status($this->getHandler()) !== \PGSQL_TRANSACTION_IDLE);
    }

    /**
     * getTransactionStatus
     *
     * Return the transaction status of the connection.
     *
     * @access public
     * @link   see http://fr2.php.net/manual/en/function.pg-transaction-status.php
     * @return Integer
     */
    public function getTransactionStatus()
    {
        return pg_transaction_status($this->getHandler());
    }

    /**
     * createObserver
     *
     * Return an observer object. This is a convenient method to create an
     * observer and chain methods.
     *
     * @access public
     * @return Observer
     */
    public function createObserver()
    {
        return new Observer($this);
    }

    /**
     * notify
     *
     * Send a server notification.
     *
     * @access public
     * @link see http://www.postgresql.org/docs/9.0/static/sql-notify.html
     * @param  String $name the notification name
     * @param  Sting  $payload optionnal transmitted data
     */
    public function notify($name, $payload = null)
    {
        $name = $this->escapeIdentifier($name);

        if (empty($payload))
        {
            $ret = $this->executeAnonymousQuery(sprintf("NOTIFY %s", $name));
        }
        else
        {
            $ret = $this->executeAnonymousQuery(sprintf("NOTIFY %s, %s", $name,  $this->escapeLiteral($payload)));
        }

        if ($ret === false)
        {
            $this->throwConnectionException(sprintf("Could not notify '%s' event.", $name), LogLevel::ERROR);
        }
    }

    /**
     * createPreparedQuery
     *
     * @access public
     * @param String $sql Statement to prepare.
     * @return PreparedQuery
     */
    public function createPreparedQuery($sql)
    {
        return new PreparedQuery($this, $sql);
    }

    /**
     * getIdentityMapper
     *
     * Get connection's related identity mapper.
     *
     * @access public
     * @return IdentityMapperInterface
     */
    public function getIdentityMapper()
    {
        return $this->identity_mapper;
    }

    /**
     * query
     *
     * performs a prepared sql statement
     *
     * @access public
     * @param  String           $sql    The sql statement
     * @param  Array            $values Values to be escaped (default [])
     * @return Resource
     */
    public function query($sql, Array $values = array())
    {
        return $this->getQuery($sql)->execute($values);
    }

    /**
     * getquery
     *
     * Return a prepared query from a sql signature. If no query match the
     * signature in the pool, a new query is prepared.
     *
     * @access public
     * @return PreparedQuery
     */
    public function getQuery($sql)
    {
        $signature = PreparedQuery::getSignatureFor($sql);

        if ($this->hasQuery($signature) === false)
        {
            $query = $this->createPreparedQuery($sql);
            $this->queries[$query->getName()] = $query;
        }

        return $this->queries[$signature];
    }

    /**
     * hasQuery
     *
     * Check if a query with the given signature exists in the pool.
     *
     * @access public
     * @param  String $name Query signature.
     * @return Boolean
     */
    public function hasQuery($name)
    {
        return (bool) isset($this->queries[$name]);
    }

    /**
     * executeAnonymousQuery
     *
     * Performs a raw SQL query
     *
     * @access public
     * @param  String   $sql The sql statement to execute.
     * @return Resource
     */
    public function executeAnonymousQuery($sql)
    {
        $this->log(LogLevel::NOTICE, sprintf("Anonymous query « %s ».", $sql));

        return @pg_query($this->getHandler(), $sql);
    }

    /**
     * executeParametrizedQuery
     *
     * Performs a SQL query with parameters (more secure).
     *
     * @access public
     * @param  String $sql     Sql statement to execute.
     * @param  Array  $values  Query parameters.
     * @return Resource
     */
    public function executeParametrizedQuery($sql, $values)
    {
        $this->log(LogLevel::NOTICE, sprintf("Parametrized anonymous query « %s ».", $sql));

        return @pg_query_params($sql, $values);
    }

    /**
     * log
     *
     * If a logger is defined, log the given message.
     * @see https://github.com/php-fig/log/blob/master/Psr/Log/LoggerInterface.php
     *
     * @param String level
     * @param String message
     * @param Array  environnment
     * @return Connection $this
     */
    public function log($level, $message, Array $env = array())
    {
        if (isset($this->logger))
        {
            $this->logger->log($level, $message, $env + array('connection' => $this));
        }

        return $this;
    }

    /**
     * throwConnectionException
     *
     * Log error message and throw a ConnectionException
     *
     * @param String Error message
     * @param String Error level
     */
    public function throwConnectionException($message, $level)
    {
        $e = new ConnectionException($message);
        $this->log($level, $message, array('exception' => $e));

        throw $e;
    }

    /**
     * escapeIdentifier
     *
     * Escape database object's names. This is different from value escaping
     * as objects names are surrounded by double quotes. API function does
     * provide a nice escaping with -- hopefully -- UTF8 support. This function
     * is only available from PHP 5.4.4, a simplistic fallback is provided but
     * it only cares about double quotes escaping.
     *
     * @see http://www.postgresql.org/docs/current/static/sql-syntax-lexical.html
     * @access public
     * @param String $name The string to be escaped.
     * @return String the escaped string.
     */
    public function escapeIdentifier($name)
    {
        if (function_exists('pg_escape_identifier'))
        {
            return \pg_escape_identifier($this->getHandler(), $name);
        }

        return sprintf('"%s"', str_replace('"', '""', $name));
    }

    /**
     * escapeLiteral
     *
     * Escape a text value.
     *
     * @access public
     * @param String The string to be escaped
     * @return String the escaped string.
     */
    public function escapeLiteral($var)
    {
        if (function_exists('pg_escape_literal'))
        {
            return \pg_escape_literal($this->getHandler(), $var);
        }

        return sprintf("'%s'", \pg_escape_string($this->getHandler(), $var));
    }
}
