<?php
include __DIR__.'/dream/DreamChat.php';

$ip = '0.0.0.0';         //ip地址
$port = 8888;           //端口号

new \dream\DreamChat($ip, $port);       //实例化类的时候就把$ip和$port一起传过去了