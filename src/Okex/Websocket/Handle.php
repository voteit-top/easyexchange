<?php

namespace EasyExchange\Okex\Websocket;

use Workerman\Connection\AsyncTcpConnection;

class Handle implements \EasyExchange\Kernel\Websocket\Handle
{
    private $config;

    public function getConnection($config, $params)
    {
        $this->config = $config;

        $ws_base_uri = $config['base_uri'].'/ws/v5/public';
//        $ws_base_uri = 'ws://ws.okex.com:8443/ws/v5/public?brokerId=9999';
        echo $ws_base_uri.PHP_EOL;

        $connection = new AsyncTcpConnection($ws_base_uri);
        $connection->transport = 'ssl';

        return $connection;
    }

    public function onConnect($connection, $client, $params)
    {
        echo 'connect:-----------------'.PHP_EOL;
        $connection->send(json_encode($params));
    }

    public function onMessage($connection, $client, $params, $data)
    {
        echo 'msg:------------------';
        echo $data.PHP_EOL;
        $old_value = $client->okex_data ?? [];
        if (!$old_value) {
            $client->add('okex_data', [$data]);
        } else {
            $max_size = $this->config['max_size'] ?? 100;
            do {
                $new_value = $old_value;
                array_unshift($new_value, $data);
                if (count($new_value) > $max_size) {
                    $new_value = array_slice($new_value, 0, $max_size);
                }
            } while (!$client->cas('okex_data', $old_value, $new_value));
        }
    }

    public function onError($connection, $client, $code, $message)
    {
        echo "error: $message\n";
    }

    public function onClose($connection, $client)
    {
        echo "connection closed\n";
    }

    /**
     * login.
     *
     * @param $connection
     */
    public function login($connection)
    {
        $timestamp = time();
        $sign = $this->getSignature($timestamp);
        $params = [
            'op' => 'login',
            'args' => [
                [
                    'apiKey' => $this->config['app_key'],
                    'passphrase' => $this->config['passphrase'],
                    'timestamp' => $timestamp,
                    'sign' => $sign,
                ],
            ],
        ];
        $connection->send(json_encode($params));
    }

    /**
     * get sign.
     *
     * @param $timestamp
     * @param string $method
     * @param string $uri_path
     *
     * @return string
     */
    public function getSignature($timestamp, $method = 'GET', $uri_path = '/users/self/verify')
    {
        $message = (string) $timestamp.$method.$uri_path;
        $secret = $this->config['secret'];

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }
}
