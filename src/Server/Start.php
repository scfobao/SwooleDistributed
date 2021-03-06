<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 17-7-25
 * Time: 上午10:29
 */

namespace Server;


class Start
{
    /**
     * Daemonize.
     *
     * @var bool
     */
    protected static $daemonize = false;
    /**
     * 单元测试
     * @var bool
     */
    public static $testUnity = false;
    /**
     * 单元测试文件目录
     * @var string
     */
    public static $testUnityDir = '';
    /**
     * The file to store master process PID.
     *
     * @var string
     */
    protected static $pidFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';
    /**
     * worker instance.
     *
     * @var SwooleServer
     */
    protected static $_worker = null;
    /**
     * Maximum length of the show names.
     *
     * @var int
     */
    protected static $_maxShowLength = 12;

    /**
     * Run all worker instances.
     *
     * @return void
     */
    public static function run()
    {
        self::checkSapiEnv();
        self::init();
        self::parseCommand();
        self::initWorkers();
        self::displayUI();
        self::startSwoole();
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init()
    {
        // Start file.
        $backtrace = debug_backtrace();
        self::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = PID_DIR . "/" . str_replace('/', '_', self::$_startFile) . ".pid";
        }

        // Process title.
        self::setProcessTitle('SWD');
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    public static function setProcessTitle($title)
    {
        if (isDarwin()) {
            return;
        }
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        else {
            @swoole_set_process_name($title);
        }
    }

    /**
     * Parse command.
     * php yourfile.php start | stop | reload
     *
     * @return void
     */
    protected static function parseCommand()
    {
        global $argv;
        // Check argv;
        $start_file = $argv[0];
        if (!isset($argv[1])) {
            exit("Usage: php yourfile.php {start|stop|kill|reload|restart|test}\n");
        }

        // Get command.
        $command = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Start command.
        $mode = '';
        if ($command === 'start') {
            if ($command2 === '-d') {
                $mode = 'in DAEMON mode';
            } else {
                $mode = 'in DEBUG mode';
            }
        }
        echo("Swoole[$start_file] $command $mode \n");
        if (file_exists(self::$pidFile)) {
            $pids = explode(',', file_get_contents(self::$pidFile));
            // Get master process PID.
            $master_pid = $pids[0];
            $manager_pid = $pids[1];
            $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        } else {
            $master_is_alive = false;
        }
        // Master is still alive?
        if ($master_is_alive) {
            if ($command === 'start' || $command === 'test') {
                echo("Swoole[$start_file] already running\n");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'test') {
            echo("Swoole[$start_file] not run\n");
            exit;
        }

        // execute command.
        switch ($command) {
            case 'start':
                if ($command2 === '-d') {
                    self::$daemonize = true;
                }
                break;
            case 'kill':
                exec('ps -ef|grep SWD|grep -v grep|cut -c 9-15|xargs kill -9');
                break;
            case 'stop':
                @unlink(self::$pidFile);
                echo("Swoole[$start_file] is stoping ...\n");
                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, SIGTERM);
                // Timeout.
                $timeout = 5;
                $start_time = time();
                // Check master process is still alive?
                while (1) {
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout?
                        if (time() - $start_time >= $timeout) {
                            echo("Swoole[$start_file] stop fail\n");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(10000);
                        continue;
                    }
                    // Stop success.
                    echo("Swoole[$start_file] stop success\n");
                    break;
                }
                exit(0);
                break;
            case 'reload':
                posix_kill($manager_pid, SIGUSR1);
                echo("Swoole[$start_file] reload\n");
                exit;
            case 'restart':
                @unlink(self::$pidFile);
                echo("Swoole[$start_file] is stoping ...\n");
                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, SIGTERM);
                // Timeout.
                $timeout = 5;
                $start_time = time();
                // Check master process is still alive?
                while (1) {
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout?
                        if (time() - $start_time >= $timeout) {
                            echo("Swoole[$start_file] stop fail\n");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(10000);
                        continue;
                    }
                    // Stop success.
                    echo("Swoole[$start_file] stop success\n");
                    break;
                }
                self::$daemonize = true;
                break;
            case 'test':
                self::$testUnity = true;
                self::$testUnityDir = $command2;
                break;
            default :
                exit("Usage: php yourfile.php {start|stop|kill|reload|restart|test}\n");
        }
    }

