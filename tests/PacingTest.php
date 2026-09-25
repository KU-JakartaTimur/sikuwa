<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;
use Sikuwa\Whatsapp\Support\Pacing;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Jeda antar pesan: siklus yang bergiliran, jitter acak, dan penimpaan per
 * panggilan.
 *
 * Tidak ada jeda sungguhan yang ditunggu di sini. AbstractProvider::useSleeper()
 * mencatat berapa detik yang diminta, jadi urutannya bisa diperiksa persis —
 * dan suite tetap selesai dalam sekejap meski jedanya puluhan detik.
 */
final class PacingTest extends TestCase
{
    /** @var array<int,int> Detik yang diminta, berurutan. */
    private array $jeda = [];

    protected function tearDown(): void
    {
        Config::useResolver(null);
        AbstractProvider::useSleeper(null);
    }

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

    /** @param array<int,array<string,mixed>> $items */
    private function apiMe(MockBackend $backend, array $items, array $options = []): string
    {
        return (new ApiMe(
            array_merge(['token' => 't', 'url' => 'https://v14.test', 'instance' => 'inst'], $options),
            $backend->executor()
        ))->sendMessage($items);
    }

    /** Respons sukses ApiMe, satu per pesan. */
    private static function apiMeBackend(int $count): MockBackend
    {
        return new MockBackend(array_fill(
            0,
            $count,
            MockBackend::json(['data' => ['whatsappId' => 'x']])
        ));
    }

    /** @return array<string,mixed> */
    private static function bodyAt(MockBackend $backend, int $index): array
    {
        return json_decode((string) $backend->history[$index]['request']->getBody(), true) ?? [];
    }

    // ---------------------------------------------------------------------
    // Pacing sebagai nilai
    // ---------------------------------------------------------------------

    public function testDisabledPacingChangesNothing(): void
    {
        $pacing = Pacing::none();

        self::assertFalse($pacing->isEnabled());
        self::assertNull($pacing->delayFor(0));
        self::assertNull($pacing->delayFor(7));
    }

    public function testEmptyConfigValuesMeanDisabled(): void
    {
        self::assertFalse(Pacing::fromConfig(null, null)->isEnabled());
        self::assertFalse(Pacing::fromConfig('', '')->isEnabled());
        self::assertFalse(Pacing::fromConfig('abc', 'xyz')->isEnabled());
    }

    public function testCycleRotates(): void
    {
        $pacing = Pacing::fromConfig('0,30', null);

        self::assertSame([0, 30], $pacing->cycle());
        self::assertSame([0, 30, 0, 30, 0, 30], [
            $pacing->delayFor(0),
            $pacing->delayFor(1),
            $pacing->delayFor(2),
            $pacing->delayFor(3),
            $pacing->delayFor(4),
            $pacing->delayFor(5),
        ]);
    }

    public function testCycleAcceptsWhitespaceAndSkipsJunk(): void
    {
        self::assertSame([0, 30], Pacing::fromConfig(' 0 , 30 ', null)->cycle());
        self::assertSame([5], Pacing::fromConfig([5], null)->cycle());
        self::assertSame([7, 0], Pacing::fromConfig('7, ?, 0', null)->cycle());
    }

    public function testIntervalAloneIsRandomWithinBounds(): void
    {
        $pacing = Pacing::fromConfig(null, '20-30');
        $samples = [];

        for ($i = 0; $i < 200; $i++) {
            $samples[] = $pacing->delayFor(0);
        }

        self::assertSame(['min' => 20, 'max' => 30], $pacing->interval());
        self::assertGreaterThan(20, max($samples), 'jitter harus bervariasi');
        self::assertLessThan(30, min($samples), 'jitter harus bervariasi');
        self::assertGreaterThanOrEqual(20, min($samples));
        self::assertLessThanOrEqual(30, max($samples));
    }

