<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pengatur jeda antar pesan saat mengirim beruntun.
 *
 * Berangkat dari satu masalah yang sama di semua gateway: jeda yang seragam
 * membuat pengiriman beruntun mudah dikenali sebagai robot. Karena itu jeda
 * disusun dari tiga bagian yang bisa dipakai sendiri-sendiri atau bersamaan:
 *
 * 1. **Siklus** — daftar jeda tetap yang dipakai bergiliran. `0, 30` berarti
 *    pesan ke-1 tanpa jeda, ke-2 jeda 30 detik, ke-3 tanpa jeda, dan
 *    seterusnya. Bagian ini yang membuat polanya tidak rata.
 * 2. **Interval acak** — jitter yang ditambahkan ke tiap jeda siklus. `20-30`
 *    berarti setiap jeda ditambah 20–30 detik acak. Bagian ini yang membuat
 *    polanya tidak bisa ditebak.
 * 3. **Pesan panjang** — pesan yang isinya panjang ditunggu lebih lama,
 *    karena mengirim teks panjang beruntun lebih mencurigakan daripada
 *    mengirim pesan pendek. Ambang dan pengalinya diatur
 *    `WHATSAPP_PACING_LONG_CHARS` dan `WHATSAPP_PACING_LONG_FACTOR`.
 *
 * Jadi jeda sebelum pesan ke-`i` adalah
 * `(siklus[i % jumlah siklus] + jitter) × pengali pesan panjang`. Dengan
 * `WHATSAPP_PACING_CYCLE=0,30`, `WHATSAPP_PACING_INTERVAL=20-30`, dan ambang
 * bawaan 300 karakter, pesan pendek berurutan 20–30, 50–60, 20–30, … detik
 * sementara pesan panjang 60–90, 150–180, 60–90, … detik.
 *
 * Bawaannya **mati**. Selama kedua kunci pertama kosong, {@see self::delayFor()}
 * mengembalikan null dan tiap gateway memakai nilai bawaannya sendiri seperti
 * sebelumnya — perilaku lama tidak berubah hanya karena fitur ini ada.
 */
final class Pacing
{
    /**
     * Batas satu jeda, dalam detik.
     *
     * Salah tulis di .env — mis. `200-300` yang dimaksudkan `20-30` — tidak
     * boleh membuat proses pemanggil menggantung berjam-jam, sama seperti
     * {@see \Sikuwa\Whatsapp\Config::timeout()} yang membatasi dirinya. Batas
     * ini berlaku setelah pengali pesan panjang ikut dihitung.
     */
    public const MAX_DELAY = 600;

    /** Panjang pesan yang sudah dianggap "panjang", dalam karakter. */
    public const DEFAULT_LONG_CHARS = 300;

    /** Berapa kali jeda dilipatkan untuk pesan panjang. */
    public const DEFAULT_LONG_FACTOR = 3;

    /**
     * @param array<int,int> $cycle Jeda tetap yang dipakai bergiliran, detik.
     * @param int|null $min Batas bawah jitter, detik. Null berarti tanpa jitter.
     * @param int|null $max Batas atas jitter, detik.
     * @param int $longChars Ambang pesan panjang, karakter. 0 mematikan aturannya.
     * @param int $longFactor Pengali jeda untuk pesan panjang. 1 berarti tidak ada.
     */
    private function __construct(
        private readonly array $cycle,
        private readonly ?int $min,
        private readonly ?int $max,
        private readonly int $longChars,
        private readonly int $longFactor
    ) {
    }

    /** Pacing yang tidak mengubah apa pun. */
    public static function none(): self
    {
        return new self([], null, null, self::DEFAULT_LONG_CHARS, self::DEFAULT_LONG_FACTOR);
    }

    /**
     * Bangun dari isi environment: `0,30`, `20-30`, `300`, `3`.
     *
     * Nilai yang tidak bisa dibaca memakai bawaannya, bukan mematikan
     * pacing-nya — salah tulis satu kunci tidak boleh membatalkan kunci lain.
     */
    public static function fromConfig(
        mixed $cycle,
        mixed $interval,
        mixed $longChars = null,
        mixed $longFactor = null
    ): self {
        [$min, $max] = self::parseInterval($interval);

        return new self(
            self::parseCycle($cycle),
            $min,
            $max,
            self::parseLongChars($longChars),
            self::parseLongFactor($longFactor)
        );
    }

    /**
     * Bangun dari opsi pemanggil, mis.
     * `['cycle' => '0,30', 'interval' => [20, 30], 'long_factor' => 5]`.
     *
     * @param array<string,mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        return self::none()->merge($spec);
    }

    /**
     * Timpa sebagian nilai, tanpa menyentuh yang tidak disebutkan.
     *
     * Sengaja menggabung, bukan mengganti: pemanggil yang cuma ingin mengubah
     * intervalnya tidak perlu mengulang siklus yang sudah ada di .env. Untuk
     * mematikan salah satunya, sebutkan kuncinya dengan nilai null.
     *
     * @param array<string,mixed>|null $spec
     */
    public function merge(?array $spec): self
    {
        if ($spec === null || $spec === []) {
            return $this;
        }

        $cycle = array_key_exists('cycle', $spec) ? self::parseCycle($spec['cycle']) : $this->cycle;

        [$min, $max] = array_key_exists('interval', $spec)
            ? self::parseInterval($spec['interval'])
            : [$this->min, $this->max];

        return new self(
            $cycle,
            $min,
            $max,
            array_key_exists('long_chars', $spec) ? self::parseLongChars($spec['long_chars']) : $this->longChars,
            array_key_exists('long_factor', $spec) ? self::parseLongFactor($spec['long_factor']) : $this->longFactor
        );
    }

