<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

/**
 * Zero-CAPTCHA, zero-JS bot spam defense for public forms (Contact, Quote, Comments).
 * Combines:
 * 1. An off-screen trap field (_hp_website) that human users never fill.
 * 2. An HMAC-signed timestamp (_hp_ts) rejecting automated submissions faster than $minSeconds.
 */
final class HoneypotGuard
{
    public const TRAP_FIELD = '_hp_website';
    public const TIME_FIELD = '_hp_ts';

    public function __construct(
        private readonly string $secret,
        private readonly int $minSeconds = 2,
        private readonly int $maxSeconds = 7200
    ) {
    }

    /**
     * Render hidden honeypot inputs to embed inside any HTML <form>.
     */
    public function renderFields(?int $now = null): string
    {
        $ts = (string)($now ?? time());
        $sig = hash_hmac('sha256', $ts, $this->secret);
        $signed = htmlspecialchars($ts . '.' . $sig, ENT_QUOTES, 'UTF-8');

        return '<div style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden;" aria-hidden="true">'
            . '<label for="' . self::TRAP_FIELD . '">Website</label>'
            . '<input type="text" id="' . self::TRAP_FIELD . '" name="' . self::TRAP_FIELD . '" value="" tabindex="-1" autocomplete="off">'
            . '</div>'
            . '<input type="hidden" name="' . self::TIME_FIELD . '" value="' . $signed . '">';
    }

    /**
     * Verify submitted form payload. Returns true if request looks human, false if spam/bot.
     *
     * @param array<string, mixed> $body
     */
    public function verify(array $body, ?int $now = null): bool
    {
        // 1. Trap field must remain completely empty
        $trap = trim((string)($body[self::TRAP_FIELD] ?? ''));
        if ($trap !== '') {
            return false;
        }

        // 2. Verify signed timestamp integrity and elapsed human time
        $rawTs = (string)($body[self::TIME_FIELD] ?? '');
        $parts = explode('.', $rawTs, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || $parts[1] === '') {
            return false;
        }

        [$tsStr, $sig] = $parts;
        $expected = hash_hmac('sha256', $tsStr, $this->secret);
        if (!hash_equals($expected, $sig)) {
            return false;
        }

        $elapsed = ($now ?? time()) - (int)$tsStr;
        if ($elapsed < $this->minSeconds || $elapsed > $this->maxSeconds) {
            return false;
        }

        return true;
    }
}
