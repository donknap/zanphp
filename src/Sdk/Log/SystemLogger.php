<?php
/**
 * Created by IntelliJ IDEA.
 * User: nuomi
 * Date: 16/5/24
 * Time: 下午2:51
 */

namespace Zan\Framework\Sdk\Log;

use Zan\Framework\Foundation\Core\Env;

class SystemLogger extends BaseLogger
{

    private $priority;
    private $hostname;
    private $server;
    private $pid;
    private $callback;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->priority = LOG_LOCAL3 + LOG_INFO;
        $this->hostname = Env::get('hostname');
        $this->server = $this->hostname . "/" . gethostbyname($this->hostname);
        $this->pid = Env::get('pid');
    }

    public function execute(callable $callback)
    {
        $this->callback = $callback;
    }

    public function init()
    {
        $this->writer = new SystemWriter($this->config['path']);
        yield $this->writer->init();
    }

    public function format($level, $message, $context)
    {
        $header = $this->buildHeader($level);
        $topic = $this->buildTopic();
        $module = $this->config['module'];
        $body = $this->buildBody();
        $result = $header . "topic=" . $topic . " " . $module . " " . $body;

        return $result;
    }

    protected function doWrite($log)
    {
        $writer = $this->getWriter();
        if (!$writer) {
            yield $this->init();
        }
        yield $this->getWriter()->write($log);
    }

    private function buildHeader($level)
    {
        $time = date("Y-m-d H:i:s");
        return "<{$this->priority}>{$time} {$this->server} {$level}[{$this->pid}]: ";
    }

    private function buildTopic()
    {

    }

    private function buildBody()
    {

    }

}
