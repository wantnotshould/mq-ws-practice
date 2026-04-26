<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$host = '127.0.0.1';
$port = 9501;

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) die("无法创建 socket\n");

echo "正在连接到 $host:$port...\n";
if (!socket_connect($socket, $host, $port)) die("连接失败\n");

// WebSocket 握手
$key = base64_encode(random_bytes(16));
$header = "GET / HTTP/1.1\r\n" .
    "Host: $host:$port\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "Sec-WebSocket-Key: $key\r\n" .
    "Sec-WebSocket-Version: 13\r\n\r\n";
socket_write($socket, $header, strlen($header));
$response = socket_read($socket, 1024);

if (!str_contains($response, '101 Switching Protocols')) die("握手失败\n");
echo "--- 握手成功！输入消息并回车发送 (Ctrl+C 退出) ---\n";

// 设置 Socket 为非阻塞模式，这样读取服务器消息时不会卡住
socket_set_nonblock($socket);

// 设置标准输入(键盘)为非阻塞（仅限类Unix系统，Windows下通常使用 stream_set_blocking）
$stdin = fopen("php://stdin", "r");
stream_set_blocking($stdin, false);

while (true) {
    // 1. 尝试从键盘读取输入
    $line = fgets($stdin);
    if ($line !== false) {
        $line = trim($line);
        if ($line !== "") {
            $frame = encode_websocket_frame($line);
            @socket_write($socket, $frame, strlen($frame));
            echo "已发送 >> $line\n";
        }
    }

    // 尝试从服务器读取回复
    $resData = @socket_read($socket, 2048);
    if ($resData !== false && strlen($resData) > 0) {
        // 检查是否是 Ping 帧 (0x89)
        if (ord($resData[0]) === 0x89) {
            // 自动回复 Pong (0x8A)
            $pong = pack('H*', '8a00');
            @socket_write($socket, $pong, strlen($pong));
        } else {
            $msg = decode_websocket_frame($resData);
            if ($msg !== "") {
                echo "\n收到回复 << $msg\n> ";
            }
        }
    }

    usleep(100000); // 休息 0.1 秒，防止 CPU 满载
}

/**
 * 编码函数：将普通字符串包装成 WebSocket 数据帧 (带掩码)
 * 客户端发往服务端的消息必须进行 Mask 处理
 */
function encode_websocket_frame(string $text): string
{
    $b1 = 0x81; // Fin = 1, Opcode = 1 (Text)
    $length = strlen($text);

    // 负载长度处理
    if ($length <= 125) {
        $header = pack('CC', $b1, $length | 0x80); // 0x80 表示设置了掩码标志位
    } elseif ($length < 65536) {
        $header = pack('CCn', $b1, 126 | 0x80, $length);
    } else {
        $header = pack('CCNN', $b1, 127 | 0x80, 0, $length);
    }

    // 生成随机 4 字节掩码
    $mask = random_bytes(4);
    $header .= $mask;

    // 对原始数据进行异或(XOR)运算处理
    $maskedData = '';
    for ($i = 0; $i < $length; $i++) {
        $maskedData .= $text[$i] ^ $mask[$i % 4];
    }

    return $header . $maskedData;
}

/**
 * 解码函数：将 WebSocket 数据帧解析为普通字符串
 * 服务端发往客户端的数据通常不带掩码
 */
function decode_websocket_frame(string $data): string
{
    $length = ord($data[1]) & 127;
    $masks = '';
    $payload = '';

    // 根据长度位确定数据起始偏移量
    if ($length === 126) {
        $masks = substr($data, 4, 4); // 这里简化处理，假设服务端没带掩码（标准规范服务端不强制带）
        $payload = substr($data, 8);
    } elseif ($length === 127) {
        $masks = substr($data, 10, 4);
        $payload = substr($data, 14);
    } else {
        // 如果第2字节最高位(Mask位)为0，说明没掩码
        $isMasked = (ord($data[1]) & 0x80) !== 0;
        if ($isMasked) {
            $masks = substr($data, 2, 4);
            $payload = substr($data, 6);
            $decoded = "";
            for ($i = 0; $i < strlen($payload); $i++) {
                $decoded .= $payload[$i] ^ $masks[$i % 4];
            }
            return $decoded;
        } else {
            return substr($data, 2);
        }
    }
    return $payload;
}
