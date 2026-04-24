<?php

/**
 * Copyright ©2026 cdme. All rights reserved.
 * Author: https://cdme.cn
 * Email:  hi@cdme.cn
 */

declare(strict_types=1);

$redis = new Redis();
$redisPass = getenv('REDIS_PASSWORD');

try {
    $redis->connect('127.0.0.1', 6379);

    if ($redisPass) {
        if (!$redis->auth($redisPass)) {
            die("Redis 认证失败！");
        }
    }

    for ($i = 1; $i <= 5; $i++) {
        $task = "Task_ID_" . $i . "_" . time();
        $redis->lPush('task_queue', $task);
        echo " [x] Sent: $task \n";
    }
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
} finally {
    $redis->close();
}
