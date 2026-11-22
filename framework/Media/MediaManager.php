<?php

declare(strict_types=1);

namespace Lite\Framework\Media;

use Lite\Framework\Security\UploadGuard;
use PDO;

/**
 * End-to-end Media Asset Manager combining UploadGuard + ImageOptimizer (WebP/CLS)
 * with a queryable PDO ledger (`media_assets`).
 */
final class MediaManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $publicUploadsDir,
        private readonly string $publicUrlPrefix = '/uploads',
        private readonly UploadGuard $uploadGuard = new UploadGuard(),
        private readonly ImageOptimizer $optimizer = new ImageOptimizer()
    ) {
    }

    /**
     * Validate, optimize (resize + WebP if GD enabled), store under YYYY/MM/, and record in DB.
     *
     * @return array{id: int, filename: string, original_name: string, relative_url: string, mime_type: string, width: int, height: int, bytes: int, alt_text: string, img_tag: string}
     */
    public function upload(
        string $tmpFilePath,
        string $originalFilename,
        string $altText = '',
        int $maxWidth = 1280,
        int $quality = 82
    ): array {
        // 1. Validate MIME, extension, size, and double-extension attacks
        $meta = $this->uploadGuard->validate($tmpFilePath, $originalFilename);

        $subDir = date('Y') . '/' . date('m');
        $targetDir = rtrim($this->publicUploadsDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subDir);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $stem = pathinfo($meta['safe_filename'], PATHINFO_FILENAME);
        $destWithoutExt = $targetDir . DIRECTORY_SEPARATOR . $stem;

        // 2. Optimize & extract explicit width/height for Core Web Vitals (CLS)
        $optimized = $this->optimizer->optimize($tmpFilePath, $destWithoutExt, $maxWidth, $quality);

        $storedFilename = basename($optimized['path']);
        $relativeUrl = rtrim($this->publicUrlPrefix, '/') . '/' . $subDir . '/' . $storedFilename;

        // 3. Persist ledger row (sanitize original_name to strip HTML/XSS characters)
        $cleanOriginalName = trim(preg_replace('/[<>"\'&`\\\\]/', '', strip_tags(basename($originalFilename))) ?? '') ?: $storedFilename;
        $stmt = $this->pdo->prepare(
            'INSERT INTO media_assets (filename, original_name, relative_url, mime_type, width, height, bytes, alt_text, created_at)
             VALUES (:filename, :original_name, :relative_url, :mime_type, :width, :height, :bytes, :alt_text, :created_at)'
        );
        $stmt->execute([
            ':filename'      => $storedFilename,
            ':original_name' => mb_substr($cleanOriginalName, 0, 200),
            ':relative_url'  => $relativeUrl,
            ':mime_type'     => $optimized['mime'],
            ':width'         => $optimized['width'],
            ':height'        => $optimized['height'],
            ':bytes'         => $optimized['bytes'],
            ':alt_text'      => $altText,
            ':created_at'    => date('Y-m-d H:i:s'),
        ]);

        $id = (int)$this->pdo->lastInsertId();

        return [
            'id'            => $id,
            'filename'      => $storedFilename,
            'original_name' => mb_substr($cleanOriginalName, 0, 200),
            'relative_url'  => $relativeUrl,
            'mime_type'     => $optimized['mime'],
            'width'         => $optimized['width'],
            'height'        => $optimized['height'],
            'bytes'         => $optimized['bytes'],
            'alt_text'      => $altText,
            'img_tag'       => ImageOptimizer::renderImgTag($relativeUrl, $altText, $optimized['width'], $optimized['height']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media_assets ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT relative_url FROM media_assets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return false;
        }

        $relativeUrl = (string)$row['relative_url'];
        $prefix = rtrim($this->publicUrlPrefix, '/') . '/';
        if (str_starts_with($relativeUrl, $prefix)) {
            $relPath = substr($relativeUrl, strlen($prefix));
            $baseReal = realpath($this->publicUploadsDir);
            $fullPath = $baseReal !== false
                ? realpath($baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath))
                : false;

            // Path traversal guard: only delete files strictly inside publicUploadsDir
            if ($baseReal !== false && $fullPath !== false && str_starts_with($fullPath, $baseReal . DIRECTORY_SEPARATOR)) {
                @unlink($fullPath);
            }
        }

        $del = $this->pdo->prepare('DELETE FROM media_assets WHERE id = :id');
        return $del->execute([':id' => $id]);
    }
}
