<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

// 永不超时
set_time_limit(0);

$host = '127.0.0.1';
$port = 9501;

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($server, $host, $port)) {
    $errorCode = socket_last_error();
    $errorMsg = socket_strerror($errorCode);
    die("无法绑定端口 $port: [$errorCode] $errorMsg \n");
}

if (!socket_listen($server)) {
    die("无法监听套接字\n");
}

echo "Server started on $host:$port\n";

// 管理所有客户端连接
$clients = [$server];

while (true) {
    $read = $clients;
    $write = $except = null;

    // 监视套接字状态
    if (socket_select($read, $write, $except, 0, 10) < 1) continue;

    // 如果 $server 在 $read 中，说明有新连接
    if (in_array($server, $read)) {
        $newClient = socket_accept($server);
        $clients[] = $newClient;

        // 执行 WebSocket 握手
        $header = socket_read($newClient, 1024);
        perform_handshake($header, $newClient, $host, $port);

        echo "New client connected!\n";
        unset($read[array_search($server, $read)]);
    }

    // 处理现有客户端发来的消息
    foreach ($read as $clientSocket) {
        $data = socket_read($clientSocket, 2048);

        if ($data === false || strlen($data) === 0) {
            $index = array_search($clientSocket, $clients);
            unset($clients[$index]);
            socket_close($clientSocket);
            echo "Client disconnected.\n";
            continue;
        }

        // 解码 WebSocket 数据帧
        $decodedData = unmask($data);
        echo "Received: " . $decodedData . "\n";

        // 响应客户端（编码为数据帧）
        $response = mask("Server received: " . $decodedData);
        foreach ($clients as $sendSocket) {
            if ($sendSocket != $server) {
                socket_write($sendSocket, $response, strlen($response));
            }
        }
    }
}

// 握手
function perform_handshake($receved_header, $client_conn, $host, $port)
{
    $headers = array();
    $lines = preg_split("/\r\n/", $receved_header);
    foreach ($lines as $line) {
        $line = chop($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }

    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $buffer  = "HTTP/1.1 101 Switching Protocols\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
    socket_write($client_conn, $buffer, strlen($buffer));
}


// 数据解码
function unmask($text)
{
    $length = ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

// 数据编码
function mask($text)
{
    $b1 = 0x81;
    $length = strlen($text);
    if ($length <= 125) $header = pack('CC', $b1, $length);
    elseif ($length > 125 && $length < 65536) $header = pack('CCn', $b1, 126, $length);
    elseif ($length >= 65536) $header = pack('CCNN', $b1, 127, $length);
    return $header . $text;
}
