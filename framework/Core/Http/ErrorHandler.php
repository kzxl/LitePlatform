<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Http;

use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Unified SEO-safe HTML & RFC 7807 JSON Error Handler.
 * - Logs 5xx errors into daily rotating FileLogger without exposing stack traces in production.
 * - Renders branded HTML error pages (templates/errors/404.lite.php, 500.lite.php) for browsers.
 * - Returns application/problem+json for /api/* or JSON-accepting clients.
 */
final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ?ViewEngine $view = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $appName = 'LitePlatform'
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        if ($logErrors && $this->logger !== null && $status >= 500) {
            $this->logger->error($exception->getMessage(), [
                'status'    => $status,
                'method'    => $request->getMethod(),
                'path'      => $request->getUri()->getPath(),
                'client_ip' => $request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'),
                'exception' => $logErrorDetails ? $exception : $exception::class,
            ]);
        }

        if ($this->wantsJson($request)) {
            return $this->renderJson($status, $exception, $displayErrorDetails);
        }

        return $this->renderHtml($status, $exception, $displayErrorDetails);
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function renderJson(int $status, Throwable $exception, bool $displayErrorDetails): ResponseInterface
    {
        $payload = [
            'status' => $status,
            'title'  => $this->defaultTitle($status),
            'detail' => $displayErrorDetails ? $exception->getMessage() : $this->safeDetail($status),
        ];

        if ($displayErrorDetails) {
            $payload['exception'] = $exception::class;
            $payload['file'] = $exception->getFile() . ':' . $exception->getLine();
        }

        $response = new Response($status);
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
    }

    private function renderHtml(int $status, Throwable $exception, bool $displayErrorDetails): ResponseInterface
    {
        $template = $status === 404 ? 'errors.404' : 'errors.500';
        $data = [
            'status'      => $status,
            'title'       => $this->defaultTitle($status),
            'message'     => $displayErrorDetails ? $exception->getMessage() : $this->safeDetail($status),
            'debugDetail' => $displayErrorDetails
                ? ($exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine())
                : null,
            'appName'     => $this->appName,
        ];

        $html = null;
        if ($this->view !== null) {
            try {
                $html = $this->view->render($template, $data);
            } catch (Throwable) {
                $html = null;
            }
        }

        if ($html === null) {
            $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = sprintf(
                '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex, nofollow"><title>%d %s</title></head><body><h1>%d — %s</h1><p>%s</p><p><a href="/">Back to Home</a></p></body></html>',
                $status,
                $e($data['title']),
                $status,
                $e($data['title']),
                $e($data['message'])
            );
        }

        $response = new Response($status);
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function defaultTitle(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            default => 'Internal Server Error',
        };
    }

    private function safeDetail(int $status): string
    {
        return match ($status) {
            404 => 'The requested page could not be found.',
            403 => 'You do not have permission to access this resource.',
            405 => 'The requested HTTP method is not allowed for this route.',
            default => 'An unexpected server error occurred. Please try again later.',
        };
    }
}
