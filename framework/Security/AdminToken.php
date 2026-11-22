<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use RuntimeException;

/**
 * Stateless HMAC-SHA256 signed authentication token with mandatory expiration (TTL).
 * Format: base64url(username|expiresAt).hmacHex
 */
final class AdminToken
{
    public const DEFAULT_INSECURE_SECRET = 'lite-admin-default-secret-key-32!';

    public static function assertSecretSafe(string $secret, string $env): void
    {
        if ($env === 'production' && (strlen($secret) < 32 || $secret === self::DEFAULT_INSECURE_SECRET)) {
            throw new RuntimeException(
                'Insecure admin auth secret in production. Set APP_KEY or CSRF_SECRET (>= 32 chars) in .env.'
            );
        }
    }

    public static function issue(
        string $username,
        string $secret,
        int $ttlSeconds = 28800,
        ?int $now = null,
        int $tokenVersion = 1
    ): string {
        $issuedAt = $now ?? time();
        $expiresAt = $issuedAt + max(60, $ttlSeconds);
        $payload = self::base64UrlEncode($username . '|' . $expiresAt . '|v' . max(1, $tokenVersion));
        $sig = hash_hmac('sha256', $payload, $secret);

        return $payload . '.' . $sig;
    }

    /**
     * Verify token signature and expiration. Returns structured payload or null.
     * Supports both legacy `username|expiresAt` and versioned `username|expiresAt|vN` tokens.
     *
     * @return array{username: string, expires_at: int, token_version: int}|null
     */
    public static function verifyPayload(string $token, string $secret, ?int $now = null): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = self::base64UrlDecode($payload);
        if ($decoded === null) {
            return null;
        }

        $segments = explode('|', $decoded);
        if (count($segments) < 2) {
            return null;
        }

        $tokenVersion = 1;
        $last = end($segments);
        if (is_string($last) && str_starts_with($last, 'v') && ctype_digit(substr($last, 1))) {
            $tokenVersion = (int)substr($last, 1);
            array_pop($segments);
        }

        $expiresStr = array_pop($segments);
        $username = implode('|', $segments);
        if ($username === '' || !is_string($expiresStr) || !ctype_digit($expiresStr)) {
            return null;
        }

        $expiresAt = (int)$expiresStr;
        if ($expiresAt < ($now ?? time())) {
            return null;
        }

        return [
            'username'      => $username,
            'expires_at'    => $expiresAt,
            'token_version' => $tokenVersion,
        ];
    }

    /**
     * Verify token signature and expiration. Returns username on success, null otherwise.
     */
    public static function verify(string $token, string $secret, ?int $now = null): ?string
    {
        $verified = self::verifyPayload($token, $secret, $now);
        return $verified !== null ? $verified['username'] : null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
