<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


/**************************/
/* Configure DB connection*/
/**************************/

CLISetup::registerUtility(new class extends UtilityScript
{
    private bool $firstPass = true;
    public $argvOpts = ['db'];
    public $optGroup = CLISetup::OPT_GRP_SETUP;

    public const COMMAND     = 'database';
    public const DESCRIPTION = 'Set up DB connection.';
    public const PROMPT      = 'Please enter your database credentials.';
    public const NOTE_ERROR  = 'could not establish connection to:';

    private const CONFIG_FILE = 'config/config.php';

    private $databases = ['aowow', 'world', 'auth', 'characters'];
    private $config    = [];
    private $dbFields  = array(
        'host'   => ['Server Host',   false],
        'user'   => ['User',          false],
        'pass'   => ['Password',      true ],
        'db'     => ['Database Name', false],
        'prefix' => ['Table prefix',  false]
    );

    private $icons   = ['Normal[0]', 'PvP', null, null, 'Normal[4]', 'RP', null, 'RP-PvP'];
    private $regions = array(
        null, 'Development', 'United States', 'Oceanic', 'Latin America', 'Tournament (Americas)', 'Korea',  'Tournament (Korea)', 'English', 'German', 'French', 'Spanish', 'Russian', 'Tournament (EU)', 'Taiwan', 'Tournament (Taiwan)', 'China',
        25 => 'Tournament (China)', 26 => 'Test Server', 27 => 'Tournament (Test Server)', 28 => 'QA Server', 30 => 'Test Server 2'
    );

    // args: null, null, null, null // nnnn
    public function run(&$args) : bool
    {
        if (!$this->config && file_exists(self::CONFIG_FILE))
        {
            require self::CONFIG_FILE;
            $this->config = $AoWoWconf;
            unset($AoWoWconf);
        }

        foreach ($this->databases as $idx => $name)
            if (empty($this->config[$name]) && $name != 'characters' )
                $this->config[$name] = array_combine(array_keys($this->dbFields), ['', '', '', '', '']);

        $this->firstPass = true;
        while (true)
        {
            CLI::write("select an index to use the corresponding entry", -1, false);

            $nCharDBs = 0;
            $tblRows  = [];
            foreach ($this->databases as $idx => $name)
            {
                if ($idx != DB_CHARACTERS)
                    $tblRows[] = $this->testDB($idx, $name, $this->config[$name]);
                else if (!empty($this->config[$name]))
                    foreach ($this->config[$name] as $charIdx => $dbInfo)
                        $tblRows[] = $this->testDB($idx + $nCharDBs++, $name.' ['.$charIdx.']', $this->config[$name][$charIdx]);
            }

            $tblRows[] = ['['.CLI::bold('N').']', 'new characters DB'];
            $tblRows[] = ['['.CLI::bold('S').']', 'show available realms'];
            $tblRows[] = ['['.CLI::bold('R').']', 'retest / reload DBs'];
            $tblRows[] = ['['.CLI::bold('Enter').']', 'save and continue'];
            $this->firstPass = false;
            CLI::writeTable($tblRows, false, true);

            while (true)
            {
                if (CLI::read(['idx' => ['', true, true, '/\d|R|N|S/i']], $uiIndex) && $uiIndex)
                {
                    if (strtoupper($uiIndex['idx']) == 'R')
                        continue 2;
                    else if (strtoupper($uiIndex['idx']) == 'S')
                    {
                        CLI::write();
                        if (!DB::isConnectable(DB_AUTH) || !$this->test())
                            CLI::write('[db] auth server not yet set up.', CLI::LOG_ERROR);
                        else if ($realms = DB::Auth()->select('SELECT `id` AS "0", `name` AS "1", `icon` AS "2", `timezone` AS "3", `allowedSecurityLevel` AS "4" FROM realmlist'))
                        {
                            $tbl = [['Realm Id', 'Name', 'Type', 'Region', 'GMLevel', 'Status']];
                            foreach ($realms as [$id, $name, $icon, $region, $level])
                            {
                                $status    = [];
                                $hasRegion = false;
                                foreach (Profiler::REGIONS as $n => $valid)
                                    if ($hasRegion = in_array($region, $valid))
                                    {
                                        if ($n == 'dev')
                                            $status[] = 'Restricted region (staff only)';
                                        break;
                                    }

                                if (!$hasRegion && !$status)
                                    $status[] = 'Unsupported region';
                                if ($level > 0)
                                    $status[] = 'GM-Level locked';
                                if (DB::isConnectable(DB_CHARACTERS . $id))
                                    $status[] = 'Already in use';

                                $tbl[] = [$id, $name, $this->icons[$icon] ?? '<UNK:'.$icon.'>', $this->regions[$region] ?? '<UNK:'.$region.'>', $level, $status ? CLI::yellow(implode(', ', $status)) : CLI::green('Usable')];
                            }

                            CLI::writeTable($tbl);
                        }
                        else
                            CLI::write('[db] table `realmlist` is empty.', CLI::LOG_WARN);

                        CLI::write();

                        continue 2;
                    }
                    else if (($uiIndex['idx'] >= DB_AOWOW && $uiIndex['idx'] < (DB_CHARACTERS + $nCharDBs)) || strtoupper($uiIndex['idx']) == 'N')
                    {
                        $curFields = $uiIndex['idx'] ? $this->dbFields : array_slice($this->dbFields, 0, 4);

                        if (strtoupper($uiIndex['idx']) == 'N')    // add new characters DB
                            $curFields['realmId'] = ['Realm Id', false, false, '/\d{1,3}/'];

                        if (CLI::read($curFields, $uiRealm))
                        {
                            if ($uiIndex['idx'] == DB_AOWOW && $uiRealm)
                                $uiRealm['prefix'] = 'aowow_';

                            if (strtoupper($uiIndex['idx']) == 'N')    // new char DB
                            {
                                if ($uiRealm)
                                {
                                    $_ = $uiRealm['realmId'];
                                    unset($uiRealm['realmId']);
                                    $this->config[$this->databases[DB_CHARACTERS]][$_] = $uiRealm;
                                }
                            }
                            else if ($uiIndex['idx'] < DB_CHARACTERS)    // auth, world or aowow
                                $this->config[$this->databases[$uiIndex['idx']]] = $uiRealm ?: array_combine(array_keys($this->dbFields), ['', '', '', '', '']);
                            else                            // existing char DB
                            {
                                $i = 0;
                                foreach ($this->config[$this->databases[DB_CHARACTERS]] as $realmId => &$dbInfo)
                                {
                                    if ($uiIndex['idx'] - DB_CHARACTERS != $i++)
                                        continue;

                                    if ($uiRealm)
                                        $dbInfo = $uiRealm;
                                    else
                                        unset($this->config[$this->databases[3]][$realmId]);
                                }
                            }

                            // write config file
                            $buff = "<?php\n\nif (!defined('AOWOW_REVISION'))\n    die('illegal access');\n\n\n";
                            foreach ($this->databases as $db)
                            {
                                if ($db != 'characters')
                                    $buff .= '$AoWoWconf[\''.$db.'\'] = '.var_export($this->config[$db], true).";\n\n";
                                else if (isset($this->config[$db]))
                                    foreach ($this->config[$db] as $idx => $charInfo)
                                        $buff .= '$AoWoWconf[\''.$db.'\'][\''.$idx.'\'] = '.var_export($this->config[$db][$idx], true).";\n\n";
                            }
                            $buff .= "?>\n";
                            CLI::write();
                            CLISetup::writeFile(self::CONFIG_FILE, $buff);
                            continue 2;
                        }
                        else
                        {
                            CLI::write("[db] edit canceled! returning to list...", CLI::LOG_INFO);
                            CLI::write();
                            sleep(1);
                            continue 2;
                        }
                    }
                }
                else
                {
                    CLI::write("[db] leaving db config...", CLI::LOG_INFO);
                    CLI::write("[db] security tip: for public production setups, restrict the AoWoW DB user to SELECT, INSERT, UPDATE, DELETE after setup completes (via 'php aowow --database' or config/config.php).", CLI::LOG_INFO);
                    CLI::write();
                    break 2;
                }
            }
        }

        return true;
    }

    public function test(?array &$error = []) : bool
    {
        if (!$this->config)
        {
            require self::CONFIG_FILE;
            $this->config = $AoWoWconf;
            unset($AoWoWconf);
        }

        $error = [];
        foreach (['aowow', 'world', 'auth'] as $idx => $what)
        {
            if ($what == 'auth' && (empty($this->config['auth']) || empty($this->config['auth']['host'])))
                continue;

            // init proper access for further setup
            if (DB::test($this->config[$what], $err))
            {
                DB::load($idx, $this->config[$what]);
                switch ($idx)
                {
                    case DB_AOWOW:
                        if (DB::Aowow()->selectCell('SHOW TABLES LIKE ?', 'aowow_dbversion'))
                            Cfg::load();                    // first time load after successful db setup
                        else
                            $error[] = ' * '.$what.': doesn\'t seem to contain aowow tables!';
                        break;
                    case DB_WORLD:
                        if (!DB::World()->selectCell('SHOW TABLES LIKE ?', 'version'))
                            $error[] = ' * '.$what.': doesn\'t seem to contain TrinityCore world tables!';
                        else if (DB::World()->selectCell('SELECT `cache_id` FROM `version`') < TDB_WORLD_MINIMUM_VER)
                            $error[] = ' * '.$what.': TDB world db is structurally outdated! (min rev.: '.CLI::bold(TDB_WORLD_MINIMUM_VER).')';
                        break;
                    default:
                       // no further checks at this time
                }
            }
            else
                $error[] = ' * '.$what.': '.$err;
        }

        return empty($error);
    }

    private function testDB($idx, $name, $dbInfo)
    {
        $buff = ['['.CLI::bold($idx).']', $name];

        if ($dbInfo['host'])
        {
            if ($idx == DB_AOWOW && !$this->firstPass)
                $this->prepareAowowDB($dbInfo);

            $result = CLI::green('OK');
            $note   = '';

            if (DB::test($dbInfo, $note))
            {
                DB::load($idx, $dbInfo);

                $ok = false;
                switch ($idx)
                {
                    case DB_AOWOW:
                        if (DB::Aowow()->selectCell('SHOW TABLES LIKE ?', 'aowow_dbversion'))
                        {
                            if ($date = DB::Aowow()->selectCell('SELECT `date` FROM ?_dbversion'))
                            {
                                $note = 'AoWoW DB version @ ' . date(Util::$dateFormatInternal, $date);
                                $ok   = true;
                            }
                            else
                                $note = CLI::yellow('AoWoW DB version empty! Import of DB dump failed?');
                        }
                        else
                            $note = CLI::yellow('DB test failed to find dbversion table. setup/db_structure.sql not yet imported?');
                        break;
                    case DB_WORLD:
                        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'version'))
                        {
                            [$vString, $vNo] = DB::World()->selectRow('SELECT `db_version` AS "0", `cache_id` AS "1" FROM `version`');
                            if (strpos($vString, 'TDB') === 0)
                            {
                                if ($vNo < TDB_WORLD_MINIMUM_VER)
                                    $note = CLI::yellow('DB test found TrinityDB version older than rev. ').CLI::bold(TDB_WORLD_MINIMUM_VER).CLI::yellow('. Please update to at least rev. ').CLI::bold(TDB_WORLD_MINIMUM_VER);
                                else if ($vNo > TDB_WORLD_EXPECTED_VER)
                                    $note = CLI::yellow('DB test found TrinityDB version newer than rev. ').CLI::bold(TDB_WORLD_EXPECTED_VER).CLI::yellow('. Be advised! DB structure may diverge!');
                                else
                                {
                                    $note = 'TrinityDB version @ ' . $vString;
                                    $ok   = true;
                                }
                            }
                            else if (strpos($vString, 'ACDB') === 0)
                                $note = CLI::yellow('DB test found AzerothCore DB version. AzerothCore DB structure is not supported!');
                            else
                                $note = CLI::yellow('DB test found unexpected vendor in expected version table. Uhh.. Good Luck..!?');
                        }
                        else if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'db_version'))
                            $note = CLI::yellow('DB test found MaNGOS styled version table. MaNGOS DB structure is not supported!');
                        else
                            $note = CLI::yellow('DB test failed to find version table. TrinityDB world not yet imported?');
                        break;
                    default:
                        $ok = true;                         // no tests right now
                }

                if (!$ok)
                    $result = CLI::yellow('WARN');
            }
            else
                $result = CLI::red('ERR');

            $buff[] = $result;
            $buff[] = 'mysqli://'.$dbInfo['user'].':'.($dbInfo['pass'] ? '**********' : '').'@'.$dbInfo['host'].'/'.$dbInfo['db'];
            $buff[] = $dbInfo['prefix'] ? 'pre.: '.$dbInfo['prefix'] : '';
            $buff[] = $note;
        }
        else
            $buff[] = CLI::bold('<empty>');

        return $buff;
    }

    private function prepareAowowDB(array $dbInfo) : void
    {
        $defPort = (int)ini_get('mysqli.default_port') ?: 3306;
        $host    = $dbInfo['host'];
        $port    = $defPort;
        if (strpos($host, ':') !== false)
            [$host, $port] = explode(':', $host, 2);

        $link = @mysqli_connect($host, $dbInfo['user'], $dbInfo['pass'], '', (int)$port);
        if (!$link)
            return;

        $dbName  = $dbInfo['db'];
        $escDb   = str_replace('`', '``', $dbName);
        $escGrantDb = str_replace(['_', '%'], ['\_', '\%'], $escDb);
        $escUser = mysqli_real_escape_string($link, $dbInfo['user']);
        $escLike = mysqli_real_escape_string($link, str_replace(['_', '%'], ['\_', '\%'], $dbName));

        // 1. Check if database exists
        $res = @mysqli_query($link, "SHOW DATABASES LIKE '" . $escLike . "'");
        if ($res && mysqli_num_rows($res) === 0)
        {
            // use the authenticated account for manual GRANT hints, not just the entered user
            $authAccount = "'{$escUser}'@'%'";
            if ($cuRes = @mysqli_query($link, "SELECT CURRENT_USER()"))
            {
                $cuRow = mysqli_fetch_row($cuRes);
                if ($cuRow && $cuRow[0] && strpos($cuRow[0], '@') !== false)
                {
                    [$cuUser, $cuHost] = explode('@', $cuRow[0], 2);
                    $authAccount = "'" . mysqli_real_escape_string($link, $cuUser) . "'@'" . mysqli_real_escape_string($link, $cuHost) . "'";
                }
            }

            CLI::write("Database '" . CLI::bold($dbName) . "' does not exist on " . $dbInfo['host'] . ".", CLI::LOG_INFO);
            if (CLI::read(['x' => ['Create database now? (y/n)', true, true, '/y|n/i']], $uiN) && $uiN && strtolower($uiN['x']) === 'y')
            {
                if (@mysqli_query($link, "CREATE DATABASE `{$escDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"))
                    CLI::write("Database '" . CLI::bold($dbName) . "' created successfully!", CLI::LOG_OK);
                else
                {
                    CLI::write("Could not create database: " . mysqli_error($link), CLI::LOG_ERROR);
                    CLI::write("Run this SQL manually as root/admin:\n  CREATE DATABASE `{$escDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n  GRANT ALL PRIVILEGES ON `{$escGrantDb}`.* TO {$authAccount};", -1, false);
                }
            }
            else
            {
                CLI::write("Create it manually with:\n  CREATE DATABASE `{$escDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n  GRANT ALL PRIVILEGES ON `{$escGrantDb}`.* TO {$authAccount};\nThen press [R] to retest.", -1, false);
                mysqli_close($link);
                return;
            }
        }

        if (!@mysqli_select_db($link, $dbName))
        {
            mysqli_close($link);
            return;
        }

        // 2. Check user privileges for setup
        $grants = [];
        if ($gRes = @mysqli_query($link, "SHOW GRANTS FOR CURRENT_USER()"))
        {
            while ($row = mysqli_fetch_row($gRes))
                $grants[] = strtoupper($row[0]);
        }
        $grantStr = implode("\n", $grants);
        if (strpos($grantStr, 'ALL PRIVILEGES') === false)
        {
            $needed  = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'];
            $missing = array_filter($needed, fn($p) => strpos($grantStr, $p) === false);
            if ($missing)
                CLI::write("Current DB user may lack setup privileges (" . implode(', ', $missing) . ") on `{$dbName}`.", CLI::LOG_WARN);
        }

        // 3. Check if db_structure.sql is imported
        $tRes      = @mysqli_query($link, "SHOW TABLES LIKE 'aowow_dbversion'");
        $hasVerTbl = $tRes && mysqli_num_rows($tRes) > 0;
        $imported  = false;
        if ($hasVerTbl && ($vRes = @mysqli_query($link, "SELECT 1 FROM `aowow_dbversion` LIMIT 1")))
            $imported = (bool)mysqli_fetch_row($vRes);

        if (!$imported)
        {
            $sqlFile = 'setup/db_structure.sql';
            $hasTbls = false;
            if ($t2Res = @mysqli_query($link, "SHOW TABLES"))
                $hasTbls = mysqli_num_rows($t2Res) > 0;

            CLI::write("Database '" . CLI::bold($dbName) . "' has no AoWoW DB version yet.", CLI::LOG_INFO);
            if ($hasVerTbl)
                CLI::write("Table `aowow_dbversion` exists but is empty; a previous import may be incomplete.", CLI::LOG_WARN);
            if ($hasTbls)
                CLI::write("Database already contains tables. " . $sqlFile . " will DROP them; existing data will be lost!", CLI::LOG_WARN);

            $prompt = $hasTbls ? 'Existing tables will be dropped! Import ' . $sqlFile . ' anyway? (y/n)' : 'Import ' . $sqlFile . ' now? (y/n)';
            if (file_exists($sqlFile) && CLI::read(['x' => [$prompt, true, true, '/y|n/i']], $uiN) && $uiN && strtolower($uiN['x']) === 'y')
            {
                CLI::write("Importing " . $sqlFile . "...", CLI::LOG_INFO);
                $sql = file_get_contents($sqlFile);
                if (mysqli_multi_query($link, $sql))
                {
                    do {
                        if ($r = mysqli_store_result($link))
                            mysqli_free_result($r);
                    } while (mysqli_more_results($link) && mysqli_next_result($link));
                }
                if (mysqli_errno($link))
                    CLI::write("SQL import error: " . mysqli_error($link), CLI::LOG_ERROR);
                else
                    CLI::write("Database structure imported successfully!", CLI::LOG_OK);
            }
            else
            {
                if ($hasTbls)
                    CLI::write("To preserve existing tables, move them aside or use a fresh database.", CLI::LOG_WARN);
                CLI::write("Import it manually with:\n  mysql -h {$host} -P {$port} -u {$dbInfo['user']} -p {$dbName} < {$sqlFile}\nThen press [R] to retest.", -1, false);
            }
        }

        mysqli_close($link);
    }

    public function writeCLIHelp() : bool
    {
        CLI::write('  Remember to use the correct Realm Id from '.CLI::bold('`logon`.`realmlist`').' when connecting to your characters DB.', -1, false);
        CLI::write('  To remove a db entry edit it and leave all fields empty.', -1, false);
        CLI::write();
        CLI::write();

        return true;
    }
});

?>