    /**
     * Init All worker instances.
     *
     * @return void
     */
    protected static function initWorkers()
    {
        // Worker name.
        if (empty(self::$_worker->name)) {
            self::$_worker->name = 'none';
        }
        // Get unix user of the worker process.
        if (empty(self::$_worker->user)) {
            self::$_worker->user = self::getCurrentUser();
        } else {
            if (posix_getuid() !== 0 && self::$_worker->user != self::getCurrentUser()) {
                echo('Warning: You must have the root privileges to change uid and gid.');
            }
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        $setConfig = self::$_worker->setServerSet();
        echo "\033[2J";
        echo "\033[1A\n\033[K-------------\033[47;30m SWOOLE_DISTRIBUTED \033[0m--------------\n\033[0m";
        echo 'System:', PHP_OS, "\n";
        echo 'SwooleDistributed version:', SwooleServer::version, "\n";
        echo 'Swoole version: ', SWOOLE_VERSION, "\n";
        echo 'PHP version: ', PHP_VERSION, "\n";
        echo 'worker_num: ', $setConfig['worker_num'], "\n";
        echo 'task_num: ', $setConfig['task_worker_num'] ?? 0, "\n";
        echo "-------------------\033[47;30m" . self::$_worker->name . "\033[0m----------------------\n";
        echo "\033[47;30mtype\033[0m", str_pad('',
            self::$_maxShowLength - strlen('type')), "\033[47;30msocket\033[0m", str_pad('',
            self::$_maxShowLength - strlen('socket')), "\033[47;30mport\033[0m", str_pad('',
            self::$_maxShowLength - strlen('port')), "\033[47;30m", "status\033[0m\n";
        switch (self::$_worker->name) {
            case SwooleDispatchClient::SERVER_NAME:
                echo str_pad('TCP',
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('dispatch_server.socket', '--'),
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('dispatch_server.port', '--'),
                    self::$_maxShowLength - 2);
                if (self::$_worker->config->get('dispatch_server.port') == null) {
                    echo " \033[31;40m [CLOSE] \033[0m\n";
                } else {
                    echo " \033[32;40m [OPEN] \033[0m\n";
                }
                break;
            case SwooleDistributedServer::SERVER_NAME:
                echo str_pad('TCP',
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('tcp.socket', '--'),
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('tcp.port', '--'),
                    self::$_maxShowLength - 2);
                if (self::$_worker->tcp_enable ?? false) {
                    echo " \033[32;40m [OPEN] \033[0m\n";
                } else {
                    echo " \033[31;40m [CLOSE] \033[0m\n";
                }
                echo str_pad('HTTP',
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('http_server.socket', '--'),
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('http_server.port', '--'),
                    self::$_maxShowLength - 2);
                if (self::$_worker->http_enable ?? false) {
                    echo " \033[32;40m [OPEN] \033[0m\n";
                } else {
                    echo " \033[31;40m [CLOSE] \033[0m\n";
                }
                echo str_pad('WEBSOCKET',
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('http_server.socket', '--'),
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('http_server.port', '--'),
                    self::$_maxShowLength - 2);
                if (self::$_worker->websocket_enable ?? false) {
                    echo " \033[32;40m [OPEN] \033[0m\n";
                } else {
                    echo " \033[31;40m [CLOSE] \033[0m\n";
                }
                echo str_pad('DISPATCH',
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('tcp.socket', '--'),
                    self::$_maxShowLength), str_pad(self::$_worker->config->get('server.dispatch_port', '--'),
                    self::$_maxShowLength - 2);
                if (self::$_worker->config->get('dispatch.enable', false)) {
                    echo " \033[32;40m [OPEN] \033[0m\n";
                } else {
                    echo " \033[31;40m [CLOSE] \033[0m\n";
                }
                break;
        }
        echo "-----------------------------------------------\n";
        if (self::$daemonize) {
            global $argv;
            $start_file = $argv[0];
            echo "Input \"php $start_file stop\" to quit. Start success.\n";
        } else {
            echo "Press Ctrl-C to quit. Start success.\n";
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function startSwoole()
    {
        self::$_worker->start();
    }

    public static function setMasterPid($masterPid, $manager_pid)
    {
        file_put_contents(self::$pidFile, $masterPid);
        file_put_contents(self::$pidFile, ',' . $manager_pid, FILE_APPEND);
        Start::setProcessTitle('SWD-Master');
    }

    public static function setWorketPid($worker_pid)
    {
        file_put_contents(self::$pidFile, ',' . $worker_pid, FILE_APPEND);
    }

    public static function initServer($swooleServer)
    {
        self::$_worker = $swooleServer;
    }

    public static function getDaemonize()
    {
        return self::$daemonize ? 1 : 0;
    }

}