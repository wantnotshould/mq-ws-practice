<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$conf = new RdKafka\Conf();

// 设置消费组 ID (同一个组内的消费者会分担任务)
$conf->set('group.id', 'my_consumer_group');
$conf->set('bootstrap.servers', '127.0.0.1:9092');

// 当没有初始偏移量或偏移量失效时的策略：smallest 表示从头开始
$conf->set('auto.offset.reset', 'smallest');

$consumer = new RdKafka\KafkaConsumer($conf);

// 订阅 Topic
$consumer->subscribe(['test_topic']);

echo " [*] Waiting for messages... To exit press CTRL+C\n";

while (true) {
    // 等待消息，120000ms 为超时时间
    $message = $consumer->consume(120000);

    switch ($message->err) {
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            echo " [v] Received: " . $message->payload . "\n";
            // 可以在这里处理业务逻辑...
            break;
        case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            echo " [!] 已到达分区末尾，等待新消息...\n";
            break;
        case RD_KAFKA_RESP_ERR__TIMED_OUT:
            echo " [!] 超时，队列中暂时没有消息。\n";
            break;
        default:
            throw new \Exception($message->errstr(), $message->err);
    }
}
