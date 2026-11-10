<?php

namespace App\Service;

final class ImageResizer
{
    public const MAX_SIZE = 1920;

    /**
     * Moves the image to $targetPath, downscaling it so its longest side is at most MAX_SIZE.
     * Smaller images are never upscaled. Animated GIFs are moved untouched to keep their animation.
     */
    public function resizeAndStore(string $sourcePath, string $mimeType, string $targetPath): void
    {
        if ($mimeType === 'image/gif') {
            $this->move($sourcePath, $targetPath);

            return;
        }

        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };
        if ($image === false) {
            throw new \RuntimeException('The uploaded photo could not be read.');
        }

        $orientation = $mimeType === 'image/jpeg' ? $this->exifOrientation($sourcePath) : 1;
        $image = $this->applyOrientation($image, $orientation);

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest > self::MAX_SIZE) {
            $ratio = self::MAX_SIZE / $longest;
            $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);
            if ($scaled === false) {
                throw new \RuntimeException('The uploaded photo could not be resized.');
            }
            $image = $scaled;
        } elseif ($orientation === 1) {
            $this->move($sourcePath, $targetPath);

            return;
        }

        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $targetPath, 90),
            'image/png' => $this->savePng($image, $targetPath),
            'image/webp' => imagewebp($image, $targetPath, 90),
        };
        if (!$saved) {
            throw new \RuntimeException('The resized photo could not be saved.');
        }
        @unlink($sourcePath);
    }

    private function savePng(\GdImage $image, string $targetPath): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $targetPath, 6);
    }

    private function move(string $sourcePath, string $targetPath): void
    {
        if (!@rename($sourcePath, $targetPath) && !(@copy($sourcePath, $targetPath) && @unlink($sourcePath))) {
            throw new \RuntimeException('The uploaded photo could not be stored.');
        }
    }

    private function exifOrientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