    /** Apakah pacing ini mengubah jeda sama sekali. */
    public function isEnabled(): bool
    {
        return $this->cycle !== [] || $this->min !== null;
    }

    /**
     * Jeda sebelum pesan ke-`index` dikirim, dalam detik.
     *
     * `$length` adalah panjang isi pesan dalam **karakter** — lihat
     * {@see Text::length()}. Pesan yang panjang ditunggu `longFactor` kali
     * lebih lama, karena itulah bagian yang membuat jedanya terasa wajar.
     *
     * Null berarti "tidak ada pacing", dan pemanggil yang memutuskan nilai
     * penggantinya — bawaan gateway atau nol. Nol berarti "pacing aktif, dan
     * untuk pesan ini jedanya nol" — dua hal yang berbeda, jadi keduanya tidak
     * boleh disamakan.
     */
    public function delayFor(int $index, int $length = 0): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $base = $this->cycle === [] ? 0 : $this->cycle[$index % \count($this->cycle)];
        $jitter = $this->min === null ? 0 : random_int($this->min, $this->max);
        $delay = ($base + $jitter) * ($this->isLong($length) ? $this->longFactor : 1);

        return min(self::MAX_DELAY, $delay);
    }

    /** Apakah pesan sepanjang `$length` karakter diperlakukan sebagai panjang. */
    public function isLong(int $length): bool
    {
        return $this->longChars > 0 && $length >= $this->longChars;
    }

    /**
     * Siklus jeda apa adanya, detik.
     *
     * @return array<int,int>
     */
    public function cycle(): array
    {
        return $this->cycle;
    }

    /** @return array{min:int,max:int}|null */
    public function interval(): ?array
    {
        return $this->min === null ? null : ['min' => $this->min, 'max' => $this->max];
    }

    /** Ambang pesan panjang, karakter. 0 berarti aturannya dimatikan. */
    public function longChars(): int
    {
        return $this->longChars;
    }

    /** Pengali jeda untuk pesan panjang. 1 berarti tidak ada pengalian. */
    public function longFactor(): int
    {
        return $this->longFactor;
    }

    /**
     * Siklus jeda dari teks `0, 30` maupun array `[0, 30]`.
     *
     * Bagian yang bukan angka dilewati, bukan ditolak: salah tulis di .env
     * tidak boleh membuat pengiriman gagal total — sama seperti
     * `WHATSAPP_TIMEOUT` yang mengabaikan nilai di luar rentang.
     *
     * @return array<int,int>
     */
    private static function parseCycle(mixed $value): array
    {
        $parts = \is_array($value) ? $value : explode(',', \is_scalar($value) ? (string) $value : '');
        $cycle = [];

        foreach ($parts as $part) {
            if (! \is_scalar($part)) {
                continue;
            }

            $text = trim((string) $part);

            if ($text === '' || ! is_numeric($text)) {
                continue;
            }

            $cycle[] = min(self::MAX_DELAY, max(0, (int) $text));
        }

        return $cycle;
    }

    /**
     * Interval acak dari teks `20-30`, angka tunggal `25`, atau array
     * `[20, 30]`.
     *
     * Urutan terbalik (`30-20`) dibetulkan alih-alih ditolak, karena maksudnya
     * sudah jelas dan menolaknya hanya memindahkan pekerjaan ke pemanggil.
     *
     * @return array{0:int|null,1:int|null}
     */
    private static function parseInterval(mixed $value): array
    {
        if (\is_array($value)) {
            $parts = array_values($value);

            return self::clampRange(
                isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : null,
                isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : null
            );
        }

        $text = trim(\is_scalar($value) ? (string) $value : '');

        if ($text === '') {
            return [null, null];
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $text, $m) === 1) {
            return self::clampRange((int) $m[1], (int) $m[2]);
        }

        return is_numeric($text) ? self::clampRange((int) $text, null) : [null, null];
    }

    /**
     * Rapikan sepasang batas: null bila tidak ada, urut, dan di dalam rentang.
     *
     * @return array{0:int|null,1:int|null}
     */
    private static function clampRange(?int $low, ?int $high): array
    {
        if ($low === null) {
            return [null, null];
        }

        $high ??= $low;

        if ($low > $high) {
            [$low, $high] = [$high, $low];
        }

        return [
            min(self::MAX_DELAY, max(0, $low)),
            min(self::MAX_DELAY, max(0, $high)),
        ];
    }

    /** Ambang pesan panjang; nilai tak terbaca kembali ke bawaan. */
    private static function parseLongChars(mixed $value): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) ? max(0, (int) $text) : self::DEFAULT_LONG_CHARS;
    }

    /** Pengali pesan panjang; 1 berarti tidak mengalikan apa pun. */
    private static function parseLongFactor(mixed $value): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) ? max(1, (int) $text) : self::DEFAULT_LONG_FACTOR;
    }
}
