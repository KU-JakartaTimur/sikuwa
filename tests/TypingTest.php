<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;

/**
 * Indikator "sedang mengetik" — `sendTyping()` di semua gateway.
 *
 * Seperti MediaTest, yang diperiksa bukan cuma hasil akhirnya melainkan juga
 * method HTTP, URL, header autentikasi, dan body yang benar-benar dikirim —
 * itulah bagian yang paling mudah rusak saat provider dirapikan.
 *
 * Perbedaan yang dijaga ketat di sini:
 *
 * - Fonnte memakai satu endpoint `/typing` dengan kolom `stop`, dan tidak
 *   punya indikator merekam suara;
 * - OpenWA memakai kata `typing`, bukan `composing`;
 * - ApiMe menaruh endpointnya di bawah `/whatsapp/presence`;
 * - Evolution API menuntut `delay` dalam MILIDETIK, bukan detik;
 * - wuzapi menandai rekaman suara lewat `Media: audio`, bukan keadaan sendiri.
 */
final class TypingTest extends TestCase
{
    private const BASE = 'https://gw.test';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // Fonnte — satu endpoint, kolom `stop`
    // ---------------------------------------------------------------------

    public function testFonnteStartsTypingWithTheDuration(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        $hasil = $this->fonnte($backend)->sendTyping([
            'destination' => '08123456789',
            'duration' => 4,
        ]);

        $form = $backend->lastForm();

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789', $hasil);
        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/typing', (string) $backend->lastRequest()?->getUri());
        self::assertSame('device-tok', $backend->lastHeader('Authorization'));
        self::assertSame('628123456789', $form['target']);
        // Fonnte menandai `duration` wajib: itulah lama indikatornya tampil.
        self::assertSame('4', $form['duration']);
        // Selama mengetik, tidak ada perintah berhenti.
        self::assertArrayNotHasKey('stop', $form);
    }

    public function testFonnteStopsTypingWithTheStopFlag(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        $hasil = $this->fonnte($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'paused',
        ]);

        $form = $backend->lastForm();

