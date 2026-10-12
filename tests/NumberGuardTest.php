<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;

/**
 * Penyaring tujuan di jalur kirim teks: setelan `check_numbers`.
 *
 * Yang diuji di sini bukan endpoint pemeriksanya — itu urusan
 * {@see CheckNumberTest} — melainkan perilaku jalur kirim saat penyaringnya
 * hidup: kapan request pemeriksaan muncul, apa yang terjadi pada tujuan yang
 * tidak terdaftar, dan apa yang dilaporkan ke pemanggil.
 *
 * Tiga janji yang paling penting untuk dijaga:
 *
 * 1. **Mati berarti tidak berubah sama sekali.** Selama `check_numbers` tidak
 *    dinyalakan, tidak ada satu pun request pemeriksaan yang muncul — kalau
 *    tidak, setiap pemanggil lama tiba-tiba mengirim dua kali lipat traffic.
 * 2. **Gagal berarti tetap kirim.** Pemeriksaan yang timeout tidak boleh
 *    menahan pesan; itu sebabnya `guardNumbers()` sengaja menelan error.
 * 3. **Hanya yang dipastikan tidak ada yang dibuang.** Nomor yang tidak
 *    disebut gateway berarti "belum diperiksa", bukan "tidak terdaftar".
 */
final class NumberGuardTest extends TestCase
{
    private const BASE = 'https://gw.test';
    private const SESSION = 'sess-1';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // Saklar mati — jalur kirim tidak boleh tersentuh
    // ---------------------------------------------------------------------

