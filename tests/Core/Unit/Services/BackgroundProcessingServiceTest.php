<?php

namespace Tests\Core\Unit\Services;

use App\Services\BackgroundProcessingService;
use Tests\TestCase;

class BackgroundProcessingServiceTest extends TestCase
{
    protected BackgroundProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BackgroundProcessingService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function maintainConcurrentTasksHandlesCompletedProcesses(): void
    {
        $mockProcess = \Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $mockProcessResult = \Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class);

        $mockProcess->shouldReceive('running')
            ->once()
            ->andReturn(false);

        $mockProcess->shouldReceive('wait')
            ->once()
            ->andReturn($mockProcessResult);

        $mockProcessResult->shouldReceive('output')
            ->once()
            ->andReturn('test output');

        $mockProcessResult->shouldReceive('errorOutput')
            ->once()
            ->andReturn('');

        $mockProcessResult->shouldReceive('exitCode')
            ->once()
            ->andReturn(0);

        $reflection = new \ReflectionClass($this->service);
        $backgroundTasksProperty = $reflection->getProperty('backgroundTasks');
        $backgroundTasksProperty->setAccessible(true);
        $backgroundTasksProperty->setValue($this->service, [
            'test_task_123' => [
                'id' => 'test_task_123',
                'type' => 'test',
                'data' => ['path' => '/test'],
                'process' => $mockProcess,
                'started_at' => microtime(true)
            ]
        ]);

        $maintainMethod = $reflection->getMethod('maintainConcurrentTasks');
        $maintainMethod->setAccessible(true);
        $maintainMethod->invoke($this->service);

        $completedTasksProperty = $reflection->getProperty('completedTasks');
        $completedTasksProperty->setAccessible(true);
        $completedTasks = $completedTasksProperty->getValue($this->service);

        $this->assertArrayHasKey('test_task_123', $completedTasks);
        $this->assertTrue($completedTasks['test_task_123']['success']);
        $this->assertEquals('test output', $completedTasks['test_task_123']['output']);
        $this->assertEquals(0, $completedTasks['test_task_123']['exit_code']);

        $backgroundTasks = $backgroundTasksProperty->getValue($this->service);
        $this->assertArrayNotHasKey('test_task_123', $backgroundTasks);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function maintainConcurrentTasksHandlesFailedProcesses(): void
    {
        $mockProcess = \Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $mockProcessResult = \Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class);

        $mockProcess->shouldReceive('running')
            ->once()
            ->andReturn(false);

        $mockProcess->shouldReceive('wait')
            ->once()
            ->andReturn($mockProcessResult);

        $mockProcessResult->shouldReceive('output')
            ->once()
            ->andReturn('');

        $mockProcessResult->shouldReceive('errorOutput')
            ->once()
            ->andReturn('error message');

        $mockProcessResult->shouldReceive('exitCode')
            ->once()
            ->andReturn(1);

        $reflection = new \ReflectionClass($this->service);
        $backgroundTasksProperty = $reflection->getProperty('backgroundTasks');
        $backgroundTasksProperty->setAccessible(true);
        $backgroundTasksProperty->setValue($this->service, [
            'test_task_456' => [
                'id' => 'test_task_456',
                'type' => 'test',
                'data' => ['path' => '/test'],
                'process' => $mockProcess,
                'started_at' => microtime(true)
            ]
        ]);

        $maintainMethod = $reflection->getMethod('maintainConcurrentTasks');
        $maintainMethod->setAccessible(true);
        $maintainMethod->invoke($this->service);

        $completedTasksProperty = $reflection->getProperty('completedTasks');
        $completedTasksProperty->setAccessible(true);
        $completedTasks = $completedTasksProperty->getValue($this->service);

        $this->assertArrayHasKey('test_task_456', $completedTasks);
        $this->assertFalse($completedTasks['test_task_456']['success']);
        $this->assertEquals('error message', $completedTasks['test_task_456']['error']);
        $this->assertEquals(1, $completedTasks['test_task_456']['exit_code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getTaskStatisticsReturnsCorrectCounts(): void
    {
        $stats = $this->service->getTaskStatistics();

        $this->assertArrayHasKey('active_tasks', $stats);
        $this->assertArrayHasKey('queued_tasks', $stats);
        $this->assertArrayHasKey('completed_tasks', $stats);
        $this->assertArrayHasKey('max_concurrent', $stats);
        $this->assertEquals(0, $stats['active_tasks']);
        $this->assertEquals(0, $stats['queued_tasks']);
        $this->assertEquals(0, $stats['completed_tasks']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function clearCompletedTasksClearsMemory(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $completedTasksProperty = $reflection->getProperty('completedTasks');
        $completedTasksProperty->setAccessible(true);
        $completedTasksProperty->setValue($this->service, [
            'task1' => ['id' => 'task1'],
            'task2' => ['id' => 'task2']
        ]);

        $this->service->clearCompletedTasks();

        $completedTasks = $completedTasksProperty->getValue($this->service);
        $this->assertEmpty($completedTasks);
    }
}
