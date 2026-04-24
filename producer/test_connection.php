<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$conf = new RdKafka\Conf();
$conf->set('bootstrap.servers', '127.0.0.1:9092');
$conf->set('socket.timeout.ms', '3000');   // 注意：用字符串 '3000'

try {
    $producer = new RdKafka\Producer($conf);
    // 获取元数据会触发实际连接
    $metadata = $producer->getMetadata(false, null, 5000);
    echo "连接成功！ Broker 数量: " . count($metadata->getBrokers()) . "\n";
} catch (Exception $e) {
    echo "连接失败: " . $e->getMessage() . "\n";
}
