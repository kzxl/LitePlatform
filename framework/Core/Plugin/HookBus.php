<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use Throwable;

/**
 * Fault-isolated Action & Filter Hook Bus for clean Core <-> Plugin and Plugin <-> Plugin integration.
 * A failing plugin listener is caught and reported without crashing the host request.
 */
final class HookBus
{
    /** @var array<string, list<array{priority: int, moduleId: string, callback: callable}>> */
    private array $actions = [];

    /** @var array<string, list<array{priority: int, moduleId: string, callback: callable}>> */
    private array $filters = [];

    /** @var (callable(string, string, Throwable): void)|null */
    private $errorReporter = null;

    /** @var list<array{hook: string, module_id: string, error: string}> */
    private array $errors = [];

    /**
     * @param callable(string, string, Throwable): void $reporter
     */
    public function setErrorReporter(callable $reporter): void
    {
        $this->errorReporter = $reporter;
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, string $moduleId = 'core'): void
    {
        $this->actions[$hook][] = [
            'priority' => $priority,
            'moduleId' => $moduleId,
            'callback' => $callback,
        ];
        usort($this->actions[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, string $moduleId = 'core'): void
    {
        $this->filters[$hook][] = [
            'priority' => $priority,
            'moduleId' => $moduleId,
            'callback' => $callback,
        ];
        usort($this->filters[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->actions[$hook] ?? [] as $listener) {
            try {
                ($listener['callback'])(...$args);
            } catch (Throwable $e) {
                $this->recordFailure($hook, $listener['moduleId'], $e);
            }
        }
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $current = $value;
        foreach ($this->filters[$hook] ?? [] as $listener) {
            try {
                $current = ($listener['callback'])($current, ...$args);
            } catch (Throwable $e) {
                $this->recordFailure($hook, $listener['moduleId'], $e);
            }
        }
        return $current;
    }

    /**
     * @return list<array{hook: string, module_id: string, error: string}>
     */
    public function getRecordedErrors(): array
    {
        return $this->errors;
    }

    private function recordFailure(string $hook, string $moduleId, Throwable $e): void
    {
        $this->errors[] = [
            'hook'      => $hook,
            'module_id' => $moduleId,
            'error'     => $e->getMessage(),
        ];
        if ($this->errorReporter !== null) {
            ($this->errorReporter)($hook, $moduleId, $e);
        }
    }
}
