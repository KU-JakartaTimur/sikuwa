<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;

/**
 * Indikator "sedang mengetik" yang dimunculkan SDK sendiri sebelum mengirim.
 *
 * Beda dari TypingTest yang menguji `sendTyping()` eksplisit, di sini yang
 * diperiksa adalah jalur kirim biasa: apakah `sendMessage()` menyisipkan
 * indikator, dengan lama yang mengikuti panjang pesan, dan — yang paling
 * penting — apakah ia benar-benar diam selama fiturnya belum dinyalakan.
 *
 * Tidak ada detik sungguhan yang ditunggu: AbstractProvider::useSleeper()
 * mencatat berapa detik yang diminta, jadi urutannya bisa diperiksa persis.
 *
 * Dua keputusan yang dijaga ketat di sini:
 *
 * - Evolution API menahan dan membersihkan indikatornya sendiri, jadi SDK
 *   tidak boleh menunggu dua kali;
 * - Fonnte dan OpenWA mengirim seluruh batch dalam satu request, jadi
 *   indikatornya hanya dimunculkan untuk tujuan pesan pertama.
 */
final class AutoTypingTest extends TestCase
{
    private const BASE = 'https://gw.test';

    /** @var array<int,int> Detik yang diminta, berurutan. */
    private array $jeda = [];

    protected function tearDown(): void
    {
        Config::useResolver(null);
        AbstractProvider::useSleeper(null);
    }

    // ---------------------------------------------------------------------
    // Bawaannya mati
    // ---------------------------------------------------------------------

