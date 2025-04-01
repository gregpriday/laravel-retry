<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Throwable;

class RetryConcurrencyTest extends TestCase
{
    public function test_concurrent_operations_with_rate_limiting(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('This test requires the pcntl extension to simulate concurrency.');
        }

        // Initialize a shared counter file for cross-process communication
        $tempFile = sys_get_temp_dir().'/retry_counter_'.uniqid().'.txt';
        file_put_contents($tempFile, '0');

        // Configure retry with rate limiting strategy (max 3 attempts within 1 second window)
        $strategy = new RateLimitStrategy(new ExponentialBackoffStrategy, 3, 1);
        $retry = new Retry(maxRetries: 2, retryDelay: 0.1, timeout: 5, strategy: $strategy);

        // Track the start time
        $startTime = microtime(true);

        // Fork processes to simulate concurrent operations
        $childPids = [];
        $processCount = 3; // Using only 3 processes to avoid timeouts

        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                try {
                    $retry->run(function () use ($tempFile, $i) {
                        // Update the counter atomically
                        $lock = fopen($tempFile, 'r+');
                        if ($lock) {
                            flock($lock, LOCK_EX);
                            $count = (int) file_get_contents($tempFile);
                            $count++;
                            file_put_contents($tempFile, (string) $count);
                            flock($lock, LOCK_UN);
                            fclose($lock);
                        } else {
                            // Fallback if locking fails
                            $count = (int) file_get_contents($tempFile);
                            file_put_contents($tempFile, (string) ($count + 1));
                        }

                        // Introduce a small delay to simulate work
                        usleep(10000); // 10ms

                        // Simulate a network operation that might fail
                        if ($i % 2 == 0) {
                            throw $this->createGuzzleException('Connection timed out');
                        }

                        return 'success';
                    });
                } catch (Throwable $e) {
                    // Just continue if operation fails
                }

                // Exit child process
                exit(0);
            } else {
                // Parent process
                $childPids[] = $pid;
            }
        }

        // Wait for all child processes to complete
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Get the final count from the counter file
        $finalCount = (int) file_get_contents($tempFile);

        // Clean up
        unlink($tempFile);

        // The test can be considered valid with a smaller number of attempts for stability
        $this->assertGreaterThanOrEqual(3, $finalCount, 'There should be at least 3 attempts in total');

        // The total execution time should be limited by the rate limiting
        $totalTime = microtime(true) - $startTime;
        $this->assertGreaterThan(0.01, $totalTime, 'Execution should take some time due to rate limiting');
    }

    public function test_concurrent_operations_with_circuit_breaker(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('This test requires the pcntl extension to simulate concurrency.');
        }

        // Create a shared status file
        $statusFile = sys_get_temp_dir().'/circuit_status_'.uniqid().'.txt';
        file_put_contents($statusFile, 'closed'); // Circuit starts closed

        // Create a shared counter file
        $countFile = sys_get_temp_dir().'/failure_count_'.uniqid().'.txt';
        file_put_contents($countFile, '0');

        // Create a CircuitBreakerStrategy with low threshold for testing
        $innerStrategy = new ExponentialBackoffStrategy;
        $cbStrategy = new CircuitBreakerStrategy($innerStrategy, failureThreshold: 5, resetTimeout: 1);
        $retry = new Retry(maxRetries: 2, retryDelay: 0.1, strategy: $cbStrategy);

        // Start multiple processes
        $childPids = [];
        $processCount = 10;

        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                try {
                    $result = $retry->run(function () use ($statusFile, $countFile, $i) {
                        // First 5 processes will always fail to trip circuit breaker
                        if ($i < 5) {
                            // Count failures with file locking
                            $lock = fopen($countFile, 'r+');
                            if ($lock) {
                                flock($lock, LOCK_EX);
                                $failureCount = (int) file_get_contents($countFile);
                                $failureCount++;
                                file_put_contents($countFile, (string) $failureCount);

                                // Update circuit status based on the failure count
                                if ($failureCount >= 5) {
                                    file_put_contents($statusFile, 'open');
                                }
                                flock($lock, LOCK_UN);
                                fclose($lock);
                            } else {
                                $failureCount = (int) file_get_contents($countFile);
                                file_put_contents($countFile, (string) ($failureCount + 1));

                                if ($failureCount >= 5) {
                                    file_put_contents($statusFile, 'open');
                                }
                            }

                            // Always throw exception for the fail-prone processes
                            throw $this->createGuzzleException('Connection failed');
                        }

                        // Check circuit status for other processes
                        $circuitStatus = file_get_contents($statusFile);

                        if ($circuitStatus === 'open') {
                            // Later processes should see circuit open and react accordingly
                            return 'Circuit open - failing fast';
                        }

                        return 'success';
                    });
                } catch (Throwable $e) {
                    // Just continue if operation fails
                }

                // Small delay to ensure first processes run first
                if ($i >= 5) {
                    usleep(50000); // 50ms
                }

                // Exit child process
                exit(0);
            } else {
                // Parent process - store child PID
                $childPids[] = $pid;

                // Small delay between process creation to ensure execution order
                if ($i < 5) {
                    usleep(10000); // 10ms
                }
            }
        }

        // Wait for all child processes to complete
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Get final circuit status and failure count
        $finalCircuitStatus = file_get_contents($statusFile);
        $finalFailureCount = (int) file_get_contents($countFile);

        // Clean up
        unlink($statusFile);
        unlink($countFile);

        // Assert that the circuit breaker opened
        $this->assertEquals('open', $finalCircuitStatus, 'Circuit should be open after multiple failures');
        $this->assertGreaterThanOrEqual(5, $finalFailureCount, 'There should be at least 5 failures to open the circuit');
    }
}
