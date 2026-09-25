<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Support\Presence;

/**
 * Kosakata keadaan "sedang mengetik" dan aturan durasinya.
 *
 * Yang dijaga di sini adalah dua hal yang tidak boleh diserahkan ke tiap
 * provider: istilah gateway mana pun harus diterima dan dipetakan ke satu
 * kosakata, dan durasi tidak boleh hilang saat indikator ditampilkan — sebab
 * Fonnte dan Evolution API akan menampilkan indikator nol detik tanpa keluhan.
 */
final class PresenceTest extends TestCase
{
    public function testComposingIsTheDefaultState(): void
    {
        $presence = Presence::from('', 5);

        self::assertSame(Presence::COMPOSING, $presence->state);
        self::assertTrue($presence->isComposing());
        self::assertTrue($presence->showsIndicator());
    }

    #[DataProvider('alias')]
    public function testGatewayWordsAreMappedToTheCanonicalVocabulary(string $alias, string $expected): void
    {
        self::assertSame($expected, Presence::from($alias, 5)->state);
    }

    /** @return array<string,array{string,string}> */
    public static function alias(): array
    {
        return [
            'typing — istilah OpenWA' => ['typing', Presence::COMPOSING],
            'compose' => ['compose', Presence::COMPOSING],
            'menulis' => ['menulis', Presence::COMPOSING],
            'stop — istilah Fonnte' => ['stop', Presence::PAUSED],
            'pause' => ['pause', Presence::PAUSED],
            'berhenti' => ['berhenti', Presence::PAUSED],
            'audio — istilah wuzapi' => ['audio', Presence::RECORDING],
            'record' => ['record', Presence::RECORDING],
        ];
    }

    public function testStateIsCaseInsensitiveAndTrimmed(): void
    {
        self::assertSame(Presence::RECORDING, Presence::from('  Recording ', 5)->state);
    }

    public function testUnknownStateListsTheAcceptedOnes(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('composing, paused, atau recording');

        Presence::from('melamun', 5);
    }

    public function testDurationIsRequiredWhileShowingAnIndicator(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kunci 'duration'");

        Presence::from('composing');
    }

    public function testPausedNeedsNoDuration(): void
    {
        $presence = Presence::from('paused');

        self::assertSame(Presence::PAUSED, $presence->state);
        self::assertSame(0, $presence->duration);
        self::assertFalse($presence->showsIndicator());
    }

    public function testFractionalSecondsAreRoundedUp(): void
    {
        self::assertSame(2, Presence::from('composing', 1.2)->duration);
        self::assertSame(3, Presence::from('composing', '2.5')->duration);
    }

    public function testZeroDurationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('minimal 1 detik');

        Presence::from('composing', 0);
    }

    public function testNonNumericDurationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('harus berupa angka detik');

        Presence::from('composing', 'sebentar');
    }

    /**
     * Nilainya datang dari array pesan pemanggil, jadi tipe yang salah harus
     * menjadi ConfigurationException — bukan TypeError yang tidak menjelaskan
     * apa pun tentang cara memperbaikinya.
     */
    public function testNonScalarDurationIsRejectedAsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('bukan array');

        Presence::from('composing', ['lima']);
    }

    public function testMillisecondsConvertTheDuration(): void
    {
        self::assertSame(5000, Presence::from('composing', 5)->milliseconds());
    }

    public function testLabelDescribesTheStateInIndonesian(): void
    {
        self::assertSame('sedang mengetik', Presence::from('typing', 5)->label());
        self::assertSame('sedang merekam suara', Presence::from('recording', 5)->label());
        self::assertSame('berhenti mengetik', Presence::from('paused')->label());
    }
}
