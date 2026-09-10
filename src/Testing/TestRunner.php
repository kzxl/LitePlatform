<?php

declare(strict_types=1);

namespace App\Testing;

/**
 * Lightweight Zero-Dependency CLI Test Runner.
 */
class TestRunner
{
    public static function run(string $testsDir): int
    {
        if (!is_dir($testsDir)) {
            echo "\033[33mNo tests directory found at {$testsDir}\033[0m\n";
            return 0;
        }

        $testFiles = glob($testsDir . '/*Test.php');
        if (empty($testFiles)) {
            echo "\033[33mNo *Test.php files found in {$testsDir}\033[0m\n";
            return 0;
        }

        echo "\n=======================================================\n";
        echo "  ⚡ LitePlatform In-Memory Test Suite\n";
        echo "=======================================================\n";

        $totalTests = 0;
        $totalAssertions = 0;
        $passed = 0;
        $failed = 0;
        $failures = [];

        $t0 = hrtime(true);
        $mem0 = memory_get_usage();

        foreach ($testFiles as $file) {
            $content = file_get_contents($file);
            $namespace = '';
            if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
                $namespace = trim($m[1]) . '\\';
            }
            if (preg_match('/class\s+(\w+)\s+extends\s+TestCase/', $content, $m)) {
                $testClass = $namespace . $m[1];
            } else {
                continue;
            }

            if (!class_exists($testClass)) {
                require_once $file;
            }

            if (!is_subclass_of($testClass, TestCase::class)) {
                continue;
            }

            $ref = new \ReflectionClass($testClass);
            $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

            echo "\n  \033[1;36m" . $ref->getShortName() . "\033[0m\n";

            foreach ($methods as $method) {
                if (!str_starts_with($method->getName(), 'test')) {
                    continue;
                }

                $totalTests++;
                /** @var TestCase $instance */
                $instance = new $testClass();
                $instance->setUp();

                $methodStart = hrtime(true);
                try {
                    $instance->{$method->getName()}();
                    $instance->tearDown();
                    $elapsedMs = round((hrtime(true) - $methodStart) / 1e6, 2);
                    $totalAssertions += $instance->getAssertionsCount();
                    $passed++;

                    echo "    \033[32m✔\033[0m {$method->getName()} \033[90m({$elapsedMs} ms)\033[0m\n";
                } catch (\Throwable $e) {
                    $instance->tearDown();
                    $failed++;
                    $failures[] = [
                        'class'   => $ref->getShortName(),
                        'method'  => $method->getName(),
                        'message' => $e->getMessage(),
                        'file'    => $e->getFile(),
                        'line'    => $e->getLine(),
                    ];
                    echo "    \033[31m✘\033[0m {$method->getName()} \033[31m[FAILED]\033[0m\n";
                }
            }
        }

        $totalTimeMs = round((hrtime(true) - $t0) / 1e6, 2);
        $peakMemMb = round(memory_get_peak_usage() / 1024 / 1024, 2);

        echo "\n-------------------------------------------------------\n";
        if ($failed === 0) {
            echo "  \033[1;32mTEST SUITE PASSED!\033[0m ({$passed} passed, {$totalAssertions} assertions)\n";
        } else {
            echo "  \033[1;31mTEST SUITE FAILED!\033[0m ({$failed} failed, {$passed} passed)\n\n";
            echo "  \033[31mFailures details:\033[0m\n";
            foreach ($failures as $f) {
                echo "  - \033[1m{$f['class']}::{$f['method']}\033[0m\n";
                echo "    {$f['message']}\n";
                echo "    \033[90mat {$f['file']}:{$f['line']}\033[0m\n\n";
            }
        }
        echo "  Time: {$totalTimeMs} ms | Peak Memory: {$peakMemMb} MB\n";
        echo "=======================================================\n\n";

        return $failed === 0 ? 0 : 1;
    }
}
