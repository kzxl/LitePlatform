<?php

declare(strict_types=1);

namespace Lite\Framework\Testing;

use Lite\Framework\Core\AppBootstrap;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase as BasePhpUnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Unified PHPUnit Web TestCase for LitePlatform.
 * Dispatches PSR-7 requests directly through the Slim pipeline in-memory.
 */
abstract class TestCase extends BasePhpUnitTestCase
{
    protected App $app;
    protected Psr17Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $baseDir = dirname(__DIR__, 2);
        $this->app = AppBootstrap::boot($baseDir);
        $this->factory = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Dispatch in-memory GET request.
     *
     * @param array<string, string> $headers
     */
    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, headers: $headers);
    }

    /**
     * Dispatch in-memory POST request with JSON payload.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * Dispatch in-memory PUT request with JSON payload.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function putJson(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    /**
     * Dispatch in-memory DELETE request.
     *
     * @param array<string, string> $headers
     */
    protected function delete(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, headers: $headers);
    }

    /**
     * Internal request builder and dispatcher.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
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
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $stream = $this->factory->createStream($json);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream)
                ->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    // ─── HTTP & JSON Assertions ───────────────────────────────────

    protected function assertStatus(ResponseInterface $response, int $expected): void
    {
        $actual = $response->getStatusCode();
        $body = (string)$response->getBody();
        self::assertSame($expected, $actual, "Expected HTTP status {$expected}, got {$actual}. Response: {$body}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertJsonOk(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        self::assertIsArray($data, "Expected JSON response. Got: {$body}");
        self::assertTrue((bool)($data['success'] ?? false), "Expected JSON response with success=true. Got: {$body}");

        return $data;
    }

    protected function assertJsonContains(ResponseInterface $response, string $key, mixed $expected): void
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        self::assertIsArray($data, "Failed asserting valid JSON response. Got: {$body}");

        $val = $this->getNestedKey($data, $key);
        self::assertSame($expected, $val, "Expected key '{$key}' to match.");
    }

    /**
     * @param array<string, mixed> $data
     */
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
