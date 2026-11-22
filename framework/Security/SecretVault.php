<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use RuntimeException;

/**
 * Authenticated Encryption Secret Vault for DB-persisted credentials and plugin secrets.
 *
 * Uses PHP 8.2 native libsodium XChaCha20-Poly1305 AEAD (or OpenSSL AES-256-GCM fallback)
 * with HKDF-SHA256 derived subkeys and scope-bound Associated Data (AD) so ciphertexts
 * cannot be transplanted across plugins or setting keys.
 */
final class SecretVault
{
    private const ENVELOPE_PREFIX = 'v1:';
    private readonly string $key;

    public function __construct(
        string $masterSecret,
        private readonly string $keyId = 'k1'
    ) {
        if (strlen($masterSecret) < 16) {
            throw new RuntimeException('SecretVault master secret must be at least 16 bytes.');
        }

        $this->key = hash_hkdf('sha256', $masterSecret, 32, 'liteplatform-secret-vault-v1:' . $this->keyId);
    }

    /**
     * Encrypt plaintext bound to a specific scope (e.g. plugin ID) and secret name.
     */
    public function encrypt(string $plaintext, string $scope, string $secretKey): string
    {
        $ad = $this->buildAssociatedData($scope, $secretKey);

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $ad, $nonce, $this->key);
            return self::ENVELOPE_PREFIX . 'xchacha:' . $this->keyId . ':' . base64_encode($nonce . $cipher);
        }

        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $ad);
        if ($cipher === false) {
            throw new RuntimeException('SecretVault encryption failed.');
        }

        return self::ENVELOPE_PREFIX . 'aesgcm:' . $this->keyId . ':' . base64_encode($nonce . $tag . $cipher);
    }

    /**
     * Decrypt an envelope and verify integrity + Associated Data binding.
     * Throws RuntimeException on any tampering or scope mismatch.
     */
    public function decrypt(string $envelope, string $scope, string $secretKey): string
    {
        if (!str_starts_with($envelope, self::ENVELOPE_PREFIX)) {
            throw new RuntimeException('Invalid SecretVault ciphertext envelope.');
        }

        $parts = explode(':', $envelope, 4);
        if (count($parts) !== 4) {
            throw new RuntimeException('Malformed SecretVault ciphertext envelope.');
        }

        [, $alg, , $b64] = $parts;
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new RuntimeException('Corrupted SecretVault base64 payload.');
        }

        $ad = $this->buildAssociatedData($scope, $secretKey);

        if ($alg === 'xchacha') {
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                throw new RuntimeException('libsodium extension is required to decrypt xchacha envelopes.');
            }
            $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) <= $nonceLen) {
                throw new RuntimeException('Truncated SecretVault payload.');
            }
            $nonce = substr($raw, 0, $nonceLen);
            $cipher = substr($raw, $nonceLen);
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $ad, $nonce, $this->key);
            if ($plain === false) {
                throw new RuntimeException('SecretVault authentication tag verification failed (tampered or wrong scope).');
            }
            return $plain;
        }

        if ($alg === 'aesgcm') {
            if (strlen($raw) <= 28) {
                throw new RuntimeException('Truncated AES-GCM payload.');
            }
            $nonce = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $ad);
            if ($plain === false) {
                throw new RuntimeException('SecretVault AES-GCM authentication failed.');
            }
            return $plain;
        }

        throw new RuntimeException("Unsupported SecretVault cipher algorithm: {$alg}");
    }

    public function isEncryptedEnvelope(string $value): bool
    {
        return str_starts_with($value, self::ENVELOPE_PREFIX);
    }

    private function buildAssociatedData(string $scope, string $secretKey): string
    {
        return strtolower(trim($scope)) . '::' . trim($secretKey);
    }
}
