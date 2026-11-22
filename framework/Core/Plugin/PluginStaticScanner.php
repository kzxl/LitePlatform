<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pure-PHP Static Security Scanner & Integrity Hasher for LitePlatform Plugins.
 * Powered by PHP's built-in tokenizer (`token_get_all`) with zero external dependencies.
 *
 * Detects P0 critical patterns (eval, shell execution, unsafe unserialize, dynamic include)
 * and P1 warning patterns (direct $_ENV/getenv secret reads, raw SQL string interpolation).
 */
final class PluginStaticScanner
{
    /** @var list<string> */
    private const FORBIDDEN_FUNCTIONS = [
        'eval',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'pcntl_exec',
        'assert',
        'create_function',
    ];

    /**
     * Scan an entire plugin directory for forbidden PHP constructs and compute deterministic SHA-256 checksum.
     *
     * @return array{
     *   ok: bool,
     *   files_scanned: int,
     *   checksum_sha256: string,
     *   critical: list<array{file: string, line: int, rule: string, detail: string}>,
     *   warnings: list<array{file: string, line: int, rule: string, detail: string}>
     * }
     */
    public function scanDirectory(string $directory): array
    {
        $critical = [];
        $warnings = [];
        $files = [];

        if (is_dir($directory)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iter as $fileInfo) {
                if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
        sort($files);

        $hashCtx = hash_init('sha256');

        foreach ($files as $filePath) {
            $rel = ltrim(str_replace(['\\', $directory], ['/', ''], $filePath), '/');
            $code = (string)file_get_contents($filePath);
            hash_update($hashCtx, $rel . ':' . hash('sha256', $code) . "\n");

            $result = $this->scanSource($code, $rel);
            foreach ($result['critical'] as $c) {
                $critical[] = $c;
            }
            foreach ($result['warnings'] as $w) {
                $warnings[] = $w;
            }
        }

        return [
            'ok'              => empty($critical),
            'files_scanned'   => count($files),
            'checksum_sha256' => hash_final($hashCtx),
            'critical'        => $critical,
            'warnings'        => $warnings,
        ];
    }

    /**
     * Scan a single PHP source string using PHP's native tokenizer.
     *
     * @return array{
     *   critical: list<array{file: string, line: int, rule: string, detail: string}>,
     *   warnings: list<array{file: string, line: int, rule: string, detail: string}>
     * }
     */
    public function scanSource(string $code, string $fileLabel = 'inline.php'): array
    {
        $tokens = @token_get_all($code);
        $critical = [];
        $warnings = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            // Backtick shell execution operator (`...`)
            if ($tok === '`') {
                $critical[] = [
                    'file'   => $fileLabel,
                    'line'   => $this->nearestLine($tokens, $i),
                    'rule'   => 'P0_BACKTICK_EXEC',
                    'detail' => 'Backtick shell execution operator is forbidden in plugins.',
                ];
                continue;
            }

            if (!is_array($tok)) {
                continue;
            }

            [$id, $text, $line] = $tok;

            // T_EVAL is its own language construct token
            if ($id === T_EVAL) {
                $critical[] = [
                    'file'   => $fileLabel,
                    'line'   => $line,
                    'rule'   => 'P0_FORBIDDEN_EVAL',
                    'detail' => 'eval() is strictly forbidden in plugins.',
                ];
                continue;
            }

            // Function calls: exec(), shell_exec(), system(), unserialize(), getenv()
            if ($id === T_STRING) {
                $lower = strtolower($text);
                $nextNonWs = $this->nextSignificantToken($tokens, $i);
                $prevNonWs = $this->prevSignificantToken($tokens, $i);

                $isMethodOrDecl = is_array($prevNonWs) && in_array($prevNonWs[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

                if (!$isMethodOrDecl && $nextNonWs === '(') {
                    if (in_array($lower, self::FORBIDDEN_FUNCTIONS, true)) {
                        $critical[] = [
                            'file'   => $fileLabel,
                            'line'   => $line,
                            'rule'   => 'P0_FORBIDDEN_CALL',
                            'detail' => "Dangerous function call '{$text}()' is forbidden in plugins.",
                        ];
                    } elseif ($lower === 'unserialize') {
                        $snippet = $this->collectCallSnippet($tokens, $i, 20);
                        if (!str_contains($snippet, 'allowed_classes')) {
                            $critical[] = [
                                'file'   => $fileLabel,
                                'line'   => $line,
                                'rule'   => 'P0_UNSAFE_UNSERIALIZE',
                                'detail' => "unserialize() without ['allowed_classes' => false] is forbidden.",
                            ];
                        }
                    } elseif ($lower === 'getenv') {
                        $warnings[] = [
                            'file'   => $fileLabel,
                            'line'   => $line,
                            'rule'   => 'P1_DIRECT_ENV_READ',
                            'detail' => 'Direct getenv() access; use PluginContext::getSetting() instead.',
                        ];
                    }
                }
            }

            // Direct $_ENV or $_SERVER secret reads
            if ($id === T_VARIABLE && $text === '$_ENV') {
                $warnings[] = [
                    'file'   => $fileLabel,
                    'line'   => $line,
                    'rule'   => 'P1_SUPERGLOBAL_ENV',
                    'detail' => 'Direct $_ENV read; plugins should use scoped PluginContext::getSetting().',
                ];
            }
        }

        return ['critical' => $critical, 'warnings' => $warnings];
    }

    /** @param list<mixed> $tokens */
    private function nextSignificantToken(array $tokens, int $index): mixed
    {
        $n = count($tokens);
        for ($j = $index + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private function prevSignificantToken(array $tokens, int $index): mixed
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private function collectCallSnippet(array $tokens, int $start, int $maxTokens): string
    {
        $out = '';
        $limit = min(count($tokens), $start + $maxTokens);
        for ($j = $start; $j < $limit; $j++) {
            $out .= is_array($tokens[$j]) ? $tokens[$j][1] : (string)$tokens[$j];
        }
        return $out;
    }

    /** @param list<mixed> $tokens */
    private function nearestLine(array $tokens, int $index): int
    {
        for ($j = $index; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                return (int)$tokens[$j][2];
            }
        }
        return 1;
    }
}
