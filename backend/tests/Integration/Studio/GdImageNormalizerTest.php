<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\ImageRefusalReason;
use App\Studio\Domain\Image\ImageRefused;
use App\Studio\Infrastructure\Image\GdImageNormalizer;
use App\Tests\Support\Studio\TestImages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdImageNormalizer::class)]
final class GdImageNormalizerTest extends TestCase
{
    #[Test]
    public function aPngIsReEncodedAndKeepsItsAlpha(): void
    {
        $stored = new GdImageNormalizer()->normalize(TestImages::png());

        self::assertSame(CardImage::PNG, $stored->image->mediaType);
        self::assertSame([815, 1110], [$stored->image->width, $stored->image->height]);
        self::assertSame(\strlen($stored->bytes), $stored->image->byteSize);
        self::assertSame(hash('sha256', $stored->bytes), $stored->image->sha256);
        $decoded = imagecreatefromstring($stored->bytes);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame(127, (imagecolorat($decoded, 5, 5) >> 24) & 0x7F, 'The transparent corner stays transparent.');
    }

    #[Test]
    public function aJpegIsReEncodedAsAJpeg(): void
    {
        $stored = new GdImageNormalizer()->normalize(TestImages::jpeg(408, 555));

        self::assertSame(CardImage::JPEG, $stored->image->mediaType);
        self::assertSame([408, 555], [$stored->image->width, $stored->image->height]);
        self::assertSame("\xFF\xD8", substr($stored->bytes, 0, 2));
    }

    #[Test]
    public function metadataAndTrailingBytesDoNotSurvive(): void
    {
        $png = TestImages::png();
        $text = 'tEXt'."Comment\0secret-gps-40.4,-3.7";
        $tainted = substr($png, 0, 33).pack('N', \strlen($text) - 4).$text.pack('N', crc32($text)).substr($png, 33).'<?php echo "polyglot";';

        $stored = new GdImageNormalizer()->normalize($tainted);

        self::assertStringNotContainsString('secret-gps', $stored->bytes);
        self::assertStringNotContainsString('polyglot', $stored->bytes);
    }

    #[Test]
    public function theSameUploadHasTheSameHash(): void
    {
        $png = TestImages::png();

        self::assertSame(new GdImageNormalizer()->normalize($png)->image->sha256, new GdImageNormalizer()->normalize($png)->image->sha256);
    }

    /**
     * @return iterable<string, array{string, ImageRefusalReason}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'a GIF' => [TestImages::gif(), ImageRefusalReason::UnsupportedFormat];
        yield 'an SVG' => [TestImages::svg(), ImageRefusalReason::UnsupportedFormat];
        yield 'garbage bytes' => [TestImages::garbage(), ImageRefusalReason::Unreadable];
        yield 'an empty file' => ['', ImageRefusalReason::Unreadable];
        yield 'a PNG that stops early' => [TestImages::truncatedPng(), ImageRefusalReason::Unreadable];
        yield 'too few pixels' => [TestImages::png(300, 408), ImageRefusalReason::TooFewPixels];
        yield 'too many pixels' => [TestImages::jpeg(1700, 2316), ImageRefusalReason::TooManyPixels];
        yield 'a wrong aspect ratio' => [TestImages::png(900, 900), ImageRefusalReason::WrongAspectRatio];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function anythingElseIsRefusedWithItsReason(string $upload, ImageRefusalReason $reason): void
    {
        try {
            new GdImageNormalizer()->normalize($upload);
            self::fail('The upload was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame($reason, $refusal->reason);
        }
    }

    #[Test]
    public function aHugeHeaderIsRefusedBeforeAnythingIsDecoded(): void
    {
        // A valid PNG header claiming 60000 × 80000 px would need 19 GB; the pixel check refuses it from the header.
        $png = TestImages::png();
        $header = substr($png, 0, 16).pack('N2', 60000, 80000).substr($png, 24);

        try {
            new GdImageNormalizer()->normalize($header);
            self::fail('The upload was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame(ImageRefusalReason::TooManyPixels, $refusal->reason);
        }
    }
}
