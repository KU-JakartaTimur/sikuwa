<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /**
     * Pasang environment palsu untuk satu test.
     *
     * @param array<string,string> $values
     */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    public function testReadsEveryValueFromEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_TOKEN' => 'tok',
            'WHATSAPP_URL' => 'https://gw.test/',
            'WHATSAPP_SESSION' => 'sess-1',
            'WHATSAPP_INSTANCE' => 'inst-1',
            'WHATSAPP_TIMEOUT' => '25',
            'WHATSAPP_PROVIDER' => 'OpenWA',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('tok', $config->token());
        self::assertSame('https://gw.test', $config->url());
        self::assertSame('sess-1', $config->session());
        self::assertSame('inst-1', $config->instance());
        self::assertSame(25.0, $config->timeout());
        self::assertSame('OpenWA', $config->provider());
    }

    public function testEmptyEnvironmentValuesAreTreatedAsAbsent(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => '', 'WHATSAPP_URL' => '']);

        $config = Config::fromEnvironment();

        self::assertSame('', $config->token());
        self::assertSame('', $config->url());
    }

    public function testExplicitValuesWinOverEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'dari-env', 'WHATSAPP_URL' => 'https://env.test']);

        $config = Config::from(['token' => 'eksplisit', 'url' => 'https://eksplisit.test']);

        self::assertSame('eksplisit', $config->token());
        self::assertSame('https://eksplisit.test', $config->url());
    }

    public function testProviderTokenBeatsGlobalToken(): void
    {
        self::fakeEnv([
            'WHATSAPP_TOKEN' => 'umum',
            'WHATSAPP_TOKEN_OpenWA' => 'khusus-openwa',
        ]);

        $config = Config::fromEnvironment();

        self::assertSame('khusus-openwa', $config->token('OpenWA'));
        self::assertSame('umum', $config->token('Fonnte'));
    }

    public function testTokenOptionBeatsProviderToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN_Fonnte' => 'dari-env']);

        $config = Config::from(['tokens' => ['Fonnte' => 'dari-opsi']]);

        self::assertSame('dari-opsi', $config->token('Fonnte'));
    }

    /**
     * Inti dari penyaringan mode `auto`: token umum tidak boleh membuat semua
     * gateway dianggap siap.
     */
    public function testProviderTokenDoesNotFallBackToGlobalToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'umum']);

        self::assertNull(Config::fromEnvironment()->providerToken('Fonnte'));
    }

    public function testUrlFallsBackToProviderDefault(): void
    {
        self::fakeEnv([]);

        self::assertSame('https://default.test', Config::fromEnvironment()->url('https://default.test'));
    }

    #[DataProvider('timeouts')]
    public function testTimeoutIsClampedToSaneRange(?string $configured, float $expected): void
    {
        self::fakeEnv($configured === null ? [] : ['WHATSAPP_TIMEOUT' => $configured]);

        self::assertSame($expected, Config::fromEnvironment()->timeout());
    }

    /** @return array<string,array{0:?string,1:float}> */
    public static function timeouts(): array
    {
        return [
            'tidak diisi' => [null, 10.0],
            'nilai wajar' => ['25', 25.0],
            'nol ditolak' => ['0', 10.0],
            'negatif ditolak' => ['-5', 10.0],
            'terlalu besar ditolak' => ['999', 10.0],
            'bukan angka ditolak' => ['abc', 10.0],
        ];
    }

    /**
     * Fonnte memakai ini supaya `WHATSAPP_URL` — yang biasanya ditujukan untuk
     * gateway self-hosted — tidak mengalihkan pengirimannya ke host lain.
     */
    public function testExplicitUrlIgnoresEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_URL' => 'https://env.test']);

        // Config tanpa opsi `url` tidak menarik WHATSAPP_URL sama sekali...
        self::assertNull(Config::from(['token' => 'tok'])->explicitUrl());

        // ...sedangkan URL yang benar-benar diberikan tetap terbaca.
        self::assertSame('https://fonnte.test', Config::from(['url' => 'https://fonnte.test'])->explicitUrl());

        // Jalan lain (url()) tetap membaca environment seperti biasa.
        self::assertSame('https://env.test', Config::from(['token' => 'tok'])->url());
    }

    public function testWithUrlReturnsACopy(): void
    {
        $config = Config::from(['url' => 'https://awal.test']);
        $copy = $config->withUrl('https://baru.test');

        self::assertNotSame($config, $copy);
        self::assertSame('https://awal.test', $config->url());
        self::assertSame('https://baru.test', $copy->url());
    }

    public function testWithUrlIgnoresEmptyValue(): void
    {
        $config = Config::from(['url' => 'https://awal.test']);

        self::assertSame($config, $config->withUrl(null));
        self::assertSame($config, $config->withUrl(''));
    }

    public function testFromAcceptsConfigInstance(): void
    {
        $config = Config::from(['token' => 'tok']);

        self::assertSame($config, Config::from($config));
    }

    #[DataProvider('notificationFlags')]
    public function testNotificationFlagIsRead(?string $value, bool $expected): void
    {
        self::fakeEnv($value === null ? [] : ['WA_NOTIFICATION' => $value]);

        self::assertSame($expected, Config::notificationEnabled());
    }

    /** @return array<string,array{0:?string,1:bool}> */
    public static function notificationFlags(): array
    {
        return [
            'tidak diisi berarti aktif' => [null, true],
            'true' => ['true', true],
            'false' => ['false', false],
            'satu' => ['1', true],
            'nol' => ['0', false],
        ];
    }
}
