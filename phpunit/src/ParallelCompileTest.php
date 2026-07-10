<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;

class ParallelCompileTest extends TestCase
{
    public function testWaitRetriesWhenInterrupted(): void
    {
        $compiler = new ScriptedWaitCompiler([
            [-1, 0, PCNTL_EINTR],
            [123, 0, 0],
        ]);

        $this->assertSame([123, 0], $compiler->waitForTest());
        $this->assertSame(2, $compiler->getWaitCallCount());
    }

    public function testWaitFailureOtherThanInterruptionThrows(): void
    {
        $compiler = new ScriptedWaitCompiler([
            [-1, 0, PCNTL_ECHILD],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to wait for compiler process');
        $compiler->waitForTest();
    }

    public function testSignaledChildIsNotSuccessful(): void
    {
        $compiler = new ScriptedWaitCompiler([]);

        $this->assertTrue($compiler->statusSucceeded(0));
        $this->assertFalse($compiler->statusSucceeded(1 << 8));
        $this->assertFalse($compiler->statusSucceeded(SIGTERM));
    }

    public function testForkFailureStillReapsRunningCompilerProcesses(): void
    {
        $compiler = new ScriptedWaitCompiler(
            [[101, 0, 0]],
            [101, -1]
        );

        try {
            $compiler->compileInParallelForTest(['first.cc', 'second.cc', 'third.cc'], 2);
            $this->fail('The fork failure should fail the parallel compilation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('second.cc', $e->getMessage());
            $this->assertStringContainsString('third.cc', $e->getMessage());
        }

        $this->assertSame(1, $compiler->getWaitCallCount());
    }
}

class ScriptedWaitCompiler extends CompilerTest
{
    private int $waitCallCount = 0;
    private int $lastWaitError = 0;

    public function __construct(private array $waitResults, private array $forkResults = [])
    {
        parent::__construct(ROOT_PATH);
        $this->noProgress = true;
    }

    protected function pcntlFork(): int
    {
        return array_shift($this->forkResults);
    }

    protected function pcntlWait(?int &$status): int
    {
        $this->waitCallCount++;
        [$pid, $status, $this->lastWaitError] = array_shift($this->waitResults);
        return $pid;
    }

    protected function pcntlLastError(): int
    {
        return $this->lastWaitError;
    }

    public function waitForTest(): array
    {
        return $this->waitForCompileChild();
    }

    public function statusSucceeded(int $status): bool
    {
        return $this->compileChildSucceeded($status);
    }

    public function getWaitCallCount(): int
    {
        return $this->waitCallCount;
    }

    public function compileInParallelForTest(array $sourceFiles, int $jobs): array
    {
        return $this->compileWithPcntl($sourceFiles, $jobs);
    }
}
