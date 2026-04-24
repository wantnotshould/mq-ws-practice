<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$conf = new RdKafka\Conf();
// Kafka 地址，如果在 Docker 外部运行填 localhost:9092
$conf->set('bootstrap.servers', '127.0.0.1:9092');

$producer = new RdKafka\Producer($conf);
$topic = $producer->newTopic("test_topic");

for ($i = 0; $i < 5; $i++) {
    $payload = "Message payload " . $i . " at " . date('Y-m-d H:i:s');

    /**
     * 参数说明：
     * RD_KAFKA_PARTITION_UA: 自动选择分区
     * 0: 消息标志
     * $payload: 消息内容
     */
    $topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload);

    // 轮询等待发送回调，确保消息发出
    $producer->poll(0);
    echo " [x] Sent: $payload \n";
}

// 彻底冲刷队列，确保最后几条消息不会因为脚本结束而丢失
$producer->flush(10000); // 等待最多 10 秒