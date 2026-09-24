<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\AuthException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Tests\MockBackend;

final class WuzapiTest extends TestCase
{
    private const BASE = 'https://v4.test';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    /** @param array<string,mixed> $options */
    private function provider(MockBackend $backend, array $options = []): Wuzapi
    {
        return new Wuzapi(array_merge(['token' => 'user-token', 'url' => self::BASE], $options), $backend->executor());
    }

    public function testSendsSingleMessageWithPascalCasePayload(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['Id' => '3EB0WUZ']]),
        ]);

        $result = $this->provider($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: 3EB0WUZ', $result);
        self::assertSame(self::BASE . '/chat/send/text', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));
        self::assertSame(['Phone' => '6281234567890', 'Body' => 'halo'], $backend->lastJson());
    }

    /**
     * wuzapi membalas HTTP 200 hampir di semua jalur, jadi penanda `success`
     * di amplop body yang harus dipercaya — bukan status HTTP-nya.
     */
    public function testSuccessFlagFalseIsTreatedAsFailure(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 500, 'error' => 'gagal mengirim', 'success' => false]),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Wuzapi menolak pesan (HTTP 500): gagal mengirim');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testUnauthorizedExplainsToken(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 401, 'error' => 'unauthorized', 'success' => false]),
        ]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[token salah, cek WHATSAPP_TOKEN]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNoSessionErrorExplainsQrLogin(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 500, 'error' => 'no session found', 'success' => false]),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('[sesi WhatsApp belum tersambung, scan QR di /login]');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testHttpErrorStatusIsRejected(): void
    {
        $backend = new MockBackend([MockBackend::json(['error' => 'boom'], 502)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Wuzapi menolak pesan (HTTP 502): boom');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testNonJsonSuccessBodyIsNotReportedAsSuccess(): void
    {
        $backend = new MockBackend([MockBackend::raw()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Respons Wuzapi tidak valid (HTTP 200)');

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $backend = new MockBackend([MockBackend::timeout()]);

        $this->expectException(TimeoutException::class);

        $this->provider($backend)->sendMessage(['destination' => '0811', 'message' => 'a']);
    }

    public function testSuccessfulBulkIsReported(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'b']]),
        ]);

        $result = $this->provider($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $result);
        self::assertSame(2, $backend->count());
    }

    public function testBulkFailureIsAggregated(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
            MockBackend::json(['code' => 500, 'error' => 'nomor tidak valid', 'success' => false]),
        ]);

        try {
            $this->provider($backend)->sendMessage([
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
            ]);
            self::fail('Seharusnya melempar ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('1/2 pesan terkirim', $e->getMessage());
            self::assertStringContainsString('62822:', $e->getMessage());
        }
    }

    public function testUrlComesFromEnvironment(): void
    {
        Config::useResolver(static fn (string $key): ?string => [
            'WHATSAPP_URL' => 'https://env-wuzapi.test/',
            'WHATSAPP_TOKEN' => 'tok-env',
        ][$key] ?? null);

        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'a']])]);

        new Wuzapi(null, $backend->executor())
            ->sendMessage(['destination' => '0811', 'message' => 'a']);

        self::assertSame('https://env-wuzapi.test/chat/send/text', (string) $backend->lastRequest()?->getUri());
        self::assertSame('tok-env', $backend->lastHeader('Token'));
    }
}
