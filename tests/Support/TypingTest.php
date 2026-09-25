<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Support\Typing;

/**
 * Lama indikator "sedang mengetik" yang dihitung dari panjang pesan.
 *
 * Yang diuji di sini murni aritmetikanya — berapa detik untuk pesan sekian
 * karakter, dan bagaimana salah tulis di .env disikapi. Jalan ceritanya
 * (indikator benar-benar dikirim sebelum pesan) ada di AutoTypingTest.
 */
final class TypingTest extends TestCase
{
    /**
     * Bawaan: 15 karakter per detik, paling singkat 2 detik, paling lama 20.
     *
     * @return array<string,array{0:int,1:int}>
     */
    public static function panjang(): array
    {
        return [
            'satu karakter tetap dua detik' => [1, 2],
            'sepuluh karakter' => [10, 2],
            'enam puluh karakter' => [60, 4],
            'seratus lima puluh karakter' => [150, 10],
            'tiga ratus karakter' => [300, 20],
            'lima ribu karakter tetap dua puluh' => [5000, 20],
        ];
    }

    #[DataProvider('panjang')]
    public function testDurationFollowsTheMessageLength(int $karakter, int $harapan): void
    {
        self::assertSame($harapan, Typing::fromConfig('1')->durationFor($karakter));
    }

    public function testTheFeatureIsOffUnlessItIsAskedFor(): void
    {
        $typing = Typing::off();

        self::assertFalse($typing->isEnabled());
        self::assertNull($typing->durationFor(300));
    }

    /**
     * Hanya saklarnya yang menyalakan, bukan kunci pengaturannya.
     *
     * Fitur ini menambah satu request ke jalur kirim dan menahan pemanggil
     * selama durasinya, jadi ia tidak boleh menyala hanya karena seseorang
     * menuliskan kecepatan ketiknya di .env.
     */
    public function testTuningKeysAloneDoNotTurnItOn(): void
    {
        $typing = Typing::fromConfig(null, '5', '1', '60');

        self::assertFalse($typing->isEnabled());
        self::assertNull($typing->durationFor(300));
    }

    /**
     * Di dalam satu panggilan, menulis kunci `typing` sudah berarti permintaan.
     *
     * Beda dari .env yang jadi saklar global, kunci ini ditulis di dalam array
     * pesan yang sedang dikirim — pemanggilnya jelas sedang meminta indikator
     * untuk panggilan itu.
     */
    public function testMentioningATypingKeyInACallTurnsItOn(): void
    {
        self::assertTrue(Typing::fromArray(['speed' => 8])->isEnabled());
        self::assertTrue(Typing::fromArray(['enabled' => true])->isEnabled());
    }

    public function testTheCallerCanTurnItOffExplicitly(): void
    {
        $typing = Typing::fromArray(['enabled' => false, 'speed' => 8]);

        self::assertFalse($typing->isEnabled());
        self::assertNull($typing->durationFor(300));
    }

    /** @return array<string,array{0:mixed,1:bool}> */
    public static function saklar(): array
    {
        return [
            'angka satu' => ['1', true],
            'true' => ['true', true],
            'on' => ['on', true],
            'yes' => ['yes', true],
            'huruf besar' => ['TRUE', true],
            'angka nol' => ['0', false],
            'false' => ['false', false],
            'off' => ['off', false],
            'kosong' => ['', false],
            'tidak diisi' => [null, false],
        ];
    }

    #[DataProvider('saklar')]
    public function testTheSwitchAcceptsTheUsualSpellings(mixed $nilai, bool $harapan): void
    {
        self::assertSame($harapan, Typing::fromConfig($nilai)->isEnabled());
    }

    public function testSpeedComesFromTheEnvironment(): void
    {
        $typing = Typing::fromConfig('1', '5');

        self::assertSame(5, $typing->speed());
        // 50 karakter ÷ 5 karakter/detik.
        self::assertSame(10, $typing->durationFor(50));
    }

    public function testTheShortestAndLongestDurationsCanBeAdjusted(): void
    {
        $typing = Typing::fromConfig('1', '15', '5', '60');

        self::assertSame(5, $typing->min());
        self::assertSame(60, $typing->max());
        self::assertSame(5, $typing->durationFor(1));
        self::assertSame(60, $typing->durationFor(5000));
    }

    /**
     * Nol berarti "fitur aktif, tapi pesan ini tidak perlu ditunggu" — beda
     * dari null yang berarti "fitur mati". Keduanya tidak boleh disamakan.
     */
    public function testAnEmptyMessageNeedsNoIndicator(): void
    {
        $typing = Typing::fromConfig('1');

        self::assertSame(0, $typing->durationFor(0));
        self::assertSame(0, $typing->durationFor(-5));
    }

    public function testUnreadableValuesFallBackToTheirDefaults(): void
    {
        $typing = Typing::fromConfig('1', 'cepat', 'dua', 'tiga');

        // Salah tulis satu kunci tidak boleh membatalkan kunci lain — sama
        // seperti WHATSAPP_PACING_* dan WHATSAPP_TIMEOUT.
        self::assertTrue($typing->isEnabled());
        self::assertSame(Typing::DEFAULT_SPEED, $typing->speed());
        self::assertSame(Typing::DEFAULT_MIN, $typing->min());
        self::assertSame(Typing::DEFAULT_MAX, $typing->max());
    }

    public function testAnAbsurdMaximumIsCapped(): void
    {
        // `20000` yang dimaksudkan `20` tidak boleh menahan pemanggil
        // selama berjam-jam.
        $typing = Typing::fromConfig('1', '15', '2', '20000');

        self::assertSame(Typing::MAX_SECONDS, $typing->max());
        self::assertSame(Typing::MAX_SECONDS, $typing->durationFor(999999));
    }

    public function testASpeedOfZeroDoesNotDivideByZero(): void
    {
        $typing = Typing::fromConfig('1', '0');

        self::assertSame(1, $typing->speed());
        self::assertSame(Typing::DEFAULT_MAX, $typing->durationFor(300));
    }

    public function testTheLongestDurationIsNeverBelowTheShortest(): void
    {
        $typing = Typing::fromConfig('1', '15', '30', '5');

        self::assertSame(5, $typing->min());
        self::assertSame(30, $typing->max());
    }

    public function testMergeKeepsWhatTheCallerDidNotMention(): void
    {
        $typing = Typing::fromArray(['speed' => 10, 'max' => 30])->merge(['min' => 5]);

        self::assertSame(10, $typing->speed());
        self::assertSame(30, $typing->max());
        self::assertSame(5, $typing->min());
    }

    public function testAnEmptyOverrideChangesNothing(): void
    {
        $typing = Typing::fromArray(['speed' => 10]);

        self::assertSame($typing, $typing->merge(null));
        self::assertSame($typing, $typing->merge([]));
    }

    public function testAnEmptySpecIsSimplyOff(): void
    {
        self::assertFalse(Typing::fromArray([])->isEnabled());
    }
}
