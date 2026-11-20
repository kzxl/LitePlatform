<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves real client IP and HTTPS scheme behind Cloudflare / reverse proxies.
 * Strictly ignores spoofed X-Forwarded-* / CF-Connecting-IP headers unless REMOTE_ADDR
 * matches the configured trusted proxy allowlist (exact IP, CIDR, or '*' opt-in).
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(
        private readonly array $trustedProxies = []
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remoteAddr = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1');
        $clientIp = $remoteAddr;

        if ($this->isTrustedProxy($remoteAddr)) {
            $cfIp = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                $clientIp = $cfIp;
            } else {
                $forwardedFor = trim($request->getHeaderLine('X-Forwarded-For'));
                if ($forwardedFor !== '') {
                    $first = trim(explode(',', $forwardedFor)[0]);
                    if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                        $clientIp = $first;
                    }
                }
            }

            $forwardedProto = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
            if ($forwardedProto === 'https' && $request->getUri()->getScheme() !== 'https') {
                $request = $request->withUri($request->getUri()->withScheme('https'));
            }
        }

        $request = $request->withAttribute('client_ip', $clientIp);

        return $handler->handle($request);
    }

    private function isTrustedProxy(string $ip): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }

        foreach ($this->trustedProxies as $proxy) {
            if ($proxy === '*' || $proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && $this->ipInCidr($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsStr] = explode('/', $cidr, 2);
        if (!ctype_digit($bitsStr)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $bits = (int)$bitsStr;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            return (ord($ipBin[$fullBytes]) & $mask) === (ord($subBin[$fullBytes]) & $mask);
        }

        return true;
    }
}
