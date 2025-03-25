<?php

namespace ReactphpX\TaskPoller\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\Promise;
use ReactphpX\TaskPoller\TaskPoller;
use ReactphpX\TaskPoller\TaskCancelledException;
use ReactphpX\TaskPoller\TaskFailedException;
use ReactphpX\TaskPoller\TaskMaxAttemptsException;
use ReactphpX\TaskPoller\TaskInvalidStatusException;
use function React\Async\await;

class TaskPollerTest extends TestCase
{
    private TaskPoller $poller;

    protected function setUp(): void
    {
        $this->poller = new TaskPoller(
            concurrency: 2,
            interval: 100,
            maxAttempts: 3
        );
    }

    public function testSuccessfulPolling()
    {
        $taskId = 'task-123';
        $result = ['status' => 'completed'];

        // Mock initial request
        $initialDeferred = new Deferred();
        $initialRequest = fn() => $initialDeferred->promise();

        // Mock status request
        $statusDeferred = new Deferred();
        $statusRequest = fn($data) => $statusDeferred->promise();

        // Set data extractor
        $this->poller->setStatusDataExtractor(function($response) use ($taskId) {
            return ['taskId' => $taskId];
        });

        // Set status checker
        $this->poller->setStatusChecker(function($response) use ($result) {
            return [
                'status' => 'SUCCESS',
                'result' => $result
            ];
        });

        // Start polling
        $promise = $this->poller->poll($initialRequest, $statusRequest);

        // Resolve initial request
        $initialDeferred->resolve((object)['body' => json_encode(['id' => $taskId])]);
        
        // Resolve status request
        $statusDeferred->resolve((object)['body' => json_encode($result)]);

        $data = await($promise);
        $this->assertEquals($result, $data);

    }

    public function testFailedPolling()
    {
        $taskId = 'task-123';
        $error = 'Task processing failed';

        // Mock requests
        $initialDeferred = new Deferred();
        $statusDeferred = new Deferred();

        $this->poller->setStatusDataExtractor(fn($response) => ['taskId' => $taskId]);
        $this->poller->setStatusChecker(fn($response) => [
            'status' => 'FAIL',
            'result' => ['error' => $error]
        ]);

        $promise = $this->poller->poll(
            fn() => $initialDeferred->promise(),
            fn($data) => $statusDeferred->promise()
        );

        $initialDeferred->resolve((object)['body' => json_encode(['id' => $taskId])]);
        $statusDeferred->resolve((object)['body' => json_encode(['error' => $error])]);

        $this->expectException(TaskFailedException::class);
        await($promise->then(null, function($e) {
            throw $e;
        }));
    }

    public function testMaxAttemptsExceeded()
    {
        $taskId = 'task-123';

        // Mock requests
        $initialDeferred = new Deferred();
        $statusDeferred = new Deferred();

        $this->poller->setStatusDataExtractor(fn($response) => ['taskId' => $taskId]);
        $this->poller->setStatusChecker(fn($response) => [
            'status' => 'PENDING',
            'result' => null
        ]);

        $promise = $this->poller->poll(
            fn() => $initialDeferred->promise(),
            fn($data) => $statusDeferred->promise()
        );

        $initialDeferred->resolve((object)['body' => json_encode(['id' => $taskId])]);
        
        // Resolve with PENDING status multiple times
        $statusDeferred->resolve((object)['body' => json_encode(['status' => 'PENDING'])]);
        $statusDeferred->resolve((object)['body' => json_encode(['status' => 'PENDING'])]);
        $statusDeferred->resolve((object)['body' => json_encode(['status' => 'PENDING'])]);

        $this->expectException(TaskMaxAttemptsException::class);
        await($promise->then(null, function($e) {
            throw $e;
        }));
    }

    public function testTaskCancellation()
    {
        $taskId = 'task-123';
        $initialDeferred = new Deferred();

        $promise = $this->poller->poll(
            fn() => $initialDeferred->promise(),
            fn($data) => new Promise(function() {})
        );

        $this->poller->cancel();

        $this->expectException(TaskCancelledException::class);
        $initialDeferred->resolve((object)['body' => json_encode(['id' => $taskId])]);
        
        await($promise->then(null, function($e) {
            throw $e;
        }));
    }
}
