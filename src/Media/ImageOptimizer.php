<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure PHP GD image optimizer for Shared Hosting SEO (Core Web Vitals: LCP & CLS).
 * Resizes images proportionally, converts to WebP when GD WebP is available,
 * and returns explicit width/height attributes to prevent Cumulative Layout Shift.
 */
final class ImageOptimizer
{
    public static function isGdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public static function isWebpSupported(): bool
    {
        return self::isGdAvailable() && function_exists('imagewebp');
    }

    /**
     * Optimize a local image file: resize to maxWidth and convert to WebP if supported.
     * Falls back cleanly to copying the source image when GD is unavailable.
     *
     * @return array{path: string, width: int, height: int, mime: string, bytes: int, webp: bool}
     */
    public function optimize(
        string $sourcePath,
        string $destinationPathWithoutExt,
        int $maxWidth = 1280,
        int $quality = 82
    ): array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new InvalidArgumentException("Source image not readable: {$sourcePath}");
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new InvalidArgumentException("Invalid image binary: {$sourcePath}");
        }

        [$origWidth, $origHeight] = [$info[0], $info[1]];
        $origMime = (string)$info['mime'];

        $destDir = dirname($destinationPathWithoutExt);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException("Unable to create image destination directory: {$destDir}");
        }

        if (!self::isGdAvailable()) {
            $ext = $this->extensionFromMime($origMime);
            $finalPath = $destinationPathWithoutExt . '.' . $ext;
            if (!copy($sourcePath, $finalPath)) {
                throw new RuntimeException("Failed copying image to {$finalPath}");
            }

            return [
                'path'   => $finalPath,
                'width'  => $origWidth,
                'height' => $origHeight,
                'mime'   => $origMime,
                'bytes'  => (int)filesize($finalPath),
                'webp'   => $origMime === 'image/webp',
            ];
        }

        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            throw new RuntimeException("Unable to read image bytes: {$sourcePath}");
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new InvalidArgumentException("Unsupported image encoding: {$sourcePath}");
        }

        $targetWidth = $origWidth;
        $targetHeight = $origHeight;

        if ($maxWidth > 0 && $origWidth > $maxWidth) {
            $ratio = $maxWidth / $origWidth;
            $targetWidth = $maxWidth;
            $targetHeight = max(1, (int)round($origHeight * $ratio));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);

        $savedAsWebp = false;
        if (self::isWebpSupported()) {
            $finalPath = $destinationPathWithoutExt . '.webp';
            imagewebp($canvas, $finalPath, max(1, min(100, $quality)));
            $finalMime = 'image/webp';
            $savedAsWebp = true;
        } else {
            $finalPath = $destinationPathWithoutExt . '.jpg';
            imagejpeg($canvas, $finalPath, max(1, min(100, $quality)));
            $finalMime = 'image/jpeg';
        }

        imagedestroy($canvas);
        imagedestroy($src);

        return [
            'path'   => $finalPath,
            'width'  => $targetWidth,
            'height' => $targetHeight,
            'mime'   => $finalMime,
            'bytes'  => (int)filesize($finalPath),
            'webp'   => $savedAsWebp,
        ];
    }

    /**
     * Render an SEO-friendly <img> tag with explicit width/height and lazy loading.
     */
    public static function renderImgTag(
        string $src,
        string $alt,
        int $width,
        int $height,
        bool $lazy = true,
        string $class = ''
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $loading = $lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high" decoding="sync"';
        $classAttr = $class !== '' ? ' class="' . $e($class) . '"' : '';

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d"%s%s>',
            $e($src),
            $e($alt),
            $width,
            $height,
            $classAttr,
            $loading
        );
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'bin',
        };
    }
}
