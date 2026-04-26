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
$last_activity = []; // 格式：[socket_id => timestamp]

while (true) {
    $read = $clients;
    $write = $except = null;

    // 监视套接字，设置 1 秒超时，以便程序能定期向下运行检查“心跳”
    if (socket_select($read, $write, $except, 1) === false) {
        break;
    }

    // 处理新连接
    if (in_array($server, $read)) {
        $newClient = socket_accept($server);
        if ($newClient) {
            $clients[] = $newClient;

            // 进行 WebSocket 握手
            $header = socket_read($newClient, 1024);
            perform_handshake($header, $newClient);

            $socketID = spl_object_id($newClient);
            $last_activity[$socketID] = time(); // 初始化活动时间

            echo "新客户端连接: ID[$socketID]\n";
        }
        // 从 read 数组中移除监听 socket，剩下的就是有数据发来的客户端
        unset($read[array_search($server, $read)]);
    }

    // 处理客户端消息
    foreach ($read as $clientSocket) {
        $socketID = spl_object_id($clientSocket);
        $data = @socket_read($clientSocket, 2048);

        // 如果读取不到数据，说明客户端主动关闭了连接
        if ($data === false || strlen($data) === 0) {
            close_client($clientSocket, $clients, $last_activity);
            continue;
        }

        // 只要收到数据，无论是 Ping 还是业务数据，都更新活动时间
        $last_activity[$socketID] = time();

        // 解码数据帧
        $decodedData = unmask($data);

        // 忽略空的或心跳响应数据，只处理有内容的业务逻辑
        if ($decodedData !== "") {
            echo "收到来自 ID[$socketID] 的消息: $decodedData\n";

            // 广播回复（编码为数据帧）
            $response = mask("服务器已收到: " . $decodedData);
            foreach ($clients as $sendSocket) {
                if ($sendSocket !== $server) {
                    @socket_write($sendSocket, $response, strlen($response));
                }
            }
        }
    }

    // 心跳检测清理逻辑
    $now = time();
    foreach ($clients as $key => $clientSocket) {
        if ($clientSocket === $server) continue; // 跳过监听 socket

        $socketID = spl_object_id($clientSocket);
        $idleTime = $now - ($last_activity[$socketID] ?? $now);

        // 如果 30 秒没动静，发送一个 Ping 帧 (0x89 0x00)
        if ($idleTime > 30 && $idleTime <= 40) {
            // 我们尝试发送一个标准的 WebSocket Ping 帧
            $pingFrame = pack('H*', '8900');
            @socket_write($clientSocket, $pingFrame, strlen($pingFrame));
        }
        // 如果超过 40 秒还没任何活动（包括没回 Pong），则判定为掉线
        elseif ($idleTime > 40) {
            echo "客户端 ID[$socketID] 响应超时，正在强制断开...\n";
            close_client($clientSocket, $clients, $last_activity);
        }
    }
}

// 握手
function perform_handshake($receved_header, $client_conn)
{
    $headers = [];
    $lines = preg_split("/\r\n/", $receved_header);
    foreach ($lines as $line) {
        $line = chop($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }

    if (!isset($headers['Sec-WebSocket-Key'])) return;

    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $buffer = "HTTP/1.1 101 Switching Protocols\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
    socket_write($client_conn, $buffer, strlen($buffer));
}


// 数据解码
function unmask($text)
{
    if (strlen($text) < 2) return "";
    $length = ord($text[1]) & 127;

    // 检查是否有 Mask (客户端发来的数据必须有 Mask)
    $isMasked = (ord($text[1]) & 128) !== 0;
    if (!$isMasked) return $text; // 理论上不应发生

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
    $b1 = 0x81; // Fin = 1, Opcode = 1 (Text)
    $length = strlen($text);

    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length < 65536) {
        $header = pack('CCn', $b1, 126, $length);
    } else {
        $header = pack('CCNN', $b1, 127, 0, $length);
    }
    return $header . $text;
}

function close_client($socket, &$clients, &$last_activity)
{
    $socketID = spl_object_id($socket);
    $index = array_search($socket, $clients);
    if ($index !== false) {
        unset($clients[$index]);
    }
    unset($last_activity[$socketID]);
    @socket_close($socket);
    echo "客户端 ID[$socketID] 已断开连接。\n";
}
