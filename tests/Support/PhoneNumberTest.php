<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Support\PhoneNumber;

final class PhoneNumberTest extends TestCase
{
    #[DataProvider('numbers')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNumber::normalize($input));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function numbers(): array
    {
        return [
            'awalan nol' => ['081234567890', '6281234567890'],
            'sudah internasional' => ['6281234567890', '6281234567890'],
            'tanpa awalan' => ['81234567890', '6281234567890'],
            'tanda plus' => ['+62 812-3456-7890', '6281234567890'],
            'spasi dan strip' => ['0812 3456 7890', '6281234567890'],
            'tanda kurung dan titik' => ['(0812) 3456.7890', '6281234567890'],
            'nomor luar negeri' => ['14155552671', '14155552671'],
            'kosong' => ['', ''],
            'tanpa angka' => ['abc-def', ''],
        ];
    }

    public function testToWidAppendsSuffix(): void
    {
        self::assertSame('6281234567890@c.us', PhoneNumber::toWid('081234567890'));
    }

    public function testToWidKeepsExistingJid(): void
    {
        self::assertSame('6281234567890@s.whatsapp.net', PhoneNumber::toWid('6281234567890@s.whatsapp.net'));
        self::assertSame('1234567890-123456@g.us', PhoneNumber::toWid('1234567890-123456@g.us'));
    }
}
