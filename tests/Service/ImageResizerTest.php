<?php

namespace App\Tests\Service;

use App\Service\ImageResizer;
use PHPUnit\Framework\TestCase;

final class ImageResizerTest extends TestCase
{
    public function testResizingPreservesJpegOrientation(): void
    {
        if (!extension_loaded('gd') || !function_exists('exif_read_data')) {
            self::markTestSkipped('GD and EXIF are required.');
        }

        foreach ([
            [2400, 1200, 6, 960, 1920, true],
            [2400, 1200, 8, 960, 1920, false],
            [60, 40, 6, 40, 60, true],
        ] as [$width, $height, $orientation, $expectedWidth, $expectedHeight, $redAtTop]) {
            $source = tempnam(sys_get_temp_dir(), 'resize-source-');
            $target = tempnam(sys_get_temp_dir(), 'resize-target-');
            self::assertNotFalse($source);
            self::assertNotFalse($target);

            try {
                $image = imagecreatetruecolor($width, $height);
                $red = imagecolorallocate($image, 255, 0, 0);
                $blue = imagecolorallocate($image, 0, 0, 255);
                imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, $red);
                imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, $blue);
                imagejpeg($image, $source);
                unset($image);

                $jpeg = file_get_contents($source);
                $exif = "Exif\0\0II".pack('vV', 42, 8)
                    .pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0".pack('V', 0);
                file_put_contents($source, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
                self::assertSame($orientation, exif_read_data($source)['Orientation']);

                (new ImageResizer())->resizeAndStore($source, 'image/jpeg', $target);

                self::assertFileDoesNotExist($source);
                $result = imagecreatefromjpeg($target);
                self::assertNotFalse($result);
                self::assertSame($expectedWidth, imagesx($result));
                self::assertSame($expectedHeight, imagesy($result));
                $top = imagecolorsforindex($result, imagecolorat($result, intdiv($expectedWidth, 2), intdiv($expectedHeight, 4)));
                $bottom = imagecolorsforindex($result, imagecolorat($result, intdiv($expectedWidth, 2), intdiv($expectedHeight * 3, 4)));
                self::assertSame($redAtTop, $top['red'] > $top['blue']);
                self::assertSame(!$redAtTop, $bottom['red'] > $bottom['blue']);
                unset($result);
            } finally {
                if (is_file($source)) {
                    unlink($source);
                }
                if (is_file($target)) {
                    unlink($target);
                }
            }
        }
    }
}
