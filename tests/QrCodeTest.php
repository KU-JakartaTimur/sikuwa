<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;

/**
 * QR sesi — `showQr()` di semua gateway, plus penyajiannya sebagai base64 atau
 * gambar.
 *
 * Yang diperiksa bukan cuma hasil akhirnya, tapi juga method HTTP, URL, dan
 * header autentikasi yang benar-benar dikirim: itulah bagian yang paling mudah
 * rusak saat provider dirapikan.
 *
 * Satu hal yang dijaga ketat di sini: **sesi yang sudah tersambung bukan
 * kegagalan.** Gateway yang mengatakannya terus terang harus menghasilkan sesi
 * `connected` tanpa QR, bukan exception.
 */
final class QrCodeTest extends TestCase
{
    private const BASE = 'https://gw.test';

    /** Base64 PNG kecil yang cukup untuk membuktikan perangkaian data URI. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUg==';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // Penolong Qr
    // ---------------------------------------------------------------------

    public function testQrHelperWrapsBareBase64AndLeavesDataUriAlone(): void
    {
        self::assertSame('data:image/png;base64,' . self::PNG, Qr::dataUri(self::PNG));
        // Idempoten: data URI yang sudah lengkap tidak boleh dibungkus dua kali.
        self::assertSame('data:image/png;base64,' . self::PNG, Qr::dataUri('data:image/png;base64,' . self::PNG));
        self::assertSame('', Qr::dataUri(''));
        self::assertSame('', Qr::dataUri('   '));
    }

    public function testQrHelperExtractsBareBase64(): void
    {
        self::assertSame(self::PNG, Qr::base64('data:image/png;base64,' . self::PNG));
        // Payload yang memang sudah base64 telanjang dikembalikan apa adanya.
        self::assertSame(self::PNG, Qr::base64(self::PNG));
        self::assertSame('', Qr::base64(''));
    }

    // ---------------------------------------------------------------------
    // OpenWA
    // ---------------------------------------------------------------------

    public function testOpenWaFetchesQrAsDataUri(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'qrCode' => 'data:image/png;base64,' . self::PNG,
            'status' => 'qr_ready',
        ])]);

        $qr = $this->openWa($backend)->showQr();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/sessions/sess-1/qr', (string) $backend->lastRequest()?->getUri());
        self::assertSame('k', $backend->lastHeader('X-API-Key'));

        self::assertTrue($qr->hasQr());
        self::assertFalse($qr->isConnected());
        // `status` di sini adalah kesiapan QR, bukan status sesi.
        self::assertSame('QR_READY', $qr->status);
        self::assertSame('data:image/png;base64,' . self::PNG, $qr->qrImage());
        self::assertSame(self::PNG, $qr->qrBase64());
    }

    public function testOpenWaAcceptsBareQrFieldInsideWrappedEnvelope(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['qr' => self::PNG]])]);

        $qr = $this->openWa($backend)->showQr('sess-9');

        // base64 telanjang tetap dibungkus, jadi pemanggil tidak perlu tahu bedanya.
        self::assertSame('data:image/png;base64,' . self::PNG, $qr->qrImage());
        self::assertSame('sess-9', $qr->id);
    }

    public function testOpenWaQrWithoutSessionIdIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        (new OpenWA(['token' => 'k', 'url' => self::BASE], $backend->executor()))->showQr();
    }

    // ---------------------------------------------------------------------
    // ApiMe — skema balasannya tidak didokumentasikan, jadi bacaannya longgar
    // ---------------------------------------------------------------------

    public function testApiMeFetchesQrFromStringData(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => self::PNG])]);

        $qr = $this->apiMe($backend)->showQr();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/api/instances/uuid-9/qr', (string) $backend->lastRequest()?->getUri());
        self::assertSame('Bearer inst-token', $backend->lastHeader('Authorization'));

        self::assertTrue($qr->hasQr());
        self::assertSame(self::PNG, $qr->qrBase64());
    }

    public function testApiMeAcceptsSeveralQrFieldNames(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['qrcode' => self::PNG, 'status' => 'waiting']])]);

        $qr = $this->apiMe($backend)->showQr();

        self::assertSame('data:image/png;base64,' . self::PNG, $qr->qrImage());
        self::assertSame('waiting', $qr->status);
    }

    public function testApiMeQrWithoutInstanceIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_INSTANCE belum diisi di .env');

        (new ApiMe(['token' => 't', 'url' => self::BASE], $backend->executor()))->showQr();
    }

    // ---------------------------------------------------------------------
    // Evolution API
    // ---------------------------------------------------------------------

    public function testEvolutionConnectReturnsQr(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'count' => 1,
            'base64' => self::PNG,
            'code' => '2@abc-def',
        ])]);

        $qr = $this->evolution($backend)->showQr();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/instance/connect/sikuwa', (string) $backend->lastRequest()?->getUri());
        self::assertSame('global-key', $backend->lastHeader('apikey'));

        self::assertTrue($qr->hasQr());
        self::assertFalse($qr->isConnected());
        self::assertSame(self::PNG, $qr->qrBase64());
    }

    /** Instance yang sudah `open` membalas keadaannya, bukan QR — itu bukan error. */
    public function testEvolutionConnectReportsAlreadyConnectedInstanceAsAState(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'instance' => ['instanceName' => 'sikuwa', 'state' => 'open'],
        ])]);

        $qr = $this->evolution($backend)->showQr();

        self::assertTrue($qr->isConnected());
        self::assertFalse($qr->hasQr());
        self::assertSame('open', $qr->status);
    }

    // ---------------------------------------------------------------------
    // wuzapi
    // ---------------------------------------------------------------------

    public function testWuzapiFetchesQrFromDataEnvelope(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'code' => 200,
            'success' => true,
            'data' => ['QRCode' => 'data:image/png;base64,' . self::PNG, 'passkeyPending' => false],
        ])]);

        $qr = $this->wuzapi($backend)->showQr();

        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(self::BASE . '/session/qr', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));

        self::assertTrue($qr->hasQr());
        self::assertSame('qr_ready', $qr->status);
        self::assertSame(self::PNG, $qr->qrBase64());
    }

    /**
     * wuzapi melaporkan sesi yang sudah login sebagai error HTTP, padahal bagi
     * pemanggil itu keadaan: tidak ada lagi QR yang perlu dipindai.
     */
    public function testWuzapiAlreadyLoggedInIsAStateNotAFailure(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 500, 'success' => false, 'error' => 'already logged in'], 500),
        ]);

        $qr = $this->wuzapi($backend)->showQr();

        self::assertTrue($qr->isConnected());
        self::assertFalse($qr->hasQr());
        self::assertSame('connected', $qr->status);
    }

    /** `not connected` dan `no session` tetap kegagalan sungguhan. */
    public function testWuzapiNotConnectedStillThrows(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 500, 'success' => false, 'error' => 'not connected'], 500),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not connected');

        $this->wuzapi($backend)->showQr();
    }

    // ---------------------------------------------------------------------
    // Fonnte — QR memakai token perangkat, bukan account token
    // ---------------------------------------------------------------------

    public function testFonnteFetchesQrWithDeviceToken(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'url' => self::PNG])]);

        $qr = $this->fonnte($backend)->showQr();

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/qr', (string) $backend->lastRequest()?->getUri());
        // Token perangkat yang dipakai, bukan account token milik Device API.
        self::assertSame('device-tok', $backend->lastHeader('Authorization'));
        self::assertSame(['type' => 'qr'], $backend->lastJson());

        self::assertTrue($qr->hasQr());
        // Fonnte mengirim base64 telanjang; SDK yang merangkai data URI-nya.
        self::assertSame('data:image/png;base64,' . self::PNG, $qr->qrImage());
        self::assertSame(self::PNG, $qr->qrBase64());
    }

    public function testFonnteTargetsDeviceWhenNumberIsGiven(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'url' => self::PNG])]);

        $this->fonnte($backend)->showQr('08123456789');

        // Nomor dinormalkan ke format internasional sebelum dikirim.
        self::assertSame(['type' => 'qr', 'whatsapp' => '628123456789'], $backend->lastJson());
    }

    public function testFonnteAlreadyConnectedIsAStateNotAFailure(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => false, 'reason' => 'device already connect']),
        ]);

        $qr = $this->fonnte($backend)->showQr();

        self::assertTrue($qr->isConnected());
        self::assertFalse($qr->hasQr());
        self::assertSame('connect', $qr->status);
    }

    public function testFonnteQrRejectionStillThrows(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'token invalid'])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('token invalid');

        $this->fonnte($backend)->showQr();
    }

    // ---------------------------------------------------------------------
    // Penyajian QR dan delegasi Client
    // ---------------------------------------------------------------------

    public function testSessionRendersQrAsImageTag(): void
    {
        $session = new Session(
            provider: 'OpenWA',
            qr: 'data:image/png;base64,' . self::PNG,
        );

        self::assertSame(
            '<img src="data:image/png;base64,' . self::PNG . '" alt="QR WhatsApp" width="260" height="260">',
            $session->qrTag()
        );

        // Alt yang mengandung kutip harus di-escape, bukan memutus atributnya.
        self::assertStringContainsString('alt="Scan &quot;sekolah&quot;"', $session->qrTag('Scan "sekolah"', 320));
        self::assertSame(self::PNG, $session->qrBase64());
    }

    public function testSessionWithoutQrRendersNothing(): void
    {
        $session = new Session(provider: 'OpenWA', connected: true);

        self::assertFalse($session->hasQr());
        self::assertSame('', $session->qrTag());
        self::assertSame('', $session->qrImage());
        self::assertSame('', $session->qrBase64());
    }

    public function testClientDelegatesShowQrToProvider(): void
    {
        $backend = new MockBackend([MockBackend::json(['qrCode' => self::PNG, 'status' => 'qr_ready'])]);

        $qr = (new Client(
            ['provider' => 'OpenWA', 'token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->client()
        ))->showQr();

        self::assertSame(self::BASE . '/api/sessions/sess-1/qr', (string) $backend->lastRequest()?->getUri());
        self::assertTrue($qr->hasQr());
    }

    /** @param array<string,mixed> $options */
    private function openWa(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            array_merge(['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'], $options),
            $backend->executor()
        );
    }

    private function apiMe(MockBackend $backend): ApiMe
    {
        return new ApiMe(
            ['token' => 'inst-token', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        );
    }

    private function evolution(MockBackend $backend): EvolutionAPI
    {
        return new EvolutionAPI(
            ['token' => 'global-key', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        );
    }

    private function wuzapi(MockBackend $backend): Wuzapi
    {
        return new Wuzapi(['token' => 'user-token', 'url' => self::BASE], $backend->executor());
    }

    /** Sengaja diberi dua kredensial, untuk membuktikan yang dipakai token perangkat. */
    private function fonnte(MockBackend $backend): Fonnte
    {
        return new Fonnte(
            ['token' => 'device-tok', 'account_token' => 'acct-tok'],
            $backend->executor()
        );
    }
}
