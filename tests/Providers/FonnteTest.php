<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class FonnteTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): Fonnte
    {
        return new Fonnte(array_merge(['token' => 'tok'], $options), $backend->executor());
    }

    /** @return array<int,array<string,string>> */
    private function sentMessages(MockBackend $backend): array
    {
        return json_decode($backend->lastForm()['data'] ?? '[]', true) ?? [];
    }

    public function testSendsSingleMessageAsFormEncodedData(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses', $result);
        self::assertSame('https://api.fonnte.com/send', (string) $backend->lastRequest()?->getUri());
        self::assertSame('tok', $backend->lastHeader('Authorization'));
        self::assertSame(
            [['target' => '081234567890', 'message' => 'halo', 'delay' => '2']],
            $this->sentMessages($backend)
        );
    }

    public function testSendsBulkInOneRequest(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'detail' => '2/2'])]);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b', 'delay' => 5],
        ]);

        self::assertSame('Sukses: 2/2', $result);
        self::assertSame(1, $backend->count());
        self::assertSame([
            ['target' => '0811', 'message' => 'a', 'delay' => '2'],
            ['target' => '0822', 'message' => 'b', 'delay' => '5'],
        ], $this->sentMessages($backend));
    }

    public function testDetailArrayIsFlattenedIntoResult(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'detail' => ['a', 'b']])]);

        self::assertSame(
            'Sukses: a; b',
            $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a'])
        );
    }

    public function testGatewayRejectionUsesReasonAsMessage(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'token tidak valid'])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('token tidak valid');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testRejectionWithoutReasonStillDescribesItself(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Fonnte menolak pesan (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons Fonnte tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testServerErrorIsRejected(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'boom'], 500)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Fonnte menolak pesan (HTTP 500): boom');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testConnectionFailureIsReported(): void
    {
        $backend = new MockBackend([MockBackend::connectionFailure()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Gagal menghubungi Fonnte: cURL error 6');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testStringMessageIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Format pesan tidak valid: Fonnte membutuhkan array pesan');

        $this->provider(new MockBackend())->sendMessage('halo');
    }

    public function testMessageWithoutDestinationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Pesan ke-0');

        $this->provider(new MockBackend())->sendMessage(['message' => 'halo']);
    }

    /**
     * Fonnte adalah layanan cloud dengan endpoint tetap. Kalau WHATSAPP_URL
     * ikut dibaca, satu nilai yang ditujukan untuk gateway self-hosted akan
     * mengalihkan pengiriman ke host yang salah.
     */
    public function testIgnoresWhatsappUrlFromEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_URL' => 'http://localhost:2785', 'WHATSAPP_TOKEN' => 'dari-env']);
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        new Fonnte(null, $backend->executor())
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('https://api.fonnte.com/send', (string) $backend->lastRequest()?->getUri());
        self::assertSame('dari-env', $backend->lastHeader('Authorization'));
    }

    public function testExplicitUrlOverridesDefault(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        $this->provider($backend, ['url' => 'https://proxy.test/send'])
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('https://proxy.test/send', (string) $backend->lastRequest()?->getUri());
    }

    public function testProviderTokenBeatsGlobalToken(): void
    {
        self::fakeEnv(['WHATSAPP_TOKEN' => 'umum', 'WHATSAPP_TOKEN_Fonnte' => 'khusus']);
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        new Fonnte(null, $backend->executor())
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('khusus', $backend->lastHeader('Authorization'));
    }
}
