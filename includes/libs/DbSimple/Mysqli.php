<?php
/**
 * DbSimple_Mysql: MySQL database.
 * (C) Dk Lab, http://en.dklab.ru
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * See http://www.gnu.org/copyleft/lesser.html
 *
 * Placeholders end blobs are emulated.
 *
 * @author Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 * @author Konstantin Zhinko, http://forum.dklab.ru/users/KonstantinGinkoTit/
 *
 * @version 2.x $Id: Mysqli.php 247 2008-08-18 21:17:08Z dk $
 */
require_once __DIR__.'/Database.php';


/**
 * Database class for MySQL.
 */
class DbSimple_Mysqli extends DbSimple_Database
{
    public $attributes = [];    // declare global property to avoid PHP > 8.0 warning
    var $link;

    private $_lastQuery;
    private $_dsn;
    private $postConnectCallback;   // re-applied after every (re)connect

    /**
     * constructor(string $dsn)
     * Connect to MySQL server.
     */
    function __construct($dsn)
    {
        $this->_dsn = $dsn;
        $this->_connect();
    }

    private function _connect() : void
    {
        // reset any stale connection so a failed (re)connect can't leave a dead mysqli in place
        $this->link = false;

        $dsn = $this->_dsn;

        if (!is_callable("mysqli_connect"))
        {
            $this->_setLastError("-1", "MySQLi extension is not loaded", "mysqli_connect");
            return;
        }

        if (!empty($dsn["persist"])) {
            if (version_compare(PHP_VERSION, '5.3') < 0) {
                $this->_setLastError("-1", "Persistent connections in MySQLi is allowable since PHP 5.3", "mysqli_connect");
                return;
            } else {
                $dsn["host"] = "p:".$dsn["host"];
            }
        }

        if ( isset($dsn['socket']) ) {
            // Socket connection
            $this->link = mysqli_connect(
                null                                         // host
                ,empty($dsn['user']) ? 'root' : $dsn['user'] // user
                ,empty($dsn['pass']) ? '' : $dsn['pass']     // password
                ,preg_replace('{^/}s', '', $dsn['path'])     // schema
                ,null                                        // port
                ,$dsn['socket']                              // socket
            );
        } else if (isset($dsn['host']) ) {
            // Host connection
            $this->link = mysqli_connect(
                $dsn['host']
                ,empty($dsn['user']) ? 'root' : $dsn['user']
                ,empty($dsn['pass']) ? '' : $dsn['pass']
                ,preg_replace('{^/}s', '', $dsn['path'])
                ,empty($dsn['port']) ? null : $dsn['port']
            );
        } else {
            $this->_setDbError('mysqli_connect()');
            return;
        }
        $this->_resetLastError();
        if (!$this->link) { $this->_setDbError('mysqli_connect()'); return; }

        mysqli_set_charset($this->link, isset($dsn['enc']) ? $dsn['enc'] : 'UTF8');

        // re-apply session settings (e.g. utf8mb4, sql_mode) on every (re)connect
        if (is_callable($this->postConnectCallback))
            call_user_func($this->postConnectCallback, $this);
    }


    /**
     * Register an optional callback invoked after every (re)connect.
     * Receives this DbSimple_Mysqli instance.
     */
    public function setPostConnectCallback($callback)
    {
        $this->postConnectCallback = $callback;
    }


    protected function _performEscape($s, $isIdent=false)
    {
        // without a live connection, escape without mysqli_real_escape_string (PHP 8 TypeError)
        if (!$isIdent)
            return "'" . ($this->link instanceof \mysqli ? mysqli_real_escape_string($this->link, $s) : addslashes((string)$s)) . "'";
        else
            return "`" . str_replace('`', '``', $s) . "`";
    }


    protected function _performNewBlob($blobid=null)
    {
        return new DbSimple_Mysqli_Blob($this, $blobid);
    }


    protected function _performGetBlobFieldNames($result)
    {
        $allFields = mysqli_fetch_fields($result);
        $blobFields = array();

        if (!empty($allFields))
        {
            foreach ($allFields as $field)
                if (stripos($field["type"], "BLOB") !== false)
                    $blobFields[] = $field["name"];
        }
        return $blobFields;
    }


