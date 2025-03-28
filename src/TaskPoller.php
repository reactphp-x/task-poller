<?php

namespace ReactphpX\TaskPoller;

/**
 * TaskPoller - 异步任务轮询器
 * 
 * 功能特性:
 * 1. 支持任务并发控制
 * 2. 支持轮询间隔设置
 * 3. 支持最大尝试次数限制
 * 4. 支持自定义状态检查逻辑
 * 5. 支持任务取消
 * 6. 支持异步数据提取和状态检查
 */

use React\Promise\PromiseInterface;
use ReactphpX\LimiterConcurrent\LimiterConcurrent;
use ReactphpX\Concurrent\Concurrent;

// 添加自定义异常类
class TaskPollerException extends \RuntimeException {}
class TaskCancelledException extends TaskPollerException {}
class TaskFailedException extends TaskPollerException {}
class TaskMaxAttemptsException extends TaskPollerException {}
class TaskInvalidStatusException extends TaskPollerException {}

class TaskPoller
{
    private $limiter;
    private $cancelled = false;
    private $statusDataExtractor;
    private $managerName = 'default';

    public function __construct(
        private $concurrency = 1,
        private $interval = 1000,
        private $maxAttempts = 100,
        private $statusChecker = null
    ) {
        // 初始化当前全局任务管理器
        $this->limiter = new LimiterConcurrent($concurrency, $interval);

        // Default status checker
        if ($this->statusChecker === null) {
            $this->statusChecker = function ($response, $cancelled) {
                if ($cancelled) {
                    throw new TaskCancelledException('Polling cancelled');
                }
                
                $data = $response;
                if (is_object($response) && method_exists($response, 'getBody')) {
                    $data = json_decode((string)$response->getBody(), true);
                }
                if (is_object($data)) {
                    $data = (array)$data;
                }
                
                return [
                    'status' => $data['status'] ?? 'PENDING',
                    'result' => $data
                ];
            };
        }

        // Default data extractor
        $this->statusDataExtractor = function ($response, $cancelled) {
            if ($cancelled) {
                throw new TaskCancelledException('Polling cancelled');
            }
            
            $data = $response;
            if (is_object($response) && method_exists($response, 'getBody')) {
                $data = json_decode((string)$response->getBody(), true);
            }
            if (is_object($data)) {
                $data = (array)$data;
            }
            
            return [
                'taskId' => $data['id'] ?? null,
                'extraData' => $data
            ];
        };
    }

    public function setStatusDataExtractor(callable $extractor): self
    {
        $this->statusDataExtractor = $extractor;
        return $this;
    }

    public function setStatusChecker(callable $checker): self
    {
        $this->statusChecker = $checker;
        return $this;
    }

    public function setManager(string $name): self
    {
        $this->managerName = $name;
        return $this;
    }

    public function poll(callable $initialRequest, callable $statusRequest, ?callable $successHandler = null): PromiseInterface
    {
        if ($this->cancelled) {
            throw new TaskCancelledException('Polling cancelled');
        }
        
        return TaskPollerManager::getInstance($this->managerName)->getConcurrent()->concurrent(
            fn() => \React\Promise\resolve($initialRequest())->then(function ($response) use ($statusRequest, $successHandler) {
                return \React\Promise\resolve(($this->statusDataExtractor)($response, $this->cancelled))->then(function ($extractedData) use ($statusRequest, $successHandler) {
                    return $this->startPolling($statusRequest, $extractedData, 0, $successHandler);
                });
            })
        );
    }

    private function startPolling(callable $statusRequest, array $data, int $attempt = 0, ?callable $successHandler = null): PromiseInterface
    {
        if ($attempt >= $this->maxAttempts) {
            throw new TaskMaxAttemptsException('Max attempts reached');
        }

        return $this->limiter->concurrent(function () use ($statusRequest, $data) {
            return $statusRequest($data);
        })->then(function ($response) use ($statusRequest, $data, $attempt, $successHandler) {
            return \React\Promise\resolve(($this->statusChecker)($response, $this->cancelled))->then(function ($status) use ($statusRequest, $data, $attempt, $successHandler) {
                if (!isset($status['status'])) {
                    throw new TaskInvalidStatusException('Status field is required in response');
                }

                if ($status['status'] === 'SUCCESS') {
                    if ($successHandler) {
                        return $successHandler($status['result']);
                    }
                    return $status['result'];
                }

                if ($status['status'] === 'FAIL') {
                    throw new TaskFailedException(json_encode($status['result']));
                }

                return $this->startPolling($statusRequest, $data, $attempt + 1);
            });
        });
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
