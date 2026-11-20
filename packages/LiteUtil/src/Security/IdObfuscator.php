<?php

declare(strict_types=1);

namespace LiteUtil\Security;

/**
 * High-performance, zero-dependency numeric ID obfuscator.
 * Converts sequential auto-increment database IDs into short, URL-safe, non-sequential strings.
 * Uses Knuth's multiplicative permutation with dynamic alphabet shuffling.
 * E.g.: 1001 => '3b45BV'
 */
class IdObfuscator
{
    private const DEFAULT_ALPHABET = '23456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    private const PRIME = 1580030173;
    private const INVERSE = 59260789;
    private const MASK = 0x7fffffff;

    private string $alphabet;
    private int $base;
    private int $minLength;
    /** @var array<string, int> */
    private array $lookup;

    public function __construct(
        string $salt = 'liteplatform-salt',
        int $minLength = 6,
        ?string $alphabet = null,
    ) {
        $this->minLength = max(0, $minLength);
        $rawAlphabet = $alphabet ?? self::DEFAULT_ALPHABET;
        $this->alphabet = self::shuffleAlphabet($rawAlphabet, $salt);
        $this->base = strlen($this->alphabet);
        $this->lookup = array_flip(str_split($this->alphabet));
    }

    /**
     * Encode a positive integer into an obfuscated string.
     */
    public function encode(int $id): string
    {
        if ($id < 0) {
            throw new \InvalidArgumentException('Only non-negative integers can be obfuscated.');
        }

        if ($id === 0) {
            $s = $this->alphabet[0];
            while (strlen($s) < $this->minLength) {
                $s = $this->alphabet[0] . $s;
            }
            return $s;
        }

        // Permute integer
        $num = ($id * self::PRIME) & self::MASK;

        // Base conversion
        $s = '';
        while ($num > 0) {
            $rem = $num % $this->base;
            $s = $this->alphabet[$rem] . $s;
            $num = intdiv($num, $this->base);
        }

        // Left-pad with alphabet[0] to meet minLength
        while (strlen($s) < $this->minLength) {
            $s = $this->alphabet[0] . $s;
        }

        return $s;
    }

    /**
     * Decode an obfuscated string back into the original integer.
     *
     * @return int|null Returns the decoded integer, or null if invalid.
     */
    public function decode(string $hash): ?int
    {
        if ($hash === '') {
            return null;
        }

        $len = strlen($hash);
        $num = 0;

        for ($i = 0; $i < $len; $i++) {
            $c = $hash[$i];
            if (!isset($this->lookup[$c])) {
                return null; // Invalid character
            }
            $num = $num * $this->base + $this->lookup[$c];
        }

        if ($num === 0) {
            return 0;
        }

        // Inverse permutation
        return ($num * self::INVERSE) & self::MASK;
    }

    /**
     * Convenience static helper for quick encoding.
     */
    public static function quickEncode(int $id, string $salt = 'liteplatform-salt', int $minLength = 6): string
    {
        return (new self($salt, $minLength))->encode($id);
    }

    /**
     * Convenience static helper for quick decoding.
     */
    public static function quickDecode(string $hash, string $salt = 'liteplatform-salt', int $minLength = 6): ?int
    {
        return (new self($salt, $minLength))->decode($hash);
    }

    private static function shuffleAlphabet(string $alphabet, string $salt): string
    {
        $chars = array_values(array_unique(str_split($alphabet)));
        $len = count($chars);
        if ($len === 0) return self::DEFAULT_ALPHABET;

        $saltHash = hash('sha256', $salt);
        $saltLen = strlen($saltHash);

        for ($i = $len - 1, $v = 0, $p = 0; $i > 0; $i--, $v++) {
            $v %= $saltLen;
            $p += ord($saltHash[$v]);
            $j = ($p + $v) % $i;

            $temp = $chars[$j];
            $chars[$j] = $chars[$i];
            $chars[$i] = $temp;
        }

        return implode('', $chars);
    }
}
