# Task Poller

异步任务轮询器，用于处理需要轮询查询状态的异步任务。

## install

```
composer require reactphp-x/task-poller
```

## 功能特性

1. 支持任务并发控制
   - 全局任务并发：通过 TaskPollerManager 统一管理所有轮询器的任务并发
   - 多任务管理器：支持创建多个命名管理器实例，适用于不同业务场景
   - 轮询并发：每个轮询器实例可独立控制状态检查的并发数
2. 支持轮询间隔设置
3. 支持最大尝试次数限制
4. 支持自定义状态检查逻辑
5. 支持任务取消
6. 支持异步数据提取和状态检查

## 使用示例

```php
// 初始化多个任务管理器
TaskPollerManager::init('api', 5, 0);     // API任务管理器：并发5，无限制
TaskPollerManager::init('queue', 10, 100); // 队列任务管理器：并发10，最大100任务

// 创建轮询器实例并指定使用的管理器
$poller = new TaskPoller(
    concurrency: 2,     // 轮询并发数
    interval: 1000,     // 轮询间隔（毫秒）
    maxAttempts: 100    // 最大尝试次数
);
$poller->setManager('api'); // 使用API任务管理器

// 设置数据提取器 - 从初始请求响应中提取数据
// 支持同步返回或返回 Promise
$poller->setStatusDataExtractor(function ($response, $cancelled) {
    if ($cancelled) {
        throw new \RuntimeException('Data extraction cancelled');
    }
    
    // 同步方式
    $data = json_decode((string)$response->getBody(), true);
    return [
        'taskId' => $data['id'],
        'token' => $data['token'],
        'extraData' => $data
    ];

    // 异步方式
    return someAsyncOperation()->then(function ($data) use ($cancelled) {
        if ($cancelled) {
            throw new \RuntimeException('Data extraction cancelled');
        }
        return [
            'taskId' => $data['id'],
            'token' => $data['token'],
            'extraData' => $data
        ];
    });
});

// 设置状态检查器 - 检查任务状态
// 支持同步返回或返回 Promise
$poller->setStatusChecker(function ($response, $cancelled) {
    if ($cancelled) {
        throw new \RuntimeException('Status check cancelled');
    }
    
    // 同步方式
    $data = json_decode((string)$response->getBody(), true);
    return [
        'status' => $data['task_status'],  // SUCCESS|FAIL|PENDING
        'result' => $data
    ];

    // 异步方式
    return someAsyncOperation()->then(function ($data) use ($cancelled) {
        if ($cancelled) {
            throw new \RuntimeException('Status check cancelled');
        }
        return [
            'status' => $data['task_status'],  // SUCCESS|FAIL|PENDING
            'result' => $data
        ];
    });
});

// 开始轮询
$promise = $poller->poll(
    // 初始请求 - 创建任务
    fn() => $client->request('POST', 'https://api.example.com/tasks', [
        'json' => ['param' => 'value']
    ]),
    
    // 状态检查请求 - 查询任务状态
    fn($data) => $client->request('GET', "https://api.example.com/tasks/{$data['taskId']}", [
        'headers' => ['Authorization' => "Bearer {$data['token']}"]
    ]),
    
    // 成功回调（可选） - 处理成功结果
    fn($result) => processSuccessResult($result)
);

// 处理结果
$promise->then(
    fn($result) => var_dump('Success:', $result),
    fn($error) => match (true) {
        $error instanceof TaskCancelledException => var_dump('Task cancelled'),
        $error instanceof TaskFailedException => var_dump('Task failed:', $error->getMessage()),
        $error instanceof TaskMaxAttemptsException => var_dump('Max attempts reached'),
        $error instanceof TaskInvalidStatusException => var_dump('Invalid status'),
        default => var_dump('Other error:', $error->getMessage())
    }
);
```
