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

$conf->set('acks', 'all');
// 设置发送重试次数
$conf->set('retries', '3');
$conf->set('linger.ms', '5');

$producer = new RdKafka\Producer($conf);
$topic = $producer->newTopic("test_topic");

echo " [x] 开始发送任务至 127.0.0.1:9092...\n";

for ($i = 0; $i < 5; $i++) {
    $payload = json_encode([
        'task_id' => $i,
        'time' => date('Y-m-d H:i:s'),
        'content' => 'Hello Kafka ' . $i
    ]);

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
    usleep(100000); // 模拟间隔
}

// 彻底冲刷队列，确保最后几条消息不会因为脚本结束而丢失
// 修复点：将 flush 的返回值赋值给 $result
$result = $producer->flush(10000);

if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
    echo " [!] 警告：部分消息未能成功发送。错误代码: $result \n";
} else {
    echo " [OK] 所有消息已成功冲刷至 Kafka。\n";
}
