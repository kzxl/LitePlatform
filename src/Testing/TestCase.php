<?php

declare(strict_types=1);

namespace App\Testing;

use App\Core\AppBootstrap;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Lightweight Zero-Dependency Test Case for LitePlatform.
 * Dispatches PSR-7 requests directly through the Slim pipeline in-memory.
 */
abstract class TestCase
{
    protected App $app;
    protected Psr17Factory $factory;
    private int $assertionsCount = 0;

    public function setUp(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $this->app = AppBootstrap::boot($baseDir);
        $this->factory = new Psr17Factory();
    }

    public function tearDown(): void
    {
        // Cleanup hooks if needed
    }

    public function getAssertionsCount(): int
    {
        return $this->assertionsCount;
    }

    /**
     * Dispatch in-memory GET request.
     */
    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, headers: $headers);
    }

    /**
     * Dispatch in-memory POST request with JSON payload.
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * Dispatch in-memory PUT request with JSON payload.
     */
    protected function putJson(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    /**
     * Dispatch in-memory DELETE request.
     */
    protected function delete(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, headers: $headers);
    }

    /**
     * Internal request builder and dispatcher.
     */
    protected function request(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, $uri);

        $queryStr = parse_url($uri, PHP_URL_QUERY);
        if ($queryStr) {
            parse_str($queryStr, $queryParams);
            $request = $request->withQueryParams($queryParams);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json = json_encode($body);
            $stream = $this->factory->createStream($json);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    // ─── Assertions ───────────────────────────────────────────────

    protected function assertStatus(ResponseInterface $response, int $expected): void
    {
        $this->assertionsCount++;
        $actual = $response->getStatusCode();
        if ($actual !== $expected) {
            $body = (string)$response->getBody();
            throw new \AssertionError("Expected HTTP status {$expected}, but got {$actual}. Response: {$body}");
        }
    }

    protected function assertJsonOk(ResponseInterface $response): array
    {
        $this->assertionsCount++;
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data) || !($data['success'] ?? false)) {
            throw new \AssertionError("Expected JSON response with success=true. Got: {$body}");
        }
        return $data;
    }

    protected function assertJsonContains(ResponseInterface $response, string $key, mixed $expected): void
    {
        $this->assertionsCount++;
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \AssertionError("Failed asserting valid JSON response. Got: {$body}");
        }

        $val = $this->getNestedKey($data, $key);
        if ($val !== $expected) {
            throw new \AssertionError("Expected key '{$key}' to be " . json_encode($expected) . ", got " . json_encode($val));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Failed asserting that condition is true'): void
    {
        $this->assertionsCount++;
        if (!$condition) {
            throw new \AssertionError($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Failed asserting that condition is false'): void
    {
        $this->assertionsCount++;
        if ($condition) {
            throw new \AssertionError($message);
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertionsCount++;
        if ($expected !== $actual) {
            $msg = $message ?: "Failed asserting that " . json_encode($actual) . " equals expected " . json_encode($expected);
            throw new \AssertionError($msg);
        }
    }

    protected function assertNotNull(mixed $value, string $message = 'Failed asserting that value is not null'): void
    {
        $this->assertionsCount++;
        if ($value === null) {
            throw new \AssertionError($message);
        }
    }

    protected function assertNull(mixed $value, string $message = 'Failed asserting that value is null'): void
    {
        $this->assertionsCount++;
        if ($value !== null) {
            throw new \AssertionError($message);
        }
    }

    private function getNestedKey(array $data, string $key): mixed
    {
        $parts = explode('.', $key);
        $curr = $data;
        foreach ($parts as $part) {
            if (!is_array($curr) || !array_key_exists($part, $curr)) {
                return null;
            }
            $curr = $curr[$part];
        }
        return $curr;
    }
}
