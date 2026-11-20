<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Hardened file upload validator for Shared Hosting environments.
 * Verifies real binary MIME types via finfo, blocks executable/double extensions,
 * enforces size caps, and generates safe randomized filenames.
 */
final class UploadGuard
{
    /** @var array<string, list<string>> */
    public const DEFAULT_IMAGE_MIMES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
    ];

    /** @var list<string> */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'htaccess', 'htpasswd', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
        'js', 'mjs', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'svg',
    ];

    /**
     * @param array<string, list<string>> $allowedExtensionMimes
     */
    public function __construct(
        private readonly array $allowedExtensionMimes = self::DEFAULT_IMAGE_MIMES,
        private readonly int $maxBytes = 5_242_880
    ) {
    }

    /**
     * Validate a local temporary file + client filename and return safe metadata.
     *
     * @return array{extension: string, mime: string, size: int, safe_filename: string}
     */
    public function validate(string $tmpFilePath, string $originalFilename): array
    {
        if (!is_file($tmpFilePath) || !is_readable($tmpFilePath)) {
            throw new InvalidArgumentException('Uploaded temporary file is missing or unreadable.');
        }

        $size = filesize($tmpFilePath);
        if ($size === false || $size <= 0) {
            throw new InvalidArgumentException('Uploaded file is empty.');
        }

        if ($size > $this->maxBytes) {
            throw new InvalidArgumentException(
                sprintf('File size (%d bytes) exceeds maximum allowed limit (%d bytes).', $size, $this->maxBytes)
            );
        }

        $basename = basename($originalFilename);
        $segments = explode('.', strtolower($basename));
        if (count($segments) < 2) {
            throw new InvalidArgumentException('Uploaded file must have a valid extension.');
        }

        // Reject forbidden or double-extension executable tricks (e.g., avatar.php.jpg)
        foreach (array_slice($segments, 1) as $segment) {
            if (in_array($segment, self::FORBIDDEN_EXTENSIONS, true)) {
                throw new InvalidArgumentException("Forbidden file extension detected: .{$segment}");
            }
        }

        $ext = end($segments);
        if (!isset($this->allowedExtensionMimes[$ext])) {
            throw new InvalidArgumentException("File extension .{$ext} is not allowed.");
        }

        $realMime = $this->detectMimeType($tmpFilePath);
        if (!in_array($realMime, $this->allowedExtensionMimes[$ext], true)) {
            throw new InvalidArgumentException(
                "MIME type mismatch: detected '{$realMime}' for extension '.{$ext}'."
            );
        }

        $normalizedExt = $ext === 'jpeg' ? 'jpg' : $ext;
        $safeFilename = bin2hex(random_bytes(16)) . '.' . $normalizedExt;

        return [
            'extension'     => $normalizedExt,
            'mime'          => $realMime,
            'size'          => $size,
            'safe_filename' => $safeFilename,
        ];
    }

    /**
     * Validate and persist an uploaded file into a target directory.
     *
     * @return array{path: string, filename: string, mime: string, size: int}
     */
    public function store(string $tmpFilePath, string $originalFilename, string $targetDir): array
    {
        $meta = $this->validate($tmpFilePath, $originalFilename);

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Unable to create upload directory: {$targetDir}");
        }

        $destination = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $meta['safe_filename'];
        $moved = is_uploaded_file($tmpFilePath)
            ? move_uploaded_file($tmpFilePath, $destination)
            : copy($tmpFilePath, $destination);

        if (!$moved) {
            throw new RuntimeException('Failed to store validated upload.');
        }

        return [
            'path'     => $destination,
            'filename' => $meta['safe_filename'],
            'mime'     => $meta['mime'],
            'size'     => $meta['size'],
        ];
    }

    private function detectMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        if (!is_string($mime) || $mime === '') {
            throw new RuntimeException('Unable to inspect binary MIME type.');
        }

        return $mime;
    }
}
