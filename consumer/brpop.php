<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$redisPass = getenv('REDIS_PASSWORD');
$queueName = 'task_queue';

$redis = new Redis();

try {
    // connection timeout = 0 (永不超时)
    $redis->connect('127.0.0.1', 6379, 0);

    if ($redisPass && !$redis->auth($redisPass)) {
        die("Redis 认证失败！\n");
    }

    /**
     * 防止读取超时
     * 默认 php.ini 里的 default_socket_timeout 是 60s
     * 设置为 -1 确保 brPop 在等待数据时，连接不会被 PHP 主动掐断
     */
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

    echo " [*] 成功连接并监听队列: $queueName. 退出请按 CTRL+C\n";

    // 消费者循环
    while (true) {
        try {
            // brPop 会在这里阻塞，不消耗 CPU
            $result = $redis->brPop([$queueName], 0);

            if ($result) {
                // $result[0] 是键名, $result[1] 是数据内容内容
                handleTask($result[1]);
            }
        } catch (RedisException $e) {
            echo "连接中断，尝试重连... " . $e->getMessage() . "\n";
            // 可以在这里写重连逻辑，或者简单地抛出异常然后重启脚本
            throw $e;
        }

        /**
         * 内存优化
         * 强制周期性触发垃圾回收
         * 避免在循环体内使用 $global_array[] = $data 这种无限累加的操作
         */
        if (gc_enabled()) {
            gc_collect_cycles();
        }
    }
} catch (Exception $e) {
    echo "脚本异常退出: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($redis) && $redis->isConnected()) {
        $redis->close();
    }
}

/**
 * 将复杂的逻辑封装到函数中
 * 这样函数内的局部变量在处理完后会自动销毁，不会留在内存中
 */
function handleTask($data)
{
    echo " [v] 开始处理任务: $data \n";

    // 业务逻辑...
    sleep(1);

    // 注意：不要在这里声明 global 变量或 static 变量
    unset($data); // 显式释放（虽然函数结束会自动回收）
}