        self::assertSame('Sukses, indikator berhenti mengetik dikirim ke 628123456789', $hasil);
        // Boolean Fonnte dibaca dari teks, bukan dari angka.
        self::assertSame('true', $form['stop']);
        self::assertSame('0', $form['duration']);
    }

    public function testFonnteRejectsTheRecordingIndicator(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Fonnte tidak punya indikator merekam suara');

        $this->fonnte($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'recording',
            'duration' => 3,
        ]);
    }

    // ---------------------------------------------------------------------
    // OpenWA — kata "typing", bukan "composing"
    // ---------------------------------------------------------------------

    public function testOpenWaUsesItsOwnWordForTyping(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $hasil = $this->openWa($backend)->sendTyping([
            'destination' => '08123456789',
            'duration' => 5,
        ]);

        $body = $backend->lastJson();

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789@c.us', $hasil);
        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/chats/typing',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('k', $backend->lastHeader('X-API-Key'));
        // OpenWA menyebutnya "typing", bukan "composing".
        self::assertSame('typing', $body['state']);
        self::assertSame('628123456789@c.us', $body['chatId']);
        // Durasi tidak berarti di sini: statusnya bertahan sampai dihapus.
        self::assertArrayNotHasKey('duration', $body);
    }

    public function testOpenWaRecordsAudio(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $this->openWa($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'recording',
            'duration' => 5,
        ]);

        self::assertSame('recording', $backend->lastJson()['state']);
    }

    public function testOpenWaStopsTyping(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $this->openWa($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'paused',
        ]);

        self::assertSame('paused', $backend->lastJson()['state']);
    }

    // ---------------------------------------------------------------------
    // ApiMe — endpoint di bawah /whatsapp
    // ---------------------------------------------------------------------

    public function testApiMeSendsPresenceUnderTheWhatsappPath(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => 'ok'])]);

        $hasil = $this->apiMe($backend)->sendTyping([
            'destination' => '08123456789',
            'duration' => 5,
        ]);

        $body = $backend->lastJson();

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789', $hasil);
        self::assertSame(
            self::BASE . '/api/instances/uuid-9/whatsapp/presence',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer inst-token', $backend->lastHeader('Authorization'));
        self::assertSame('composing', $body['state']);
        self::assertSame('628123456789', $body['to']);
    }

    public function testApiMeRecordsAudio(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => 'ok'])]);

        $this->apiMe($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'recording',
            'duration' => 5,
        ]);

        self::assertSame('recording', $backend->lastJson()['state']);
    }

    // ---------------------------------------------------------------------
    // Evolution API — `delay` wajib, satuannya milidetik
    // ---------------------------------------------------------------------

    public function testEvolutionSendsTheDurationInMilliseconds(): void
    {
        $backend = new MockBackend([MockBackend::json(['presence' => 'composing'])]);

        $hasil = $this->evolution($backend)->sendTyping([
            'destination' => '08123456789',
            'duration' => 3,
        ]);

        $body = $backend->lastJson();

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789', $hasil);
        self::assertSame(
            self::BASE . '/chat/sendPresence/sikuwa',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('global-key', $backend->lastHeader('apikey'));
        self::assertSame('composing', $body['presence']);
        self::assertSame('628123456789', $body['number']);
        // Skema Evolution menuntut `delay`, dan satuannya milidetik — bukan
        // detik seperti di kunci yang ditulis pemanggil.
        self::assertSame(3000, $body['delay']);
    }

    public function testEvolutionStopsTypingWithoutADelay(): void
    {
        $backend = new MockBackend([MockBackend::json(['presence' => 'paused'])]);

        $this->evolution($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'paused',
        ]);

        $body = $backend->lastJson();

        self::assertSame('paused', $body['presence']);
        self::assertSame(0, $body['delay']);
    }

    // ---------------------------------------------------------------------
    // wuzapi — rekaman suara lewat `Media`
    // ---------------------------------------------------------------------

    public function testWuzapiSendsComposingWithAnEmptyMedia(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $hasil = $this->wuzapi($backend)->sendTyping([
            'destination' => '08123456789',
            'duration' => 5,
        ]);

        $body = $backend->lastJson();

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789', $hasil);
        self::assertSame(self::BASE . '/chat/presence', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));
        self::assertSame('composing', $body['State']);
        // Bukan keadaan tersendiri: rekaman ditandai lewat `Media`.
        self::assertSame('', $body['Media']);
    }

    public function testWuzapiMarksRecordingThroughMedia(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $this->wuzapi($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'recording',
            'duration' => 5,
        ]);

        $body = $backend->lastJson();

        // wuzapi tidak punya `State: recording`; yang berubah hanya `Media`.
        self::assertSame('composing', $body['State']);
        self::assertSame('audio', $body['Media']);
    }

    public function testWuzapiStopsTyping(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $this->wuzapi($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'paused',
        ]);

        $body = $backend->lastJson();

        self::assertSame('paused', $body['State']);
        self::assertSame('', $body['Media']);
    }

    // ---------------------------------------------------------------------
    // Bentuk yang seragam
    // ---------------------------------------------------------------------

    /**
     * Inti janji SDK ini: satu panggilan yang sama harus diterima kelima
     * gateway tanpa pemanggil perlu tahu mana yang sedang aktif.
     */
    public function testTheSameCallIsAcceptedByEveryGateway(): void
    {
        $panggilan = ['destination' => '08123456789', 'duration' => 5];

        $kasus = [
            'Fonnte' => [
                static fn (MockBackend $b): Fonnte => new Fonnte(['token' => 'device-tok'], $b->executor()),
                ['status' => true],
                'https://api.fonnte.com/typing',
            ],
            'OpenWA' => [
                static fn (MockBackend $b): OpenWA => new OpenWA(
                    ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
                    $b->executor()
                ),
                ['success' => true],
                self::BASE . '/api/sessions/sess-1/chats/typing',
            ],
            'ApiMe' => [
                static fn (MockBackend $b): ApiMe => new ApiMe(
                    ['token' => 'inst-token', 'url' => self::BASE, 'instance' => 'uuid-9'],
                    $b->executor()
                ),
                ['status' => 'ok'],
                self::BASE . '/api/instances/uuid-9/whatsapp/presence',
            ],
            'EvolutionAPI' => [
                static fn (MockBackend $b): EvolutionAPI => new EvolutionAPI(
                    ['token' => 'global-key', 'url' => self::BASE, 'instance' => 'sikuwa'],
                    $b->executor()
                ),
                ['presence' => 'composing'],
                self::BASE . '/chat/sendPresence/sikuwa',
            ],
            'Wuzapi' => [
                static fn (MockBackend $b): Wuzapi => new Wuzapi(
                    ['token' => 'user-token', 'url' => self::BASE],
                    $b->executor()
                ),
                ['success' => true],
                self::BASE . '/chat/presence',
            ],
        ];

        foreach ($kasus as $nama => [$buat, $balasan, $url]) {
            $backend = new MockBackend([MockBackend::json($balasan)]);
            $hasil = $buat($backend)->sendTyping($panggilan);

            self::assertStringStartsWith('Sukses', $hasil, "{$nama} tidak melaporkan sukses");
            self::assertSame(
                $url,
                (string) $backend->lastRequest()?->getUri(),
                "{$nama} menembak URL yang salah"
            );
        }
    }

    public function testGatewayAliasIsAcceptedThroughThePublicMethod(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        // "stop" adalah istilah Fonnte; di sini harus menjadi "paused" OpenWA.
        $this->openWa($backend)->sendTyping([
            'destination' => '08123456789',
            'state' => 'stop',
        ]);

        self::assertSame('paused', $backend->lastJson()['state']);
    }

    public function testMissingDestinationIsRejected(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kunci 'destination'");

        $this->openWa($backend)->sendTyping(['duration' => 5]);
    }

    /**
     * Durasi ditolak sebelum request apa pun disusun — bukan sesudah gateway
     * menjawab, karena jawabannya akan tampak seperti kegagalan pengiriman.
     */
    public function testMissingDurationIsRejectedBeforeAnyRequest(): void
    {
        $backend = new MockBackend();

        try {
            $this->wuzapi($backend)->sendTyping(['destination' => '08123456789']);
            self::fail('Indikator tanpa durasi seharusnya ditolak');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("kunci 'duration'", $e->getMessage());
        }

        self::assertSame(0, $backend->count());
    }

    public function testClientDelegatesTyping(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true])]);

        $client = new Client([
            'provider' => 'OpenWA',
            'token' => 'k',
            'url' => self::BASE,
            'session' => 'sess-1',
        ], $backend->client());

        $hasil = $client->sendTyping([
            'destination' => '08123456789',
            'duration' => 5,
        ]);

        self::assertSame('Sukses, indikator sedang mengetik dikirim ke 628123456789@c.us', $hasil);
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/chats/typing',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    // ---------------------------------------------------------------------
    // Penolong
    // ---------------------------------------------------------------------

    private function openWa(MockBackend $backend): OpenWA
    {
        return new OpenWA(
            ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
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

    private function fonnte(MockBackend $backend): Fonnte
    {
        return new Fonnte(['token' => 'device-tok'], $backend->executor());
    }
}
