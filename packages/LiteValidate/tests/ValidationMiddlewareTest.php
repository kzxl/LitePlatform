<?php

declare(strict_types=1);

namespace LiteValidate\Tests;

use PHPUnit\Framework\TestCase;
use LiteValidate\Middleware\ValidationMiddleware;
use LiteValidate\Attribute\{Required, Email};
use Nyholm\Psr7\{Response, ServerRequest};
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

class SimpleRequestDto
{
    #[Required]
    public string $username;

    #[Required, Email]
    public string $email;
}

class ValidationMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testFailsValidationWith422Json(): void
    {
        $middleware = new ValidationMiddleware(SimpleRequestDto::class, $this->factory);

        $request = (new ServerRequest('POST', '/api/users'))
            ->withParsedBody(['username' => '', 'email' => 'bad-email']);

        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->called = true;
                return (new Response())->withStatus(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($handler->called);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('VALIDATION_FAILED', $body['error']['type']);
        $this->assertArrayHasKey('username', $body['error']['details']);
        $this->assertArrayHasKey('email', $body['error']['details']);
    }

    public function testPassesValidationAndInjectsDto(): void
    {
        $middleware = new ValidationMiddleware(SimpleRequestDto::class, $this->factory);

        $request = (new ServerRequest('POST', '/api/users'))
            ->withParsedBody(['username' => 'john_doe', 'email' => 'john@example.com']);

        $injectedDto = null;
        $handler = new class(function ($dto) use (&$injectedDto) { $injectedDto = $dto; }) implements RequestHandlerInterface {
            public function __construct(private $callback) {}
            public function handle(ServerRequestInterface $request): ResponseInterface {
                ($this->callback)($request->getAttribute('dto'));
                return (new Response())->withStatus(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(SimpleRequestDto::class, $injectedDto);
        $this->assertSame('john_doe', $injectedDto->username);
        $this->assertSame('john@example.com', $injectedDto->email);
    }
}
