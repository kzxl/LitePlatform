<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use RuntimeException;

/**
 * Outbound HTTP SSRF (Server-Side Request Forgery) Guard for plugins and webhooks.
 * Blocks non-HTTP(S) schemes, credentials in URLs, loopback, RFC1918 private networks,
 * cloud metadata endpoints (169.254.169.254), and IPv6 private/link-local ranges.
 */
final class SsrfGuard
{
    /** @var callable(string): list<string> */
    private $dnsResolver;

    /**
     * @param (callable(string): list<string>)|null $dnsResolver Optional resolver for deterministic testing
     */
    public function __construct(?callable $dnsResolver = null)
    {
        $this->dnsResolver = $dnsResolver ?? static function (string $host): array {
            $ips = [];
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
            if (function_exists('dns_get_record')) {
                $v6 = @dns_get_record($host, DNS_AAAA);
                if (is_array($v6)) {
                    foreach ($v6 as $rec) {
                        if (!empty($rec['ipv6'])) {
                            $ips[] = (string)$rec['ipv6'];
                        }
                    }
                }
            }
            return array_values(array_unique($ips));
        };
    }

    /**
     * Validate that a target URL resolves exclusively to public, routable IP addresses.
     */
    public function assertUrlSafe(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('SSRF blocked: malformed URL.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("SSRF blocked: unsupported URL scheme '{$scheme}'.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('SSRF blocked: embedded credentials in URL are forbidden.');
        }

        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new RuntimeException("SSRF blocked: reserved hostname '{$host}'.");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);
            return;
        }

        $resolved = ($this->dnsResolver)($host);
        if (empty($resolved)) {
            throw new RuntimeException("SSRF blocked: unable to resolve hostname '{$host}'.");
        }

        foreach ($resolved as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    public function isUrlSafe(string $url): bool
    {
        try {
            $this->assertUrlSafe($url);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertPublicIp(string $ip): void
    {
        $validPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($validPublic === false) {
            throw new RuntimeException("SSRF blocked: target IP '{$ip}' is private, loopback, or reserved.");
        }
    }
}
