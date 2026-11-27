<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

/**
 * Images made with GD at run time, so no binary fixture is committed.
 */
final class TestImages
{
    /**
     * A PNG with a transparent corner.
     */
    public static function png(int $width = 815, int $height = 1110): string
    {
        $image = self::canvas($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, 20, 20, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        return self::encode(static fn (): bool => imagepng($image));
    }

    public static function jpeg(int $width = 815, int $height = 1110): string
    {
        $image = self::canvas($width, $height);

        return self::encode(static fn (): bool => imagejpeg($image));
    }

    public static function gif(int $width = 815, int $height = 1110): string
    {
        $image = self::canvas($width, $height);

        return self::encode(static fn (): bool => imagegif($image));
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="815" height="1110"><rect width="100" height="100"/></svg>';
    }

    public static function garbage(): string
    {
        return random_bytes(2048);
    }

    /**
     * A PNG whose header says 815 × 1110 and whose pixels stop early.
     */
    public static function truncatedPng(): string
    {
        return substr(self::png(), 0, 400);
    }

    private static function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        \assert($image instanceof \GdImage);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), (int) imagecolorallocate($image, 200, 30, 30));

        return $image;
    }

    /**
     * @param callable(): bool $encode
     */
    private static function encode(callable $encode): string
    {
        ob_start();
        $encode();

        return (string) ob_get_clean();
    }
}
