<?php

namespace EasyExchange\Kernel\Websocket;

use EasyExchange\Kernel\ServiceContainer;
use GlobalData\Client;
use GlobalData\Server;
use Workerman\Worker;

class BaseClient
{
    public $client;

    /**
     * BaseClient constructor.
     */
    public function __construct(ServiceContainer $app)
    {
        $this->app = $app;
        $this->client = new Client('127.0.0.1:2207');
    }

    /**
     * @param $params
     */
    public function server($params, Handle $handle)
    {
        $config = $this->app->getConfig();
        $worker = new Worker();
        // GlobalData Server
        new Server('127.0.0.1', 2207);
        $worker->onWorkerStart = function () use ($config, $params, $handle) {
            $this->client = new Client('127.0.0.1:2207');
            $ws_connection = $handle->getConnection($config, $params);
            $ws_connection->onConnect = function ($connection) use ($params, $handle) {
                $handle->onConnect($connection, $params);
            };
            $ws_connection->onMessage = function ($connection, $data) use ($params, $handle) {
                $handle->onMessage($connection, $params, $data);
            };
            $ws_connection->onError = function ($connection, $code, $msg) use ($handle) {
                $handle->onError($connection, $code, $msg);
            };
            $ws_connection->onClose = function ($connection) use ($handle) {
                $handle->onClose($connection);
            };
            $ws_connection->connect();

            // heartbeat
            $this->ping($ws_connection);

            // timer action
            $this->connect($ws_connection, 2);
        };
        Worker::runAll();
    }

    /**
     * 更新或创建.
     *
     * @param $key
     * @param $value
     */
    public function updateOrCreate($key, $value)
    {
        if (!isset($this->client->$key)) {
            $this->create($key, $value);
        } else {
            $this->client->$key = $value;
        }
    }

    /**
     * 创建.
     *
     * @param $key
     * @param $value
     *
     * @throws \Exception
     */
    public function create($key, $value)
    {
        $this->client->add($key, $value);

        $this->cache($key);
    }

    /**
     * 获取缓存的值.
     *
     * @param $key
     *
     * @return array|mixed
     */
    public function get($key)
    {
        if (!isset($this->client->{$key}) || empty($this->client->{$key})) {
            return [];
        }

        return $this->client->{$key};
    }

    /**
     * 数据缓存.
     *
     * @param $key
     *
     * @throws \Exception
     */
    protected function cache($key)
    {
        do {
            $old_value = $new_value = $this->client->global_key;
            $new_value[$key] = $key;
        } while (!$this->client->cas('global_key', $old_value, $new_value));
    }

    public function move($key, $new_key)
    {
        $data = $this->get($key);
        $this->updateOrCreate($new_key, $data);
        $this->updateOrCreate($key, []);
    }

    public function delete($key)
    {
        $this->updateOrCreate($key, []);
    }
}
