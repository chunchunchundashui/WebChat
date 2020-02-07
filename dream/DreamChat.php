<?php
namespace dream;

class DreamChat
{
    protected $master = null; //服务端
    protected $connectPool = []; //socket连接池
    protected $handPool = []; //http升级websocket池

    //构造方法接受传过来的数据
    public function __construct($ip, $port)
    {
        $this->startServer($ip, $port);
    }

    //启动服务端的方法
    public function startServer($ip, $port)
    {
        //服务端的socket
        $this->connectPool[] = $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        //第一个套接字， 绑定端口
        socket_bind($this->master, $ip, $port);
        //监听
        socket_listen($this->master, 1000);
        while(true)
        {
            //获取所有的socket
            $sockets = $this->connectPool;
            $write = $except = null;
            //阻塞模式
            socket_select($sockets, $write, $except, 60);

            //判断是服务端还是客服端
            foreach ($sockets as $socket)
            {
                if($socket == $this->master) {
                    //接受客服端信息给socket连接池
                    $this->connectPool[] = $client = socket_accept($this->master);
                    $keyArr = array_keys($this->connectPool, $client);
                    $key = end($keyArr);
                    //还没有握手时
                    $this->handPool[$key] = false; 
                }else {
                    $length = socket_recv($socket, $buffer, 1024, 0);
                    if($length < 1) {
                        $this->close($socket);
                    }else {
                        $key = array_search($socket, $this->connectPool);
                        if($this->handPool[$key] == false){
                            $this->handShake($socket, $buffer, $key);
                        } else {
                            //解帧
                            $message = $this->deFrame($buffer);
                            //封帧
                            $message = $this->enFrame($message);
                            //群聊发送
                            $this->send($message);
                        }
                    }
                }
            }
        }
    }

    //客户端断开连接
    public function close($socket)
    {
        //返回一个KEY值
        $key = array_search($socket, $this->connectPool);
        unset($this->connnectPool[$key]);
        unset($this->handPool[$key]);
        socket_close($socket);
    }

    //http升级websocket
    public function handShake($socket, $buffer, $key)
    {
        if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match)) {
            $responseKey = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $upgrade = "HTTP/1.1 101 Switching Protocol\r\n" . 
                        "Upgrade: websocket\r\n" . 
                        "Connection: Upgrade\r\n" . 
                        "Sec-WebSocket-Accept: " . $responseKey . "\r\n\r\n";
            socket_write($socket, $upgrade, strlen($upgrade));
            $this->handPool[$key] = true;
        }
    }

    //数据解帧
    public function deFrame($buffer)
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;

        if($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } elseif ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    //数据封帧
    public function enFrame($message)
    {
        $len = strlen($message);
        if($len <= 125) {
            return "\x81" . chr($len) . $message;
        }elseif ($len <= 65535) {
            return "\x81" . chr(126) . pack("n", $len) . $message;
        }else {
            return "\x81" . chr(127) . pack("xxxxN", $len) . $message;
        }
    }

    //群聊发送给所有客服端
    public function send($message)
    {
        foreach($this->connectPool as $socket) {
            if($socket != $this->master) {
                socket_write($socket, $message, strlen($message));
            }
        }
    }
}