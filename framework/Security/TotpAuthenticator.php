<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

/**
 * Zero-dependency RFC 6238 TOTP (Time-based One-Time Password) 2FA authenticator.
 * Compatible with Google Authenticator, 1Password, Bitwarden, and Authy.
 */
final class TotpAuthenticator
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes($length);
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    public function getCode(string $base32Secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        $secretKey = self::base32Decode($base32Secret);
        $timeSlice = (int)floor(($timestamp ?? time()) / $period);
        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice);

        $hmac = hash_hmac('sha1', $timeBytes, $secretKey, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** $digits);
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code with configurable clock-skew window (default ±1 step = ±30s).
     */
    public function verify(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null, int $period = 30): bool
    {
        $clean = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (!preg_match('/^\d{6}$/', $clean)) {
            return false;
        }

        $now = $timestamp ?? time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = $this->getCode($base32Secret, $now + ($offset * $period), $period);
            if (hash_equals($candidate, $clean)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $issuer, string $account, string $base32Secret): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=6&period=30',
            $label,
            rawurlencode($base32Secret),
            rawurlencode($issuer)
        );
    }

    private static function base32Decode(string $input): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos(self::BASE32_ALPHABET, $clean[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
