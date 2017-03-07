<?php

namespace Zan\Framework\Network\Tcp;

use Zan\Framework\Network\Server\Monitor\Worker;
use Zan\Framework\Network\Server\WorkerStart\InitializeHawkMonitor;
use Zan\Framework\Network\ServerManager\ServerDiscoveryInitiator;
use Zan\Framework\Network\ServerManager\ServerStore;
use Zan\Framework\Network\Server\WorkerStart\InitializeServerDiscovery;
use Zan\Framework\Network\Server\ServerStart\InitLogConfig;
use Zan\Framework\Network\Server\WorkerStart\InitializeConnectionPool;
use swoole_server as SwooleServer;
use Kdt\Iron\Nova\Nova;
use Zan\Framework\Foundation\Application;
use Zan\Framework\Foundation\Core\Path;
use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Foundation\Exception\ZanException;
use Zan\Framework\Network\Server\ServerBase;
use Zan\Framework\Network\Tcp\ServerStart\InitializeMiddleware;
use Zan\Framework\Network\Tcp\ServerStart\InitializeSqlMap;
use Zan\Framework\Network\Server\WorkerStart\InitializeWorkerMonitor;
use Zan\Framework\Network\Tcp\WorkerStart\InitializeServerRegister;
use Zan\Framework\Foundation\Container\Di;
use Zan\Framework\Network\ServerManager\ServiceUnregister;

class Server extends ServerBase {

    protected $serverStartItems = [
        InitializeSqlMap::class,
        InitLogConfig::class,
        InitializeMiddleware::class
    ];

    protected $workerStartItems = [
        InitializeWorkerMonitor::class,
        InitializeConnectionPool::class,
        InitializeServerDiscovery::class,
        InitializeHawkMonitor::class,
    ];

    /**
     * @var SwooleServer
     */
    public $swooleServer;

    public function __construct(SwooleServer $swooleServer, array $config)
    {
        $this->swooleServer = $swooleServer;
        $this->swooleServer->set($config);
    }

    public function start()
    {
        $this->swooleServer->on('start', [$this, 'onStart']);
        $this->swooleServer->on('shutdown', [$this, 'onShutdown']);

        $this->swooleServer->on('workerStart', [$this, 'onWorkerStart']);
        $this->swooleServer->on('workerStop', [$this, 'onWorkerStop']);
        $this->swooleServer->on('workerError', [$this, 'onWorkerError']);

        $this->swooleServer->on('connect', [$this, 'onConnect']);
        $this->swooleServer->on('receive', [$this, 'onReceive']);

        $this->swooleServer->on('close', [$this, 'onClose']);

        \swoole_async_set(["socket_dontwait" => 1]);

        $this->init();

        $this->bootServerStartItem();
        
        $this->swooleServer->start();
    }

    private function init()
    {
        $config = Config::get('nova.novaApi', null);
        if(null === $config){
            return true;
        }

        Nova::init($this->parserNovaConfig($config));
        // TODO 移除
        // ClassMap::getInstance()->setSpec(GenericService::class, new GenericService());

        $config = Config::get('haunt');
        if (isset($config['app_names']) && is_array($config['app_names']) && [] !== $config['app_names']) {
            ServerStore::getInstance()->resetLockDiscovery();
        }
    }

    public function onConnect()
    {
        sys_echo("connecting ......");
    }

    public function onClose()
    {
        sys_echo("closing .....");
    }

    public function onStart($swooleServer)
    {
        $this->writePid($swooleServer->master_pid);
        Di::make(InitializeServerRegister::class)->bootstrap($this);
        sys_echo("server starting ..... [$swooleServer->host:$swooleServer->port]");
    }

    public function onShutdown($swooleServer)
    {
        $this->removePidFile();
        (new ServiceUnregister())->unRegister();
        sys_echo("server shutdown .....");
    }

    public function onWorkerStart($swooleServer, $workerId)
    {
        $_SERVER["WORKER_ID"] = $workerId;
        $this->bootWorkerStartItem($workerId);
        sys_echo("worker #$workerId starting .....");
    }

    public function onWorkerStop($swooleServer, $workerId)
    {
        // worker stop 在 worker 进程执行
        ServerDiscoveryInitiator::getInstance()->unlockDiscovery($workerId);
        sys_echo("worker #$workerId stopping ....");

        $num = Worker::getInstance()->reactionNum ?: 0;
        sys_echo("worker #$workerId still has $num requests in progress...");
    }

    public function onWorkerError($swooleServer, $workerId, $workerPid, $exitCode, $sigNo)
    {
        ServerDiscoveryInitiator::getInstance()->unlockDiscovery($workerId);
        sys_echo("worker error happening [workerId=$workerId, workerPid=$workerPid, exitCode=$exitCode, signalNo=$sigNo]...");

        $num = Worker::getInstance()->reactionNum ?: 0;
        sys_echo("worker #$workerId still has $num requests in progress...");
    }

    public function onPacket(SwooleServer $swooleServer, $data, array $clientInfo)
    {
        sys_echo("receive packet data..");
    }

    public function onReceive(SwooleServer $swooleServer, $fd, $fromId, $data)
    {
        (new RequestHandler())->handle($swooleServer, $fd, $fromId, $data);
    }

    /**
     * 配置向下兼容
     *
     * novaApi => [
     *      'path'  => 'vendor/nova-service/xxx/gen-php',
     *      'namespace' => 'Com\\Youzan\\Biz\\',
     *      'appName' => 'demo', // optional
     *      'domain' => 'com.youzan.service', // optional
     * ]
     * novaApi => [
     *      [
     *          'appName' => 'app-foo',
     *          'path'  => 'vendor/nova-service/xxx/gen-php',
     *          'namespace' => 'Com\\Youzan\\Biz\\',
     *          'domain' => 'com.youzan.service', // optional
     *      ],
     *      [
     *          'appName' => 'app-bar',
     *          'path'  => 'vendor/nova-service/xxx/gen-php',
     *          'namespace' => 'Com\\Youzan\\Biz\\',
     *          'domain' => 'com.youzan.service', // optional
     *      ],
     * ]
     * @param $config
     * @return array
     * @throws ZanException
     */
    private function parserNovaConfig($config)
    {
        if (!is_array($config)) {
            throw new ZanException("invalid nova config");
        }
        if (isset($config["path"])) {
            $appName = Application::getInstance()->getName();
            if (!isset($config["appName"])) {
                $config["appName"] = $appName;
            }
            $config = [ $config ];
        }

        foreach ($config as &$item) {
            if (!isset($item["appName"])) {
                $item["appName"] = Application::getInstance()->getName();
            }
            if(!isset($item["path"])){
                throw new ZanException("nova server path not defined");
            }

            $item["path"] = Path::getRootPath() . $item["path"];

            if(!isset($item["namespace"])){
                throw new ZanException("nova namespace path not defined");
            }

            if(!isset($item["domain"])) {
                $item["domain"] = "com.youzan.service";
            }

            if(!isset($item["protocol"])) {
                $item["protocol"] = "nova";
            }
        }
        unset($item);
        return $config;
    }
}
