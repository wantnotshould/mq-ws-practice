<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

// 信号处理初始化 (仅限 Linux/Unix)
$shouldExit = false;
if (!$isWindows && extension_loaded('pcntl')) {
    // Linux/Unix 环境：使用信号量优雅退出
    pcntl_async_signals(true);
    $handler = function () use (&$shouldExit) {
        echo "\n [!] 接收到退出信号，处理完当前任务后安全停止...\n";
        $shouldExit = true;
    };
    pcntl_signal(SIGTERM, $handler); // Supervisor 停止信号
    pcntl_signal(SIGINT,  $handler); // Ctrl+C
} else {
    // Windows 环境：pcntl 不可用
    if ($isWindows) {
        echo " [i] 当前运行在 Windows 环境，信号监听已禁用 (请按 Ctrl+C 直接退出)。\n";
    }
}

$conf = new RdKafka\Conf();

// 设置消费组 ID (同一个组内的消费者会分担任务)
$conf->set('group.id', 'my_consumer_group');
$conf->set('bootstrap.servers', '127.0.0.1:9092');

// 当没有初始偏移量或偏移量失效时的策略：smallest 表示从头开始
$conf->set('auto.offset.reset', 'smallest');

$consumer = new RdKafka\KafkaConsumer($conf);

// 订阅 Topic
$consumer->subscribe(['test_topic']);

// 运行限制变量
$maxTasks = 5000;       // 处理 5000 个任务后自动退出，防止内存溢出
$memoryLimit = 128 * 1024 * 1024; // 128MB 内存上限
$processedCount = 0;

echo " [*] 正在监听消息... \n";

while (!$shouldExit) {
    // 等待消息，120000ms 为超时时间
    $message = $consumer->consume(120000);

    switch ($message->err) {
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            handleBusiness($message->payload);
            $processedCount++;
            // 可以在这里处理业务逻辑...
            break;
        case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            echo " [!] 已到达分区末尾，等待新消息...\n";
            break;
        case RD_KAFKA_RESP_ERR__TIMED_OUT:
            // 正常超时，不打印日志，避免刷屏
            break;
        default:
            echo " [错误] Kafka 异常: " . $message->errstr() . "\n";
            $shouldExit = true; // 严重错误则退出
            break;
    }

    if ($processedCount >= $maxTasks) {
        echo " [i] 达到处理上限 ($maxTasks)，准备退出/重启...\n";
        $shouldExit = true;
    }

    if (memory_get_usage() > $memoryLimit) {
        echo " [!] 内存占用过高 (" . round(memory_get_usage() / 1024 / 1024, 2) . "MB)，准备退出...\n";
        $shouldExit = true;
    }
}

// 清理退出
echo " [*] 正在注销消费者并释放资源...\n";
$consumer->unsubscribe();
echo " [OK] 进程已安全结束。\n";

/**
 * 业务逻辑封装
 * 使用函数包装可以确保局部变量在执行完后立即释放内存
 */
function handleBusiness($payload)
{
    $data = json_decode($payload, true);
    $id = $data['task_id'] ?? 'N/A';
    echo " [v] 正在处理任务 ID: $id \n";

    // 逻辑处理完毕后，建议清理较大的局部变量（如有）
    unset($data);
}
