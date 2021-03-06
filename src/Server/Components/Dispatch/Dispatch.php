<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 17-7-24
 * Time: 上午11:41
 */

namespace Server\Components\Dispatch;


use Server\SwooleMarco;

class Dispatch
{
    protected $dispatch_udp_port;
    protected $dispatch_port;
    protected $dispatchClientFds = [];
    protected $enable;
    protected $config;
    /**
     * 分布式系统服务器唯一标识符
     * @var int
     */
    public $USID;
    public $probuf_set = [
        'open_length_check' => 1,
        'package_length_type' => 'N',
        'package_length_offset' => 0,       //第N个字节是包长度的值
        'package_body_offset' => 0,       //第几个字节开始计算长度
        'package_max_length' => 2000000,  //协议最大长度)
    ];

    public function __construct($config)
    {
        $this->config = $config;
        $this->enable = $config->get('dispatch.enable',false);
    }

    public function buildPort()
    {
        if ($this->enable) {
            //创建一个udp端口
            $this->dispatch_udp_port = get_instance()->server->listen($this->config['tcp']['socket'], $this->config['server']['dispatch_udp_port'], SWOOLE_SOCK_UDP);

            //创建dispatch端口用于连接dispatch
            $this->dispatch_port = get_instance()->server->listen(get_instance()->config['tcp']['socket'],$this->config['server']['dispatch_port'], SWOOLE_SOCK_TCP);
            $this->dispatch_port->set($this->probuf_set);
            $this->dispatch_port->on('close', function ($serv, $fd) {
                print_r("Remove a dispatcher: $fd.\n");
                for ($i = 0; $i < get_instance()->worker_num + get_instance()->task_num; $i++) {
                    if ($i == $serv->worker_id) continue;
                    $data = get_instance()->packSerevrMessageBody(SwooleMarco::REMOVE_DISPATCH_CLIENT, $fd);
                    $serv->sendMessage($data, $i);
                }
                get_instance()->sendToAllWorks(SwooleMarco::REMOVE_DISPATCH_CLIENT, $fd, DispatchHelp::class . "::removeDispatch");
            });

            $this->dispatch_port->on('receive', function ($serv, $fd, $from_id, $data) {
                $data = $this->dispatch_decode($data);
                $unserialize_data = \swoole_serialize::unpack($data);
                $type = $unserialize_data['type'];
                $message = $unserialize_data['message'];
                switch ($type) {
                    case SwooleMarco::MSG_TYPE_USID://获取服务器唯一id
                        $uns_data = $message;
                        $uns_data['fd'] = $fd;
                        if (!array_key_exists($fd, $this->dispatchClientFds)) {
                            print_r("Find a new dispatcher: $fd.\n");
                        }
                        $fdinfo = get_instance()->server->connection_info($fd);
                        $uns_data['remote_ip'] = $fdinfo['remote_ip'];
                        get_instance()->sendToAllWorks($type, $uns_data, DispatchHelp::class . "::addDispatch");
                        break;
                    case SwooleMarco::MSG_TYPE_SEND://发送消息
                        get_instance()->sendToUid($message['uid'], $message['data'], true);
                        break;
                    case SwooleMarco::MSG_TYPE_SEND_BATCH://批量消息
                        get_instance()->sendToUids($message['uids'], $message['data'], true);
                        break;
                    case SwooleMarco::MSG_TYPE_SEND_ALL://广播消息
                        $serv->task($data);
                        break;
                    case SwooleMarco::MSG_TYPE_KICK_UID://踢人
                        get_instance()->kickUid($message['uid'], true);
                        break;
                }
            });
        }
    }

    /**
     * 被DispatchHelp调用
     * 移除dispatch
     * @param $fd
     */
    public function removeDispatch($fd)
    {
        unset($this->dispatchClientFds[$fd]);
    }

    /**
     * 被DispatchHelp调用
     * 添加一个dispatch
     * @param $data
     */
    public function addDispatch($data)
    {
        $this->USID = $data['usid'];
        $this->dispatchClientFds[$data['fd']] = $data['fd'];
    }

    /**
     * 数据包编码
     * @param $buffer
     * @return string
     */
    public function dispatch_encode($buffer)
    {
        $total_length = 4 + strlen($buffer);
        return pack('N', $total_length) . $buffer;
    }

    /**
     * @param $buffer
     * @return string
     */
    public function dispatch_decode($buffer)
    {
        $data = substr($buffer, 4);
        return $data;
    }

    /**
     * @return null
     */
    protected function getRamdomFd()
    {
        $fd = null;
        if (count($this->dispatchClientFds) > 0) {
            $fd = $this->dispatchClientFds[array_rand($this->dispatchClientFds)];
        }
        return $fd;
    }

    /**
     * @param $send_data
     * @return bool
     */
    public function send($send_data)
    {
        if(!$this->enable){
            return false;
        }
        $fd = $this->getRamdomFd();
        if($fd!=null) {
            get_instance()->server->send($fd, $this->dispatch_encode($send_data));
            return true;
        }
        return false;
    }

    /**
     * @param $fd
     * @return bool
     */
    public function isDispatchFd($fd)
    {
        return in_array($fd, $this->dispatchClientFds);
    }

    public function bind($uid)
    {
        if(!$this->enable) return;
        get_instance()->getRedis()->hSet(SwooleMarco::redis_uid_usid_hash_name, $uid, $this->USID);
    }
}