    protected function _performGetPlaceholderIgnoreRe()
    {
        return '
            "   (?> [^"\\\\]+|\\\\"|\\\\)*    "   |
            \'  (?> [^\'\\\\]+|\\\\\'|\\\\)* \'   |
            `   (?> [^`]+ | ``)*              `   |   # backticks
            /\* .*?                          \*/      # comments
        ';
    }


    protected function _performTransaction($parameters=null)
    {
        return mysqli_begin_transaction($this->link);
    }


    protected function _performCommit()
    {
        return mysqli_commit($this->link);
    }


    protected function _performRollback()
    {
        return mysqli_rollback($this->link);
    }


    protected function _performTransformQuery(&$queryMain, $how)
    {
        // If we also need to calculate total number of found rows...
        switch ($how)
        {
            // Prepare total calculation (if possible)
            case 'CALC_TOTAL':
                $m = null;
                if (preg_match('/^(\s* SELECT)(.*)/six', $queryMain[0], $m))
                    $queryMain[0] = $m[1] . ' SQL_CALC_FOUND_ROWS' . $m[2];
                return true;

            // Perform total calculation.
            case 'GET_TOTAL':
                // Built-in calculation available?
                $queryMain = array('SELECT FOUND_ROWS()');
                return true;
        }

        return false;
    }


    protected function _performQuery($queryMain)
    {
        $this->_lastQuery = $queryMain;
        // abort early if connection failed; _expandPlaceholders would call mysqli_real_escape_string() on a dead link (PHP 8 TypeError)
        if (!$this->link)
            return $this->_setDbError($queryMain[0]);
        $this->_expandPlaceholders($queryMain, false);

        // suppress mysqli error reporting + custom error handler on the initial attempt, so a lost-connection
        // error (2006/2013) reaches the reconnect check below without triggering DB::errorHandler logging first
        $mysqliDriver   = class_exists('mysqli_driver') ? new \mysqli_driver() : null;
        $prevReportMode = $mysqliDriver ? $mysqliDriver->report_mode : MYSQLI_REPORT_ERROR;
        set_error_handler(static function () { return true; });
        mysqli_report($prevReportMode & ~MYSQLI_REPORT_ERROR);
        try {
            $result = mysqli_query($this->link, $queryMain[0]);
        }
        catch (\Throwable $e) {
            $result = false;
        }
        finally {
            restore_error_handler();
            mysqli_report($prevReportMode);
        }

        // auto-reconnect once if an idle connection timed out (e.g. during long CLI setup runs)
        if ($result === false && in_array(mysqli_errno($this->link), [2006, 2013], true))
        {
            $this->_connect();
            if ($this->link)
                $result = mysqli_query($this->link, $queryMain[0]);
        }
        if ($result === false)
            return $this->_setDbError($queryMain[0]);

        if ($this->link->warning_count) {
            if ($warn = $this->link->query("SHOW WARNINGS")) {
                while ($warnRow = $warn->fetch_row())
                    if ($warnRow[0] === 'Warning')
                        $this->_setLastError(-$warnRow[1], $warnRow[2], $queryMain[0]);

                $warn->close();
            }
        }

        if (!is_object($result)) {
            if (preg_match('/^\s* INSERT \s+/six', $queryMain[0]))
            {
                // INSERT queries return generated ID.
                return mysqli_insert_id($this->link);
            }
            // Non-SELECT queries return number of affected rows, SELECT - resource.
            return mysqli_affected_rows($this->link);
        }
        return $result;
    }


    protected function _performFetch($result)
    {
        $row = mysqli_fetch_assoc($result);
        if (mysqli_error($this->link)) return $this->_setDbError($this->_lastQuery);
        if ($row === false) return null;
        return $row;
    }


    protected function _setDbError($query)
    {
    	if ($this->link) {
	        return $this->_setLastError(mysqli_errno($this->link), mysqli_error($this->link), $query);
	    } else {
	        return $this->_setLastError(mysqli_connect_errno(), mysqli_connect_error(), $query);
	    }
    }
}


class DbSimple_Mysqli_Blob implements DbSimple_Blob
{
    // MySQL does not support separate BLOB fetching.
    private $blobdata = null;
    private $curSeek = 0;

    public function __construct(&$database, $blobdata=null)
    {
        $this->blobdata = $blobdata;
        $this->curSeek = 0;
    }

    public function read($len)
    {
        $p = $this->curSeek;
        $this->curSeek = min($this->curSeek + $len, strlen($this->blobdata));
        return substr($this->blobdata, $p, $len);
    }

    public function write($data)
    {
        $this->blobdata .= $data;
    }

    public function close()
    {
        return $this->blobdata;
    }

    public function length()
    {
        return strlen($this->blobdata);
    }
}
