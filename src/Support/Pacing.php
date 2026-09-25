<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pengatur jeda antar pesan saat mengirim beruntun.
 *
 * Berangkat dari satu masalah yang sama di semua gateway: jeda yang seragam
 * membuat pengiriman beruntun mudah dikenali sebagai robot. Karena itu jeda
 * disusun dari dua bagian yang bisa dipakai sendiri-sendiri atau bersamaan:
 *
 * 1. **Siklus** — daftar jeda tetap yang dipakai bergiliran. `0, 30` berarti
 *    pesan ke-1 tanpa jeda, ke-2 jeda 30 detik, ke-3 tanpa jeda, dan
 *    seterusnya. Bagian ini yang membuat polanya tidak rata.
 * 2. **Interval acak** — jitter yang ditambahkan ke tiap jeda siklus. `20-30`
 *    berarti setiap jeda ditambah 20–30 detik acak. Bagian ini yang membuat
 *    polanya tidak bisa ditebak.
 *
 * Jadi jeda sebelum pesan ke-`i` adalah
 * `siklus[i % jumlah siklus] + jitter`. Dengan
 * `WHATSAPP_PACING_CYCLE=0,30` dan `WHATSAPP_PACING_INTERVAL=20-30`, jedanya
 * berurutan 20–30, 50–60, 20–30, 50–60, … detik.
 *
 * Bawaannya **mati**. Selama kedua kunci itu kosong, {@see self::delayFor()}
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
     * {@see \Sikuwa\Whatsapp\Config::timeout()} yang membatasi dirinya.
     */
    public const MAX_DELAY = 600;

    /**
     * @param array<int,int> $cycle Jeda tetap yang dipakai bergiliran, detik.
     * @param int|null $min Batas bawah jitter, detik. Null berarti tanpa jitter.
     * @param int|null $max Batas atas jitter, detik.
     */
    private function __construct(
        private readonly array $cycle,
        private readonly ?int $min,
        private readonly ?int $max
    ) {
    }

    /** Pacing yang tidak mengubah apa pun. */
    public static function none(): self
    {
        return new self([], null, null);
    }

    /** Bangun dari isi environment: `0,30` dan `20-30`. */
    public static function fromConfig(mixed $cycle, mixed $interval): self
    {
        [$min, $max] = self::parseInterval($interval);

        return new self(self::parseCycle($cycle), $min, $max);
    }

    /**
     * Bangun dari opsi pemanggil, mis.
     * `['cycle' => '0,30', 'interval' => [20, 30]]`.
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

        return new self($cycle, $min, $max);
    }

    /** Apakah pacing ini mengubah jeda sama sekali. */
    public function isEnabled(): bool
    {
        return $this->cycle !== [] || $this->min !== null;
    }

    /**
     * Jeda sebelum pesan ke-`index` dikirim, dalam detik.
     *
     * Null berarti "tidak ada pacing", dan pemanggil yang memutuskan nilai
     * penggantinya — bawaan gateway atau nol. Nol berarti "pacing aktif, dan
     * untuk pesan ini jedanya nol" — dua hal yang berbeda, jadi keduanya tidak
     * boleh disamakan.
     */
    public function delayFor(int $index): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $base = $this->cycle === [] ? 0 : $this->cycle[$index % \count($this->cycle)];
        $jitter = $this->min === null ? 0 : random_int($this->min, $this->max);

        return $base + $jitter;
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
}
