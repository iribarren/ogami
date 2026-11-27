<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain;

use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\ImageRefusalReason;
use App\Studio\Domain\Image\ImageRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardImage::class)]
#[CoversClass(ImageRefused::class)]
final class CardImageTest extends TestCase
{
    private const string HASH = 'a3f1c0de00000000000000000000000000000000000000000000000000000000';

    #[Test]
    public function theRecommendedSizeAndTheLimitsAreAccepted(): void
    {
        foreach ([[815, 1110], [408, 555], [1630, 2220], [409, 555], [815, 1129], [815, 1090]] as [$width, $height]) {
            $image = CardImage::of(self::HASH, CardImage::PNG, $width, $height, 1000);

            self::assertSame([$width, $height], [$image->width, $image->height]);
        }
    }

    /**
     * @return iterable<string, array{int, int, ImageRefusalReason}>
     */
    public static function refusedPixels(): iterable
    {
        yield 'narrower than the minimum' => [407, 555, ImageRefusalReason::TooFewPixels];
        yield 'shorter than the minimum' => [408, 554, ImageRefusalReason::TooFewPixels];
        yield 'wider than the maximum' => [1631, 2220, ImageRefusalReason::TooManyPixels];
        yield 'taller than the maximum' => [1630, 2221, ImageRefusalReason::TooManyPixels];
        yield 'a square' => [900, 900, ImageRefusalReason::WrongAspectRatio];
        yield 'landscape' => [1110, 815, ImageRefusalReason::WrongAspectRatio];
        yield 'just over 2 % wide' => [835, 1110, ImageRefusalReason::WrongAspectRatio];
        yield 'just over 2 % narrow' => [795, 1110, ImageRefusalReason::WrongAspectRatio];
    }

    #[Test]
    #[DataProvider('refusedPixels')]
    public function pixelsOutOfRangeOrOutOfProportionAreRefusedWithAReason(int $width, int $height, ImageRefusalReason $reason): void
    {
        try {
            CardImage::of(self::HASH, CardImage::JPEG, $width, $height, 1000);
            self::fail('The image was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame($reason, $refusal->reason);
            self::assertStringContainsString('815 × 1110', $refusal->getMessage());
        }
    }

    #[Test]
    public function aPixelRefusalNamesTheLimitAndTheRecommendedSize(): void
    {
        $message = ImageRefused::because(ImageRefusalReason::TooFewPixels)->getMessage();

        self::assertStringContainsString('408 × 555', $message);
        self::assertStringContainsString('815 × 1110', $message);
        self::assertStringContainsString('300 dpi', $message);
    }

    #[Test]
    public function onlyPngAndJpegAreAKnownType(): void
    {
        foreach (['image/gif', 'image/svg+xml', 'image/webp', ''] as $type) {
            try {
                CardImage::of(self::HASH, $type, 815, 1110, 1000);
                self::fail('The type was accepted.');
            } catch (ImageRefused $refusal) {
                self::assertSame(ImageRefusalReason::UnsupportedFormat, $refusal->reason);
            }
        }
    }

    #[Test]
    public function aHashThatIsNotLowerCaseSha256HexIsRejected(): void
    {
        foreach (['', 'abc', strtoupper(self::HASH), self::HASH.'0'] as $hash) {
            try {
                CardImage::of($hash, CardImage::PNG, 815, 1110, 1000);
                self::fail('The hash was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function anEmptyImageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CardImage::of(self::HASH, CardImage::PNG, 815, 1110, 0);
    }

    #[Test]
    public function anUploadOfExactlyFiveMegabytesIsAcceptedAndOneByteMoreIsNot(): void
    {
        CardImage::assertUploadSize(5 * 1024 * 1024);
        $this->addToAssertionCount(1);

        try {
            CardImage::assertUploadSize(5 * 1024 * 1024 + 1);
            self::fail('The upload was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame(ImageRefusalReason::TooLarge, $refusal->reason);
            self::assertStringContainsString('5 MB', $refusal->getMessage());
        }
    }
}
