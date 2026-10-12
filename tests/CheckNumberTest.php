<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\NumberCheck;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Waxum\Waxum;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Providers\Wwebjs\Wwebjs;

/**
 * `checkNumbers()` di tujuh gateway, plus bentuk hasilnya.
 *
 * Yang diperiksa bukan cuma jawabannya, tapi juga request yang benar-benar
 * dikirim — method, URL, header, dan body — karena itulah bagian yang paling
 * mudah rusak diam-diam saat provider dirapikan. Perbedaan yang paling
 * menentukan di sini adalah berapa nomor yang bisa diperiksa sekali panggil:
 * empat gateway menerima daftar, tiga sisanya satu nomor per request.
 */
final class CheckNumberTest extends TestCase
{
    private const BASE = 'https://gw.test';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // Fonnte — satu panggilan untuk seluruh daftar, kolom dipisah koma
    // ---------------------------------------------------------------------

    public function testFonnteChecksAllNumbersInOneCall(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'status' => true,
            'registered' => ['6281234567890'],
            'not_registered' => ['6289876543210'],
        ])]);

        $hasil = (new Fonnte(['token' => 'device-tok'], $backend->executor()))
            ->checkNumbers(['081234567890', '089876543210']);

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/validate', (string) $backend->lastRequest()?->getUri());
        // /validate memakai token perangkat, sama seperti /qr dan /typing.
        self::assertSame('device-tok', $backend->lastHeader('Authorization'));

        // Nomornya dinormalkan lebih dulu, jadi yang terkirim sudah berbentuk
        // internasional tanpa tanda plus.
        self::assertSame('6281234567890,6289876543210', $backend->lastForm()['target']);

        self::assertSame('Fonnte', $hasil->provider);
        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
        self::assertSame(['6281234567890'], $hasil->registered());
        self::assertSame(['6289876543210'], $hasil->unregistered());
    }

    /** Fonnte menolak lebih dari 500 nomor sekaligus — seluruh panggilannya. */
    public function testFonnteSplitsListsOverItsLimit(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['status' => true, 'registered' => [], 'not_registered' => []]),
            MockBackend::json(['status' => true, 'registered' => [], 'not_registered' => []]),
        ]);

        $numbers = [];

        for ($i = 1; $i <= 501; $i++) {
            $numbers[] = sprintf('0812%07d', $i);
        }

        (new Fonnte(['token' => 't'], $backend->executor()))->checkNumbers($numbers);

        self::assertSame(2, $backend->count());

        // Potongan pertama berisi tepat 500 nomor: 499 pemisah. Isinya dibaca
        // lewat parse_str karena form-encoding menulis koma sebagai `%2C`.
        $form = [];
        parse_str((string) $backend->history[0]['request']->getBody(), $form);

        self::assertSame(499, substr_count($form['target'] ?? '', ','));
    }

    public function testFonnteRejectionEnvelopeStillThrows(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => false, 'reason' => 'device disconnected'])]);

        $this->expectExceptionMessage('device disconnected');

        (new Fonnte(['token' => 't'], $backend->executor()))->checkNumbers('081234567890');
    }

    // ---------------------------------------------------------------------
    // OpenWA — satu nomor per panggilan
    // ---------------------------------------------------------------------

    public function testOpenWaChecksEachNumberSeparately(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['number' => '6281234567890', 'exists' => true, 'whatsappId' => '6281234567890@c.us']),
            MockBackend::json(['number' => '6289876543210', 'exists' => false, 'whatsappId' => null]),
        ]);

        $hasil = $this->openWa($backend)->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(2, $backend->count());
        self::assertSame('GET', $backend->lastRequest()?->getMethod());
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/contacts/check/6289876543210',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('k', $backend->lastHeader('X-API-Key'));

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
        self::assertSame('6281234567890@c.us', $hasil->whatsappId('081234567890'));
        // Gateway yang tidak menyebut id WhatsApp membiarkannya kosong.
        self::assertSame('', $hasil->whatsappId('089876543210'));
    }

    public function testOpenWaAcceptsWrappedEnvelope(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['number' => '6281234567890', 'exists' => true]]),
        ]);

        $hasil = $this->openWa($backend)->checkNumbers('081234567890');

        self::assertTrue($hasil->isRegistered('081234567890'));
    }

    public function testOpenWaCheckWithoutSessionIdIsRejected(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WHATSAPP_SESSION belum diisi di .env');

        (new OpenWA(['token' => 'k', 'url' => self::BASE], $backend->executor()))->checkNumbers('081234567890');
    }

    // ---------------------------------------------------------------------
    // ApiMe — satu nomor per panggilan, jawaban bersarang di data.results
    // ---------------------------------------------------------------------

    public function testApiMeReadsNestedResultEntry(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'data' => ['results' => [[
                'Query' => '+6281234567890',
                'JID' => '6281234567890@s.whatsapp.net',
                'IsIn' => true,
            ]]],
        ])]);

        $hasil = (new ApiMe(
            ['token' => 'inst-token', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        ))->checkNumbers('081234567890');

        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame(
            self::BASE . '/api/instances/uuid-9/whatsapp/check',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer inst-token', $backend->lastHeader('Authorization'));
        self::assertSame(['phone' => '6281234567890'], $backend->lastJson());

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertSame('6281234567890@s.whatsapp.net', $hasil->whatsappId('081234567890'));
    }

    // ---------------------------------------------------------------------
    // Evolution API — satu panggilan untuk seluruh daftar
    // ---------------------------------------------------------------------

    public function testEvolutionChecksWholeListAtOnce(): void
    {
        $backend = new MockBackend([MockBackend::json([
            ['jid' => '6281234567890@s.whatsapp.net', 'exists' => true, 'number' => '6281234567890'],
            ['jid' => '6289876543210@s.whatsapp.net', 'exists' => false, 'number' => '6289876543210'],
        ])]);

        $hasil = (new EvolutionAPI(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        ))->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(1, $backend->count());
        self::assertSame(
            self::BASE . '/chat/whatsappNumbers/sikuwa',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('t', $backend->lastHeader('apikey'));
        self::assertSame(['numbers' => ['6281234567890', '6289876543210']], $backend->lastJson());

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
        self::assertSame('6281234567890@s.whatsapp.net', $hasil->whatsappId('081234567890'));
    }

    /** Entri tanpa kolom `number` tetap terbaca lewat JID-nya. */
    public function testEvolutionFallsBackToJidWhenNumberIsMissing(): void
    {
        $backend = new MockBackend([MockBackend::json([
            ['jid' => '6281234567890@s.whatsapp.net', 'exists' => true],
        ])]);

        $hasil = (new EvolutionAPI(
            ['token' => 't', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        ))->checkNumbers('081234567890');

        self::assertTrue($hasil->isRegistered('081234567890'));
    }

    // ---------------------------------------------------------------------
    // wuzapi — satu panggilan, jawaban di data.Users
    // ---------------------------------------------------------------------

    public function testWuzapiChecksWholeListAndReadsUsersArray(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'code' => 200,
            'success' => true,
            'data' => ['Users' => [
                [
                    'IsInWhatsapp' => true,
                    'JID' => '6281234567890@s.whatsapp.net',
                    'Query' => '6281234567890',
                ],
                [
                    'IsInWhatsapp' => false,
                    'JID' => '6289876543210@s.whatsapp.net',
                    'Query' => '6289876543210',
                ],
            ]],
        ])]);

        $hasil = (new Wuzapi(['token' => 'user-token', 'url' => self::BASE], $backend->executor()))
            ->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(self::BASE . '/user/check', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));
        self::assertSame(['Phone' => ['6281234567890', '6289876543210']], $backend->lastJson());

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
    }

    /** wuzapi membalas HTTP 200 walau menolak; amplopnya yang menentukan. */
    public function testWuzapiFailureEnvelopeIsRejected(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['code' => 401, 'success' => false, 'error' => 'Invalid token']),
        ]);

        $this->expectExceptionMessage('Invalid token');

        (new Wuzapi(['token' => 't', 'url' => self::BASE], $backend->executor()))->checkNumbers('081234567890');
    }

    // ---------------------------------------------------------------------
    // Wwebjs — satu nomor per panggilan
    // ---------------------------------------------------------------------

    public function testWwebjsChecksEachNumberSeparately(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'result' => true]),
            MockBackend::json(['success' => true, 'result' => false]),
        ]);

        $hasil = (new Wwebjs(
            ['token' => 't', 'url' => self::BASE, 'session' => 'sess-a'],
            $backend->executor()
        ))->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(2, $backend->count());
        self::assertSame(
            self::BASE . '/client/isRegisteredUser/sess-a',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('t', $backend->lastHeader('x-api-key'));
        self::assertSame(['number' => '6289876543210'], $backend->lastJson());

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
    }

    // ---------------------------------------------------------------------
    // Waxum — satu panggilan untuk seluruh daftar
    // ---------------------------------------------------------------------

    public function testWaxumChecksWholeListAtOnce(): void
    {
        $backend = new MockBackend([MockBackend::json([
            'results' => [
                ['phone' => '6281234567890', 'exists' => true, 'jid' => '6281234567890@s.whatsapp.net'],
                ['phone' => '6289876543210', 'exists' => false, 'jid' => null],
            ],
        ])]);

        $hasil = (new Waxum(
            ['token' => 't', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->executor()
        ))->checkNumbers(['081234567890', '089876543210']);

        self::assertSame(1, $backend->count());
        self::assertSame(
            self::BASE . '/api/v1/sessions/sess-1/contacts/check',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer t', $backend->lastHeader('Authorization'));
        self::assertSame(['phones' => ['6281234567890', '6289876543210']], $backend->lastJson());

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertFalse($hasil->isRegistered('089876543210'));
        self::assertSame('6281234567890@s.whatsapp.net', $hasil->whatsappId('081234567890'));
    }

    // ---------------------------------------------------------------------
    // Aturan yang berlaku di semua gateway
    // ---------------------------------------------------------------------

    /** Memeriksa nomor yang sama dua kali hanya memakan traffic tambahan. */
    public function testDuplicateNumbersAreCheckedOnce(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['number' => '6281234567890', 'exists' => true]),
        ]);

        $this->openWa($backend)->checkNumbers(['081234567890', '6281234567890', '+62 812-3456-7890']);

        self::assertSame(1, $backend->count());
    }

    public function testEmptyInputIsRejectedBeforeAnyRequest(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sertakan minimal satu nomor');

        $this->openWa($backend)->checkNumbers([]);
    }

    public function testUnreadableNumberIsRejectedBeforeAnyRequest(): void
    {
        $backend = new MockBackend([]);

        $this->expectException(ConfigurationException::class);

        $this->openWa($backend)->checkNumbers('bukan nomor');
    }

    public function testClientDelegatesCheckNumbersToProvider(): void
    {
        $backend = new MockBackend([
            MockBackend::json(['number' => '6281234567890', 'exists' => true]),
        ]);

        $hasil = (new Client(
            ['provider' => 'OpenWA', 'token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->client()
        ))->checkNumbers('081234567890');

        self::assertSame('OpenWA', $hasil->provider);
        self::assertTrue($hasil->isRegistered('081234567890'));
    }

    // ---------------------------------------------------------------------
    // NumberCheck — value object-nya sendiri
    // ---------------------------------------------------------------------

    public function testIsRegisteredNormalisesWhateverFormCallerUses(): void
    {
        $hasil = new NumberCheck('OpenWA', ['6281234567890' => true]);

        self::assertTrue($hasil->isRegistered('081234567890'));
        self::assertTrue($hasil->isRegistered('+62 812-3456-7890'));
        self::assertTrue($hasil->isRegistered('6281234567890'));
        // Nomor yang tidak diperiksa bukan berarti tidak terdaftar — ia
        // memang tidak punya jawaban, dan itu dijawab false.
        self::assertFalse($hasil->isRegistered('081200000000'));
    }

    /**
     * Kunci array yang berbentuk angka disimpan PHP sebagai integer, jadi
     * daftar nomornya harus dikembalikan sebagai string — kalau tidak,
     * perbandingan ketat dengan nomor dari pemanggil gagal tanpa sebab.
     */
    public function testRegisteredReturnsNumbersAsStrings(): void
    {
        $hasil = new NumberCheck('OpenWA', [
            '6281234567890' => true,
            '6289876543210' => false,
        ]);

        self::assertSame(['6281234567890'], $hasil->registered());
        self::assertSame(['6289876543210'], $hasil->unregistered());
        self::assertFalse($hasil->isEmpty());
        self::assertSame('OpenWA: 1 terdaftar, 1 tidak terdaftar', (string) $hasil);
    }

    public function testEmptyResultIsReportedAsEmpty(): void
    {
        $hasil = new NumberCheck('Wuzapi');

        self::assertTrue($hasil->isEmpty());
        self::assertSame([], $hasil->registered());
        self::assertSame([], $hasil->unregistered());
        self::assertSame('{}', $hasil->toJson());
    }

    /** `raw` dan `ids` tidak ikut ke `toArray()`: keduanya bisa besar. */
    public function testSerialisationKeepsOnlyTheAnswers(): void
    {
        $hasil = new NumberCheck(
            'Waxum',
            ['6281234567890' => true],
            ['6281234567890' => '6281234567890@s.whatsapp.net'],
            ['results' => [['phone' => '6281234567890', 'exists' => true]]],
        );

        self::assertSame(['6281234567890' => true], $hasil->toArray());
        self::assertSame('{"6281234567890":true}', $hasil->toJson());
    }

    /** @param array<string,mixed> $options */
    private function openWa(MockBackend $backend, array $options = []): OpenWA
    {
        return new OpenWA(
            array_merge(['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'], $options),
            $backend->executor()
        );
    }
}