    /**
     * Dua kunci itu dipakai bersamaan: siklus menentukan dasar jedanya, jitter
     * membuatnya tidak bisa ditebak. Kalau salah satu ditafsirkan sebagai
     * pengganti yang lain, salah satu kunci jadi tidak berpengaruh.
     */
    public function testIntervalIsAddedToCycle(): void
    {
        $pacing = Pacing::fromConfig('0,30', '20-30');

        for ($i = 0; $i < 100; $i++) {
            $genap = $pacing->delayFor(0);
            $ganjil = $pacing->delayFor(1);

            self::assertGreaterThanOrEqual(20, $genap);
            self::assertLessThanOrEqual(30, $genap);
            self::assertGreaterThanOrEqual(50, $ganjil);
            self::assertLessThanOrEqual(60, $ganjil);
        }
    }

    public function testSingleNumberIntervalMeansFixedDelay(): void
    {
        self::assertSame(['min' => 25, 'max' => 25], Pacing::fromConfig(null, '25')->interval());
        self::assertSame(25, Pacing::fromConfig(null, '25')->delayFor(3));
    }

    public function testReversedRangeIsNormalised(): void
    {
        self::assertSame(['min' => 20, 'max' => 30], Pacing::fromConfig(null, '30-20')->interval());
    }

    public function testIntervalAcceptsArrayForm(): void
    {
        self::assertSame(['min' => 20, 'max' => 30], Pacing::fromConfig(null, [20, 30])->interval());
        self::assertSame(['min' => 4, 'max' => 4], Pacing::fromConfig(null, [4])->interval());
    }

    public function testDelaysAreClampedToMaximum(): void
    {
        $pacing = Pacing::fromConfig((string) (Pacing::MAX_DELAY * 2), null);

        self::assertSame([Pacing::MAX_DELAY], $pacing->cycle());
        self::assertSame(Pacing::MAX_DELAY, Pacing::fromConfig(null, '9999')->delayFor(0));
    }

    public function testMergeOnlyTouchesMentionedKeys(): void
    {
        $config = Pacing::fromConfig('0,30', '20-30');

        // Interval dimatikan, siklus dari konfigurasi tetap dipakai.
        $onlyCycle = $config->merge(['interval' => null]);
        self::assertSame([0, 30], $onlyCycle->cycle());
        self::assertNull($onlyCycle->interval());
        self::assertSame(30, $onlyCycle->delayFor(1));

        // Siklus diganti, interval dari konfigurasi tetap dipakai.
        $replaced = $config->merge(['cycle' => '5']);
        self::assertSame([5], $replaced->cycle());
        self::assertSame(['min' => 20, 'max' => 30], $replaced->interval());
    }

    public function testMergeWithNothingReturnsSameInstance(): void
    {
        $pacing = Pacing::fromConfig('0,30', null);

        self::assertSame($pacing, $pacing->merge(null));
        self::assertSame($pacing, $pacing->merge([]));
    }

    // ---------------------------------------------------------------------
    // Pesan panjang
    // ---------------------------------------------------------------------

    public function testLongBodyMultipliesTheDelay(): void
    {
        $pacing = Pacing::fromConfig('0,30', null);

        self::assertSame(Pacing::DEFAULT_LONG_CHARS, $pacing->longChars());
        self::assertSame(Pacing::DEFAULT_LONG_FACTOR, $pacing->longFactor());

        // Pesan pendek: siklus apa adanya.
        self::assertSame(0, $pacing->delayFor(0, 100));
        self::assertSame(30, $pacing->delayFor(1, 100));

        // Pesan panjang: siklus dikali pengali.
        self::assertSame(0, $pacing->delayFor(0, 400));
        self::assertSame(90, $pacing->delayFor(1, 400));
    }

    /** Batasnya inklusif: 300 karakter sudah dihitung panjang. */
    public function testLongThresholdBoundary(): void
    {
        $pacing = Pacing::fromConfig('10', null);

        self::assertFalse($pacing->isLong(299));
        self::assertTrue($pacing->isLong(300));
        self::assertSame(10, $pacing->delayFor(0, 299));
        self::assertSame(30, $pacing->delayFor(0, 300));
    }

