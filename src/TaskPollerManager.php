<?php

namespace ReactphpX\TaskPoller;

use ReactphpX\Concurrent\Concurrent;

/**
 * TaskPollerManager - 任务轮询管理器
 * 
 * 功能特性:
 * 1. 支持多实例管理
 *    - 可创建多个命名管理器实例
 *    - 适用于不同业务场景的并发控制
 * 2. 支持全局任务并发控制
 *    - 可设置每个管理器实例的并发数
 *    - 可限制最大任务数
 * 
 * 使用示例:
 * 
 * // 初始化不同场景的管理器
 * TaskPollerManager::init('api', 5, 0);     // API任务：并发5，无限制
 * TaskPollerManager::init('queue', 10, 100); // 队列任务：并发10，最大100任务
 * 
 * // 获取管理器实例
 * $apiManager = TaskPollerManager::getInstance('api');
 * $queueManager = TaskPollerManager::getInstance('queue');
 * 
 * // 移除指定管理器
 * TaskPollerManager::remove('api');
 * 
 * // 清理所有管理器
 * TaskPollerManager::clear();
 */
class TaskPollerManager
{
    private static $instances = [];
    private $concurrent;

    private function __construct(int $taskConcurrent = 1, int $maxTask = 0)
    {
        $this->concurrent = new Concurrent($taskConcurrent, $maxTask);
    }

    public static function getInstance(string $name = 'default'): self
    {
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new self();
        }
        return self::$instances[$name];
    }

    public static function init(string $name = 'default', int $taskConcurrent = 1, int $maxTask = 0): void
    {
        self::$instances[$name] = new self($taskConcurrent, $maxTask);
    }

    public static function remove(string $name): void
    {
        unset(self::$instances[$name]);
    }

    public static function clear(): void
    {
        self::$instances = [];
    }

    public function getConcurrent(): Concurrent
    {
        return $this->concurrent;
    }
}