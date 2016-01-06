<?php

namespace Zan\Framework\Network\Http;

use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Exception\InvalidRoute;
use Zan\Framework\Foundation\Domain\Controller;

class RequestProcessor {

    private $request;
    private $response;

    private $controllerNamespace = 'app\\controllers';

    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;
    }

    public function run($route)
    {
        $controller = $this->createController($route);

        if (!($controller instanceof Controller)) {
            throw new InvalidRoute('Not found controller:'.$controller);
        }
        $action = $route['action'];
        if (!method_exists($controller, $action)) {
            throw new InvalidRoute('Class does not exist method '. get_class($controller).'::'.$action);
        }
        $task = new Task($controller->$action());
        $task->run();
    }

    private function createController($route)
    {
        $module    = $route['module'];
        $className = $route['controller'];

        if (!preg_match('%^[a-z][a-z0-9]*$%', $className)) {
            return null;
        }
        $className = $module.'_'.str_replace(' ', '', ucwords($className)).'Controller';
        $className = ltrim($this->controllerNamespace . '\\' . $className, '\\');

        if (!class_exists($className)) {
            return null;
        }
        return new $className();
    }

}