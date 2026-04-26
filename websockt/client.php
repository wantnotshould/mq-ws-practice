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
if (!$socket) {
    die("无法创建 socket: " . socket_strerror(socket_last_error()) . "\n");
}

echo "正在连接到 $host:$port...\n";
if (!socket_connect($socket, $host, $port)) {
    die("连接失败: " . socket_strerror(socket_last_error()) . "\n");
}

// 构造 WebSocket 握手请求
// WebSocket 必须先通过 HTTP 协议升级（Upgrade）
$key = base64_encode(random_bytes(16));
$header = "GET / HTTP/1.1\r\n" .
    "Host: $host:$port\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "Sec-WebSocket-Key: $key\r\n" .
    "Sec-WebSocket-Version: 13\r\n\r\n";

socket_write($socket, $header, strlen($header));

// 读取服务器返回的握手响应
$response = socket_read($socket, 1024);
if (str_contains($response, '101 Switching Protocols')) {
    echo "--- 握手成功! ---\n";
} else {
    die("握手失败: \n$response\n");
}

// 发送一条消息
$message = "Hello Server! 我是 PHP 客户端";
echo "发送消息: $message\n";
$frame = encode_websocket_frame($message);
socket_write($socket, $frame, strlen($frame));

// 接收服务器的回应
// 注意：服务端发回的数据也是带帧格式的，需要解码
$data = socket_read($socket, 2048);
if ($data) {
    echo "收到回复: " . decode_websocket_frame($data) . "\n";
}

// 关闭连接
socket_close($socket);


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
