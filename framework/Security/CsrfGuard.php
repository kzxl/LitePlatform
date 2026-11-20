<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stateless Signed Double-Submit CSRF Guard for Shared Hosting (no PHP session lock required).
 * - Automatically binds a valid token into LiteView\ViewEngine::setCsrfToken() for @csrf.
 * - Enforces token verification on state-changing web requests (POST/PUT/PATCH/DELETE).
 * - Skips exempt path prefixes (default: /api/).
 */
final class CsrfGuard implements MiddlewareInterface
{
    public const COOKIE_NAME = 'lite_csrf';
    public const FIELD_NAME = '_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';

    /**
     * @param list<string> $exemptPrefixes
     */
    public function __construct(
        private readonly string $secret,
        private readonly bool $enabled = true,
        private readonly array $exemptPrefixes = ['/api/'],
        private readonly bool $secureCookie = false,
        private readonly int $ttlSeconds = 14400
    ) {
    }

    public function generateToken(?int $now = null): string
    {
        $nonce = bin2hex(random_bytes(16));
        $expiresAt = ($now ?? time()) + $this->ttlSeconds;
        $payload = $nonce . '.' . $expiresAt;
        $sig = hash_hmac('sha256', $payload, $this->secret);

        return $payload . '.' . $sig;
    }

    public function isValidToken(string $token, ?int $now = null): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$nonce, $expiresStr, $sig] = $parts;
        if ($nonce === '' || !ctype_digit($expiresStr) || $sig === '') {
            return false;
        }

        if ((int)$expiresStr < ($now ?? time())) {
            return false;
        }

        $expected = hash_hmac('sha256', $nonce . '.' . $expiresStr, $this->secret);

        return hash_equals($expected, $sig);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $cookieToken = isset($cookies[self::COOKIE_NAME]) ? (string)$cookies[self::COOKIE_NAME] : '';
        $needsNewCookie = false;

        if ($cookieToken === '' || !$this->isValidToken($cookieToken)) {
            $cookieToken = $this->generateToken();
            $needsNewCookie = true;
        }

        if (class_exists(\LiteView\ViewEngine::class)) {
            \LiteView\ViewEngine::setCsrfToken($cookieToken);
        }

        $request = $request->withAttribute('csrf_token', $cookieToken);

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if ($this->enabled && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->isExempt($path)) {
            $submitted = $this->extractSubmittedToken($request);
            $rawCookie = isset($cookies[self::COOKIE_NAME]) ? (string)$cookies[self::COOKIE_NAME] : '';

            if (
                $submitted === ''
                || $rawCookie === ''
                || !hash_equals($rawCookie, $submitted)
                || !$this->isValidToken($submitted)
            ) {
                $response = new Response(403);
                $response->getBody()->write(json_encode([
                    'status' => 403,
                    'title'  => 'CSRF Token Mismatch',
                    'detail' => 'Invalid or missing CSRF verification token.',
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/problem+json');
            }
        }

        $response = $handler->handle($request);

        if ($needsNewCookie) {
            $cookieHeader = sprintf(
                '%s=%s; Path=/; HttpOnly; SameSite=Lax%s',
                self::COOKIE_NAME,
                $cookieToken,
                $this->secureCookie ? '; Secure' : ''
            );
            $response = $response->withAddedHeader('Set-Cookie', $cookieHeader);
        }

        return $response;
    }

    private function extractSubmittedToken(ServerRequestInterface $request): string
    {
        $headerToken = trim($request->getHeaderLine(self::HEADER_NAME));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[self::FIELD_NAME])) {
            return trim((string)$body[self::FIELD_NAME]);
        }

        return '';
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->exemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