    public function testLongRuleCanBeSwitchedOff(): void
    {
        // Ambang 0 mematikan aturannya, sepanjang apa pun pesannya.
        $off = Pacing::fromConfig('10', null, 0, 5);
        self::assertFalse($off->isLong(9999));
        self::assertSame(10, $off->delayFor(0, 9999));

        // Pengali 1 berarti tidak mengalikan apa pun.
        $flat = Pacing::fromConfig('10', null, 300, 1);
        self::assertTrue($flat->isLong(500));
        self::assertSame(10, $flat->delayFor(0, 500));
    }

    public function testLongValuesAreConfigurableAndSafeToMistype(): void
    {
        $custom = Pacing::fromConfig('10', null, '50', '2');

        self::assertSame(50, $custom->longChars());
        self::assertSame(2, $custom->longFactor());
        self::assertSame(20, $custom->delayFor(0, 50));

        // Nilai tak terbaca kembali ke bawaan, tidak mematikan pacing-nya.
        $broken = Pacing::fromConfig('10', null, 'abc', 'xyz');

        self::assertSame(Pacing::DEFAULT_LONG_CHARS, $broken->longChars());
        self::assertSame(Pacing::DEFAULT_LONG_FACTOR, $broken->longFactor());
    }

    public function testLongDelayIsStillClampedToMaximum(): void
    {
        $pacing = Pacing::fromConfig((string) Pacing::MAX_DELAY, null);

        self::assertSame(Pacing::MAX_DELAY, $pacing->delayFor(0, 9999));
    }

    public function testMergeHandlesLongKeys(): void
    {
        $pacing = Pacing::fromConfig('10', null)->merge(['long_chars' => 5, 'long_factor' => 2]);

        self::assertSame(5, $pacing->longChars());
        self::assertSame(2, $pacing->longFactor());
        self::assertSame(20, $pacing->delayFor(0, 5));

        // Yang tidak disebutkan tidak disentuh.
        self::assertSame(
            Pacing::DEFAULT_LONG_CHARS,
            Pacing::fromConfig('10', null)->merge(['long_factor' => 2])->longChars()
        );
    }

    public function testLengthCountsCharactersNotBytes(): void
    {
        self::assertSame(3, Text::length('ééé'));
        self::assertSame(6, \strlen('ééé'), 'prasyarat: huruf beraksen lebih dari satu byte');
    }

    // ---------------------------------------------------------------------
    // Konfigurasi
    // ---------------------------------------------------------------------

    public function testConfigReadsPacingFromEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_PACING_CYCLE' => '0,30',
            'WHATSAPP_PACING_INTERVAL' => '20-30',
        ]);

        $pacing = Config::fromEnvironment()->pacing();

        self::assertTrue($pacing->isEnabled());
        self::assertSame([0, 30], $pacing->cycle());
        self::assertSame(['min' => 20, 'max' => 30], $pacing->interval());
    }

    public function testConfigPacingIsOffWhenKeysAreAbsent(): void
    {
        self::fakeEnv([]);

        self::assertFalse((new Config())->pacing()->isEnabled());
    }

    public function testConfigReadsLongKeysFromEnvironment(): void
    {
        self::fakeEnv([
            'WHATSAPP_PACING_CYCLE' => '0,30',
            'WHATSAPP_PACING_LONG_CHARS' => '120',
            'WHATSAPP_PACING_LONG_FACTOR' => '4',
        ]);

        $pacing = Config::fromEnvironment()->pacing();

        self::assertSame(120, $pacing->longChars());
        self::assertSame(4, $pacing->longFactor());
        self::assertFalse($pacing->isLong(119));
        self::assertTrue($pacing->isLong(120));
    }

    public function testPacingOptionAcceptsArray(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);

        $pacing = Config::from(['pacing' => ['interval' => '20-30']])->pacing();

        // Nilai eksplisit menang utuh: siklus dari environment tidak ikut.
        self::assertSame([], $pacing->cycle());
        self::assertSame(['min' => 20, 'max' => 30], $pacing->interval());
    }

    public function testPacingOptionAcceptsPacingInstance(): void
    {
        $pacing = Config::from(['pacing' => Pacing::fromConfig('3', null)])->pacing();

        self::assertSame([3], $pacing->cycle());
    }

    public function testExplicitPacingOptionWinsOverEnvironment(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);

        self::assertFalse((new Config(pacing: Pacing::none()))->pacing()->isEnabled());
    }

    // ---------------------------------------------------------------------
    // Penerapan saat mengirim
    // ---------------------------------------------------------------------

    public function testDisabledPacingKeepsSendingWithoutWaiting(): void
    {
        self::fakeEnv([]);
        $this->recordSleeps();
        $backend = self::apiMeBackend(3);

        $result = $this->apiMe($backend, [
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0833', 'message' => 'c'],
        ]);

        self::assertSame('Sukses, 3/3 pesan terkirim', $result);
        self::assertSame([], $this->jeda);
    }

    public function testConfigPacingIsAppliedBetweenMessages(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30,10']);
        $this->recordSleeps();
        $backend = self::apiMeBackend(4);

        $result = $this->apiMe($backend, [
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0833', 'message' => 'c'],
            ['destination' => '0844', 'message' => 'd'],
        ]);

        self::assertSame('Sukses, 4/4 pesan terkirim', $result);
        self::assertSame(4, $backend->count());

        // Pesan pertama tidak pernah ditunggu; sisanya mengikuti siklus
        // 30, 10, lalu kembali ke awal siklus (0, jadi tanpa jeda).
        self::assertSame([30, 10], $this->jeda);
    }

    public function testExplicitDelayWinsOverPacing(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '5']);
        $this->recordSleeps();
        $backend = self::apiMeBackend(3);

        $this->apiMe($backend, [
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b', 'delay' => 0],
            ['destination' => '0833', 'message' => 'c'],
        ]);

        // Pesan ke-2 menyebut 0 secara eksplisit, jadi pacing tidak menimpanya.
        self::assertSame([5], $this->jeda);
    }

    public function testPerCallPacingOverridesConfig(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $this->recordSleeps();
        $backend = self::apiMeBackend(3);

        $this->apiMe($backend, [
            'messages' => [
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
                ['destination' => '0833', 'message' => 'c'],
            ],
            'pacing' => ['cycle' => '7'],
        ]);

        self::assertSame(3, $backend->count());
        self::assertSame([7, 7], $this->jeda);
    }

    /**
     * Penimpaan per panggilan menggabung, bukan mengganti: yang tidak
     * disebutkan tetap memakai nilai dari .env.
     */
    public function testPerCallPacingMergesWithConfig(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $this->recordSleeps();
        $backend = self::apiMeBackend(3);

        $this->apiMe($backend, [
            'messages' => [
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
                ['destination' => '0833', 'message' => 'c'],
            ],
            'pacing' => ['interval' => '1-1'],
        ]);

        // Siklus 0,30 dari .env + jitter 1 detik: 30+1, lalu 0+1.
        self::assertSame([31, 1], $this->jeda);
    }

    public function testPacingKeyMaySitBesideTheMessageList(): void
    {
        self::fakeEnv([]);
        $this->recordSleeps();
        $backend = self::apiMeBackend(2);

        $this->apiMe($backend, [
            'pacing' => ['cycle' => '4'],
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        // Kunci 'pacing' tidak boleh ikut terbaca sebagai pesan.
        self::assertSame(2, $backend->count());
        self::assertSame([4], $this->jeda);
    }

    public function testPacingKeyOnSingleMessage(): void
    {
        self::fakeEnv([]);
        $backend = self::apiMeBackend(1);

        $result = $this->apiMe($backend, [
            'destination' => '0811',
            'message' => 'a',
            'pacing' => ['cycle' => '9'],
        ]);

        // Satu pesan tidak punya pesan berikutnya untuk dijeda.
        self::assertSame('Sukses, messageId: x', $result);
        self::assertSame(1, $backend->count());
    }

    public function testNonArrayPacingIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kunci 'pacing' harus berupa array");

        $this->apiMe(self::apiMeBackend(0), [
            'destination' => '0811',
            'message' => 'a',
            'pacing' => '20-30',
        ]);
    }

    public function testNullPacingIsIgnored(): void
    {
        self::fakeEnv([]);
        $backend = self::apiMeBackend(1);

        self::assertSame('Sukses, messageId: x', $this->apiMe($backend, [
            'destination' => '0811',
            'message' => 'a',
            'pacing' => null,
        ]));
    }

    public function testWuzapiHonoursPacing(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $this->recordSleeps();

        $backend = new MockBackend([
            MockBackend::json(['success' => true, 'data' => ['Id' => 'a']]),
            MockBackend::json(['success' => true, 'data' => ['Id' => 'b']]),
        ]);

        (new Wuzapi(['token' => 't', 'url' => 'https://v4.test'], $backend->executor()))
            ->sendMessage([
                ['destination' => '0811', 'message' => 'a'],
                ['destination' => '0822', 'message' => 'b'],
            ]);

        self::assertSame([30], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Gateway yang menyerahkan jeda ke servernya
    // ---------------------------------------------------------------------

    public function testFonnteSendsPacingAsServerSideDelay(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        (new Fonnte(['token' => 't'], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0833', 'message' => 'c'],
        ]);

        $sent = json_decode($backend->lastForm()['data'] ?? '[]', true) ?? [];

        // Fonnte menunggu di sisinya sendiri, jadi tidak ada jeda di klien.
        self::assertSame(['0', '30', '0'], array_column($sent, 'delay'));
        self::assertSame([], $this->jeda);
    }

    public function testFonnteKeepsItsOwnDefaultWithoutPacing(): void
    {
        self::fakeEnv([]);
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        (new Fonnte(['token' => 't'], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        $sent = json_decode($backend->lastForm()['data'] ?? '[]', true) ?? [];

        self::assertSame(['2', '2'], array_column($sent, 'delay'));
    }

    public function testOpenWaBulkDelayComesFromPacing(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $backend = new MockBackend([MockBackend::json(['totalMessages' => 2, 'batchId' => 'b'])]);

        (new OpenWA(
            ['token' => 't', 'url' => 'https://gw.test', 'session' => 's'],
            $backend->executor()
        ))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
        ]);

        // OpenWA hanya menerima satu angka jeda untuk seluruh batch.
        self::assertSame(30000, $backend->lastJson()['options']['delayBetweenMessages']);
        self::assertSame([], $this->jeda);
    }

    public function testEvolutionSendsPacingInPayload(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,30']);
        $backend = new MockBackend([
            MockBackend::json(['key' => ['id' => 'a']]),
            MockBackend::json(['key' => ['id' => 'b']]),
            MockBackend::json(['key' => ['id' => 'c']]),
        ]);

        (new EvolutionAPI(
            ['token' => 't', 'url' => 'https://v7.test', 'instance' => 'siku'],
            $backend->executor()
        ))->sendMessage([
            ['destination' => '0811', 'message' => 'a'],
            ['destination' => '0822', 'message' => 'b'],
            ['destination' => '0833', 'message' => 'c'],
        ]);

        // Evolution menghitung delay dalam milidetik dan membuang nilai 0.
        self::assertArrayNotHasKey('delay', self::bodyAt($backend, 0));
        self::assertSame(30000, self::bodyAt($backend, 1)['delay']);
        self::assertArrayNotHasKey('delay', self::bodyAt($backend, 2));

        // Jeda sudah dititipkan ke server, jadi klien tidak menunggu dua kali.
        self::assertSame([], $this->jeda);
    }

    // ---------------------------------------------------------------------
    // Panjang isi pesan ikut menentukan jeda
    // ---------------------------------------------------------------------

    /**
     * Pembanding yang sama, siklus yang sama — hanya isi pesannya yang beda.
     * Itulah buktinya bahwa `sendMessage()` benar-benar membaca badan pesan.
     */
    public function testLongerBodyGetsLongerDelay(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,10']);
        $panjang = str_repeat('a', 400);

        $this->recordSleeps();
        $this->apiMe(self::apiMeBackend(4), [
            ['destination' => '0811', 'message' => 'pendek'],
            ['destination' => '0822', 'message' => $panjang],
            ['destination' => '0833', 'message' => 'pendek'],
            ['destination' => '0844', 'message' => $panjang],
        ]);
        self::assertSame([30, 30], $this->jeda);

        $this->recordSleeps();
        $this->apiMe(self::apiMeBackend(4), [
            ['destination' => '0811', 'message' => 'pendek'],
            ['destination' => '0822', 'message' => 'pendek'],
            ['destination' => '0833', 'message' => 'pendek'],
            ['destination' => '0844', 'message' => 'pendek'],
        ]);
        self::assertSame([10, 10], $this->jeda);
    }

    /** Huruf beraksen dihitung satu karakter, bukan dua byte. */
    public function testThresholdIsCountedInCharacters(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,10']);
        $this->recordSleeps();

        // 250 karakter, tapi 500 byte — tidak boleh dianggap pesan panjang.
        $this->apiMe(self::apiMeBackend(2), [
            ['destination' => '0811', 'message' => 'pendek'],
            ['destination' => '0822', 'message' => str_repeat('é', 250)],
        ]);

        self::assertSame([10], $this->jeda);
    }

    public function testLongRuleCanBeDisabledForOneCall(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,10']);
        $this->recordSleeps();

        $this->apiMe(self::apiMeBackend(2), [
            'messages' => [
                ['destination' => '0811', 'message' => 'pendek'],
                ['destination' => '0822', 'message' => str_repeat('a', 400)],
            ],
            'pacing' => ['long_factor' => 1],
        ]);

        self::assertSame([10], $this->jeda);
    }

    public function testFonnteAppliesLongMessageDelayOnTheServer(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,10']);
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        (new Fonnte(['token' => 't'], $backend->executor()))->sendMessage([
            ['destination' => '0811', 'message' => 'pendek'],
            ['destination' => '0822', 'message' => str_repeat('a', 400)],
            ['destination' => '0833', 'message' => 'pendek'],
        ]);

        $sent = json_decode($backend->lastForm()['data'] ?? '[]', true) ?? [];

        // Pesan ke-2 panjang: 10 detik x 3.
        self::assertSame(['0', '30', '0'], array_column($sent, 'delay'));
    }

    public function testOpenWaBatchDelayFollowsTheSecondMessage(): void
    {
        self::fakeEnv(['WHATSAPP_PACING_CYCLE' => '0,10']);

        self::assertSame(10000, $this->openWaBulkDelay('pendek'));
        self::assertSame(30000, $this->openWaBulkDelay(str_repeat('a', 400)));
    }

    /** Jeda batch OpenWA, dalam milidetik, untuk pesan kedua seperti ini. */
    private function openWaBulkDelay(string $second): int
    {
        $backend = new MockBackend([MockBackend::json(['totalMessages' => 2, 'batchId' => 'b'])]);

        (new OpenWA(
            ['token' => 't', 'url' => 'https://gw.test', 'session' => 's'],
            $backend->executor()
        ))->sendMessage([
            ['destination' => '0811', 'message' => 'pendek'],
            ['destination' => '0822', 'message' => $second],
        ]);

        return (int) $backend->lastJson()['options']['delayBetweenMessages'];
    }
}