    /**
     * Tanpa `check_numbers`, satu pesan tetap satu request. Ini penjaga
     * regresi untuk seluruh pemanggil yang tidak pernah meminta fitur ini.
     */
    public function testFeatureOffSendsWithoutAnyCheckRequest(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'M1'])]);

        $result = $this->openWa($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: M1', $result);
        self::assertSame(1, $backend->count());
        self::assertStringContainsString(
            '/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    /** Pilihan tegas `check_numbers => false` menang atas environment. */
    public function testExplicitFalseOverridesEnabledEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_CHECK_NUMBERS' => 'true']);

        $backend = new MockBackend([MockBackend::json(['messageId' => 'M1'])]);

        $result = $this->openWa($backend, ['check_numbers' => false])->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: M1', $result);
        self::assertSame(1, $backend->count());
    }

    /** Saklar yang sama juga dibaca dari `WHATSAPP_CHECK_NUMBERS`. */
    public function testEnvironmentTurnsTheGuardOn(): void
    {
        self::fakeEnv(['WHATSAPP_CHECK_NUMBERS' => 'true']);

        $backend = new MockBackend([
            MockBackend::json(['exists' => true]),
            MockBackend::json(['messageId' => 'M1']),
        ]);

        $this->openWa($backend)->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame(2, $backend->count());
        self::assertStringContainsString(
            '/contacts/check/6281234567890',
            (string) $backend->history[0]['request']->getUri()
        );
    }

    // ---------------------------------------------------------------------
    // Saklar hidup — periksa dulu, baru kirim
    // ---------------------------------------------------------------------

    /**
     * Pemeriksaan dikirim **sebelum** pengiriman, dan hasilnya terlihat dari
     * urutan request di backend: cek lebih dulu, baru send-text.
     */
    public function testCheckRunsBeforeSendWhenEnabled(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['exists' => true]),
            MockBackend::json(['messageId' => 'M2']),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: M2', $result);
        self::assertSame(2, $backend->count());

        self::assertStringContainsString(
            '/contacts/check/6281234567890',
            (string) $backend->history[0]['request']->getUri()
        );
        self::assertStringContainsString(
            '/messages/send-text',
            (string) $backend->history[1]['request']->getUri()
        );
    }

    /**
     * Tujuan yang dipastikan tidak terdaftar dibuang, sisanya tetap dikirim,
     * dan jumlah yang dilewati dilaporkan di hasilnya.
     */
    public function testUnregisteredDestinationIsSkippedAndReported(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['exists' => true]),   // 081234567890 — dikirim
            MockBackend::json(['exists' => false]),  // 089876543210 — dibuang
            MockBackend::json(['messageId' => 'M3']),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage([
            ['destination' => '081234567890', 'message' => 'a'],
            ['destination' => '089876543210', 'message' => 'b'],
        ]);

        // Dua pemeriksaan (satu per nomor) lalu satu pengiriman untuk sisa satu.
        self::assertSame(3, $backend->count());

        // Yang benar-benar terkirim hanya nomor yang terdaftar.
        self::assertSame(
            ['chatId' => '6281234567890@c.us', 'text' => 'a'],
            $backend->lastJson()
        );

        self::assertSame(
            'Sukses, messageId: M3 (1 tujuan dilewati karena tidak terdaftar: 089876543210)',
            $result
        );
    }

    /**
     * Bila semuanya tidak terdaftar, tidak ada request pengiriman sama sekali
     * — hanya pemeriksaan yang jalan.
     */
    public function testAllUnregisteredSendsNothing(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['exists' => false]),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage([
            'destination' => '089876543210',
            'message' => 'halo',
        ]);

        self::assertSame(1, $backend->count());
        self::assertSame(
            'Tidak ada pesan terkirim: 1 tujuan dilewati karena tidak terdaftar di WhatsApp',
            $result
        );
    }

    /** Tujuan yang terverifikasi **tidak** boleh ikut dibuang. */
    public function testVerifiedDestinationIsStillDelivered(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['exists' => true]),
            MockBackend::json(['messageId' => 'M4']),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage([
            'destination' => '081234567890',
            'message' => 'halo',
        ]);

        self::assertSame('Sukses, messageId: M4', $result);
        self::assertSame(
            ['chatId' => '6281234567890@c.us', 'text' => 'halo'],
            $backend->lastJson()
        );
    }

    // ---------------------------------------------------------------------
    // Gagal berarti tetap kirim
    // ---------------------------------------------------------------------

    /**
     * Pemeriksaan yang timeout tidak boleh menahan pesan. Yang tersisa adalah
     * catatan peringatan ke logger, bukan exception.
     */
    public function testFailedCheckIsIgnoredAndMessageStillSent(): void
    {
        $logger = new GuardLogger();

        $backend = new MockBackend([
            MockBackend::timeout(),
            MockBackend::json(['messageId' => 'M5']),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true, 'logger' => $logger])
            ->sendMessage(['destination' => '081234567890', 'message' => 'halo']);

        self::assertSame('Sukses, messageId: M5', $result);
        self::assertSame(2, $backend->count());

        self::assertNotEmpty($logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('Pemeriksaan nomor sebelum kirim gagal', $logger->records[0]['message']);
    }

    /**
     * Kasus yang paling sering: endpoint cek OpenWA menuntut kunci berperan
     * operator, jadi akun berkunci baca ditolak HTTP 403. Penolakan itu tidak
     * boleh mematikan pengiriman — kirimnya sendiri baik-baik saja.
     */
    public function testForbiddenCheckStillDeliversMessage(): void
    {
        $logger = new GuardLogger();

        $backend = new MockBackend([
            MockBackend::json(['error' => 'forbidden'], 403),
            MockBackend::json(['messageId' => 'M9']),
        ]);

        $result = $this->openWa($backend, ['check_numbers' => true, 'logger' => $logger])
            ->sendMessage(['destination' => '081234567890', 'message' => 'halo']);

        self::assertSame('Sukses, messageId: M9', $result);
        self::assertSame(2, $backend->count());
        self::assertStringContainsString(
            '/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertNotEmpty($logger->records);
    }

    // ---------------------------------------------------------------------
    // Yang tidak bisa diperiksa tidak diperiksa
    // ---------------------------------------------------------------------

    /**
     * Tujuan berbentuk JID (grup) tidak punya padanan nomor, jadi tidak ikut
     * diperiksa — dan karena tidak ada satu pun yang bisa diperiksa, tidak ada
     * request pemeriksaan sama sekali.
     */
    public function testGroupDestinationIsNotChecked(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'M6'])]);

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage([
            'destination' => '120363000000000000@g.us',
            'message' => 'halo grup',
        ]);

        self::assertSame('Sukses, messageId: M6', $result);
        self::assertSame(1, $backend->count());
        self::assertStringContainsString(
            '/messages/send-text',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    // ---------------------------------------------------------------------
    // Bentuk laporan
    // ---------------------------------------------------------------------

    /**
     * Daftar contoh dipotong: satu batch besar tidak boleh membanjiri hasil
     * dengan ratusan nomor. Yang tampil cukup lima, sisanya diringkas.
     */
    public function testSkippedNoteCapsTheExampleList(): void
    {
        $responses = [];

        for ($i = 1; $i <= 6; $i++) {
            $responses[] = MockBackend::json(['exists' => false]);
        }

        $responses[] = MockBackend::json(['exists' => true]);
        $responses[] = MockBackend::json(['messageId' => 'M7']);

        $backend = new MockBackend($responses);

        $messages = [];

        for ($i = 1; $i <= 6; $i++) {
            $messages[] = ['destination' => sprintf('08900000000%d', $i), 'message' => 'x'];
        }

        $messages[] = ['destination' => '081234567890', 'message' => 'y'];

        $result = $this->openWa($backend, ['check_numbers' => true])->sendMessage($messages);

        self::assertSame(
            'Sukses, messageId: M7 (6 tujuan dilewati karena tidak terdaftar: '
            . '089000000001, 089000000002, 089000000003, 089000000004, 089000000005'
            . ' dan 1 lainnya)',
            $result
        );
    }

    /** Melewati tujuan dicatat ke logger, supaya penyaringan tidak senyap. */
    public function testSkippingIsLoggedAsWarning(): void
    {
        $logger = new GuardLogger();

        $backend = new MockBackend([
            MockBackend::json(['exists' => false]),  // 089876543210 — dibuang
            MockBackend::json(['exists' => true]),   // 081234567890 — dikirim
            MockBackend::json(['messageId' => 'M8']),
        ]);

        $this->openWa($backend, ['check_numbers' => true, 'logger' => $logger])->sendMessage([
            ['destination' => '089876543210', 'message' => 'a'],
            ['destination' => '081234567890', 'message' => 'b'],
        ]);

        self::assertNotEmpty($logger->records);

        $terakhir = $logger->records[array_key_last($logger->records)];

        self::assertSame('warning', $terakhir['level']);
        self::assertStringContainsString('tidak terdaftar dilewati', $terakhir['message']);
        self::assertSame(1, $terakhir['context']['dilewati'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Titik panggil kedua: Fonnte
    // ---------------------------------------------------------------------

    /**
     * Fonnte memanggil `plan()` sendiri, bukan lewat `sendIndividually()`,
     * jadi penyaringnya harus terpasang di sana juga. Fonnte memeriksa seluruh
     * daftar dalam satu request, lalu mengirim sisanya dalam satu request.
     */
    public function testFonnteGuardsBeforeSending(): void
    {
        $backend = new MockBackend([
            MockBackend::json([
                'status' => true,
                'registered' => ['6281234567890'],
                'not_registered' => ['6289876543210'],
            ]),
            MockBackend::json(['status' => true, 'detail' => '1/1']),
        ]);

        $result = (new Fonnte(
            ['token' => 'tok', 'check_numbers' => true],
            $backend->executor()
        ))->sendMessage([
            ['destination' => '081234567890', 'message' => 'a'],
            ['destination' => '089876543210', 'message' => 'b'],
        ]);

        self::assertSame(2, $backend->count());
        self::assertSame(
            'https://api.fonnte.com/validate',
            (string) $backend->history[0]['request']->getUri()
        );
        self::assertSame(
            'https://api.fonnte.com/send',
            (string) $backend->history[1]['request']->getUri()
        );

        // Body kirim hanya memuat satu target — yang terdaftar.
        $terkirim = json_decode($backend->lastForm()['data'] ?? '[]', true);

        self::assertCount(1, $terkirim);
        self::assertSame('081234567890', $terkirim[0]['target']);

        self::assertStringContainsString('1 tujuan dilewati', $result);
    }

    // ---------------------------------------------------------------------

    /** @param array<string,string> $values */
    private static function fakeEnv(array $values): void
    {
        Config::useResolver(static fn (string $key): ?string => $values[$key] ?? null);
    }

    /** @param array<string,mixed> $options */
    private function openWa(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            array_merge(['token' => 'k', 'url' => self::BASE, 'session' => self::SESSION], $options),
            $backend->executor()
        );
    }
}

/**
 * Logger yang merekam semuanya, untuk diperiksa test.
 *
 * Dipakai untuk memastikan penyaringan tujuan tidak berjalan senyap: baik
 * pemeriksaan yang gagal maupun tujuan yang dilewati sama-sama harus tercatat.
 */
final class GuardLogger extends AbstractLogger
{
    /** @var array<int,array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