    /**
     * Janji utama fitur ini: selama tidak dinyalakan, jalur kirim berjalan
     * persis seperti sebelumnya — satu request, tanpa jeda tambahan.
     */
    public function testOnlyTheMessageIsSentWhileTheFeatureIsOff(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'a']])]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage(['destination' => '0811', 'message' => 'Halo']);

        self::assertSame('Sukses, messageId: a', $hasil);
        self::assertSame(1, $backend->count());
        self::assertSame(self::BASE . '/chat/send/text', $this->uriAt($backend, 0));
        self::assertSame([], $this->jeda);
    }

    public function testMediaSendsAreNotPrecededByTheIndicator(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'a']])]);
        $this->recordSleeps();

        $this->wuzapi($backend)->sendImage([
            'destination' => '0811',
            'image' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        // Indikator "sedang mengetik" untuk sebuah berkas akan berbohong:
        // yang dikirim bukan ketikan. Berkas lewat begitu saja.
        self::assertSame(1, $backend->count());
        self::assertSame(self::BASE . '/chat/send/image', $this->uriAt($backend, 0));
        self::assertSame([], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Satu pesan
    // ---------------------------------------------------------------------

    public function testTheIndicatorIsShownRightBeforeTheMessage(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'data' => ['Id' => '3EB0']]),
        ]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage(['destination' => '0811', 'message' => 'Halo']);

        self::assertSame('Sukses, messageId: 3EB0', $hasil);
        self::assertSame(2, $backend->count());
        self::assertSame(self::BASE . '/chat/presence', $this->uriAt($backend, 0));
        self::assertSame(self::BASE . '/chat/send/text', $this->uriAt($backend, 1));
        self::assertSame('composing', $this->bodyAt($backend, 0)['State']);
        // 'Halo' hanya empat karakter: ceil(4/15) = 1, dijepit ke batas bawah
        // dua detik supaya indikatornya sempat terlihat.
        self::assertSame([2], $this->jeda);
    }

    public function testALongerMessageGetsALongerIndicator(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = $this->pesanTerkirim();

        $this->recordSleeps();

        $this->wuzapi($backend)->sendMessage([
            'destination' => '0811',
            'message' => str_repeat('a', 150),
        ]);

        // 150 karakter ÷ 15 karakter/detik.
        self::assertSame([10], $this->jeda);
    }

    public function testTheTypingSpeedComesFromTheEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1', 'WHATSAPP_TYPING_SPEED' => '5']);
        $backend = $this->pesanTerkirim();

        $this->recordSleeps();

        $this->wuzapi($backend)->sendMessage([
            'destination' => '0811',
            'message' => str_repeat('a', 50),
        ]);

        // 50 karakter ÷ 5 karakter/detik — bukan 15 seperti bawaan.
        self::assertSame([10], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Evolution API menahan sendiri
    // ---------------------------------------------------------------------

    public function testEvolutionDoesNotWaitTwice(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['presence' => 'composing']),
            MockBackend::json(['key' => ['id' => 'X']]),
        ]);
        $this->recordSleeps();

        $this->evolution($backend)->sendMessage([
            'destination' => '08123456789',
            'message' => str_repeat('a', 150),
        ]);

        self::assertSame(2, $backend->count());
        // Servernya yang menidurkan permintaan, jadi menunggu lagi di klien
        // berarti pemanggil menunggu dua kali lebih lama daripada yang diminta.
        self::assertSame([], $this->jeda);
        // 150 karakter = 10 detik, dan Evolution memakai milidetik.
        self::assertSame(10000, $this->bodyAt($backend, 0)['delay']);
        self::assertSame('composing', $this->bodyAt($backend, 0)['presence']);
    }

    // ---------------------------------------------------------------------
    // Beberapa pesan
    // ---------------------------------------------------------------------

    public function testEachMessageInASequentialSendGetsItsOwnIndicator(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'b']]),
        ]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'Halo'],
            ['destination' => '0822', 'message' => 'Hai'],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $hasil);
        self::assertSame(4, $backend->count());
        self::assertSame([
            self::BASE . '/chat/presence',
            self::BASE . '/chat/send/text',
            self::BASE . '/chat/presence',
            self::BASE . '/chat/send/text',
        ], [
            $this->uriAt($backend, 0),
            $this->uriAt($backend, 1),
            $this->uriAt($backend, 2),
            $this->uriAt($backend, 3),
        ]);
        // Pesan PERTAMA pun dapat indikator — justru itu kasus yang paling
        // sering: satu pesan dikirim sendirian.
        self::assertSame([2, 2], $this->jeda);
    }

    public function testOneMessageInAListCanOptOutOfTheIndicator(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'b']]),
        ]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage([
            ['destination' => '0811', 'message' => 'Halo'],
            ['destination' => '0822', 'message' => 'Hai', 'typing' => ['enabled' => false]],
        ]);

        self::assertSame('Sukses, 2/2 pesan terkirim', $hasil);
        self::assertSame(3, $backend->count());
        // Pesan pertama didahului indikator, pesan kedua tidak.
        self::assertSame(self::BASE . '/chat/presence', $this->uriAt($backend, 0));
        self::assertSame(self::BASE . '/chat/send/text', $this->uriAt($backend, 1));
        self::assertSame(self::BASE . '/chat/send/text', $this->uriAt($backend, 2));
        self::assertSame([2], $this->jeda);
    }

    public function testABatchGatewayAnnouncesOnlyTheFirstDestination(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'totalMessages' => 2, 'batchId' => 'b-1']),
        ]);
        $this->recordSleeps();

        $this->openWa($backend)->sendMessage([
            ['destination' => '08123456789', 'message' => 'Halo'],
            ['destination' => '081298765432', 'message' => 'Hai'],
        ]);

        // Satu request presence, bukan dua. OpenWA mengirim seluruh batch
        // sekaligus, jadi indikator untuk tujuan kedua hanya akan membuat
        // penerimanya menunggu lama tanpa pesan yang menyusul.
        self::assertSame(2, $backend->count());
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/chats/typing',
            $this->uriAt($backend, 0)
        );
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/messages/send-bulk',
            $this->uriAt($backend, 1)
        );
        self::assertSame('628123456789@c.us', $this->bodyAt($backend, 0)['chatId']);
        self::assertSame([2], $this->jeda);
    }

    public function testFonnteAnnouncesTheFirstDestinationBeforeTheBatch(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['status' => true]),
            MockBackend::json(['status' => true]),
        ]);
        $this->recordSleeps();

        $hasil = $this->fonnte($backend)->sendMessage([
            'destination' => '08123456789',
            'message' => 'Halo',
        ]);

        self::assertStringStartsWith('Sukses', $hasil);
        self::assertSame(2, $backend->count());
        self::assertSame('https://api.fonnte.com/typing', $this->uriAt($backend, 0));
        self::assertSame('628123456789', $this->formAt($backend, 0)['target']);
        // Fonnte memakai detik di sini, sama seperti kunci pemanggil.
        self::assertSame('2', $this->formAt($backend, 0)['duration']);
        self::assertSame('https://api.fonnte.com/send', $this->uriAt($backend, 1));
        self::assertSame([2], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Penimpaan per panggilan
    // ---------------------------------------------------------------------

    public function testACallCanTurnTheFeatureOnByItself(): void
    {
        // Environment sengaja tidak diisi: yang menyalakan adalah kuncinya.
        $backend = $this->pesanTerkirim();
        $this->recordSleeps();

        $this->wuzapi($backend)->sendMessage([
            'destination' => '0811',
            'message' => 'Halo',
            'typing' => ['speed' => 10],
        ]);

        self::assertSame(2, $backend->count());
        self::assertSame(self::BASE . '/chat/presence', $this->uriAt($backend, 0));
        self::assertSame([2], $this->jeda);
    }

    public function testACallCanTurnTheFeatureOffByItself(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'a']])]);
        $this->recordSleeps();

        $this->wuzapi($backend)->sendMessage([
            'destination' => '0811',
            'message' => 'Halo',
            'typing' => ['enabled' => false],
        ]);

        self::assertSame(1, $backend->count());
        self::assertSame([], $this->jeda);
    }

    public function testATypingOverrideThatIsNotAnArrayIsRejectedBeforeAnyRequest(): void
    {
        $backend = new MockBackend();

        try {
            $this->wuzapi($backend)->sendMessage([
                'destination' => '0811',
                'message' => 'Halo',
                'typing' => 'yes',
            ]);
            self::fail("Kunci 'typing' yang bukan array seharusnya ditolak");
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("kunci 'typing' harus berupa array", $e->getMessage());
        }

        self::assertSame(0, $backend->count());
    }

    // ---------------------------------------------------------------------
    // Indikator yang gagal tidak boleh menggagalkan pesannya
    // ---------------------------------------------------------------------

    public function testAFailingIndicatorDoesNotBlockTheMessage(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::json(['code' => 500, 'error' => 'sesi tidak terhubung', 'success' => false], 500),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
        ]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage(['destination' => '0811', 'message' => 'Halo']);

        self::assertSame('Sukses, messageId: a', $hasil);
        self::assertSame(2, $backend->count());
        // Indikator yang gagal tidak boleh menambah jeda yang tidak dipakai.
        self::assertSame([], $this->jeda);
    }

    public function testAnIndicatorThatTimesOutDoesNotBlockTheMessage(): void
    {
        self::fakeEnv(['WHATSAPP_TYPING' => '1']);
        $backend = new MockBackend([
            MockBackend::timeout(),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
        ]);
        $this->recordSleeps();

        $hasil = $this->wuzapi($backend)->sendMessage(['destination' => '0811', 'message' => 'Halo']);

        self::assertSame('Sukses, messageId: a', $hasil);
        self::assertSame([], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Lewat Client
    // ---------------------------------------------------------------------

    public function testClientAcceptsTypingOptions(): void
    {
        $backend = $this->pesanTerkirim();
        $this->recordSleeps();

        $client = new Client([
            'provider' => 'Wuzapi',
            'token' => 'user-token',
            'url' => self::BASE,
            'typing' => ['enabled' => true, 'speed' => 5],
        ], $backend->client());

        $client->send(['destination' => '0811', 'message' => str_repeat('a', 50)]);

        self::assertSame(2, $backend->count());
        self::assertSame([10], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Penolong
    // ---------------------------------------------------------------------

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    private function recordSleeps(): void
    {
        $this->jeda = [];
        AbstractProvider::useSleeper(function (int $seconds): void {
            $this->jeda[] = $seconds;
        });
    }

    /** Backend yang siap menerima satu indikator lalu satu pesan. */
    private function pesanTerkirim(): MockBackend
    {
        return new MockBackend([
            MockBackend::json(['success' => true]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
        ]);
    }

    private function uriAt(MockBackend $backend, int $index): string
    {
        return (string) $backend->history[$index]['request']->getUri();
    }

    /** @return array<string,mixed> */
    private function bodyAt(MockBackend $backend, int $index): array
    {
        return json_decode((string) $backend->history[$index]['request']->getBody(), true) ?? [];
    }

    /** @return array<string,string> */
    private function formAt(MockBackend $backend, int $index): array
    {
        $form = [];
        parse_str((string) $backend->history[$index]['request']->getBody(), $form);

        /** @var array<string,string> $form */
        return $form;
    }

    private function wuzapi(MockBackend $backend): Wuzapi
    {
        return new Wuzapi(['token' => 'user-token', 'url' => self::BASE], $backend->executor());
    }

    private function openWa(MockBackend $backend): OpenWA
    {
        return new OpenWA(
            ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
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

    private function fonnte(MockBackend $backend): Fonnte
    {
        return new Fonnte(['token' => 'device-tok'], $backend->executor());
    }
}
