<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pengatur indikator "sedang mengetik" yang dimunculkan SDK sendiri sebelum
 * sebuah pesan dikirim.
 *
 * Berangkat dari masalah yang sama di semua gateway: pesan yang muncul
 * seketika setelah request terbaca sebagai robot. Manusia mengetik dulu,
 * penerima melihat "sedang mengetik", baru pesannya datang. Fitur ini meniru
 * bagian itu — dan lamanya **mengikuti panjang pesan**, karena pesan 300
 * karakter yang "diketik" dalam satu detik sama tidak wajarnya dengan "Halo"
 * yang diketipkan sepuluh detik.
 *
 * Lamanya dihitung `panjang pesan ÷ kecepatan ketik`, lalu dijepit di antara
 * `min` dan `max`. Dengan bawaan 15 karakter/detik, 2 detik, dan 20 detik:
 * 10 karakter menjadi 2 detik, 60 karakter 4 detik, 150 karakter 10 detik,
 * dan 300 karakter ke atas berhenti di 20 detik.
 *
 * Bawaannya **mati**. Selama `WHATSAPP_TYPING` tidak diisi,
 * {@see self::durationFor()} mengembalikan null dan jalur kirim berjalan
 * persis seperti sebelum fitur ini ada.
 *
 * Bedanya dengan {@see Presence}: kelas itu menormalkan *permintaan* pemanggil
 * ke satu kosakata, sedangkan kelas ini yang memutuskan **berapa lama**
 * indikatornya tampil. Hasilnya diserahkan ke `Presence` untuk diterjemahkan
 * tiap gateway.
 */
final class Typing
{
    /** Kecepatan ketik bawaan, dalam karakter per detik. */
    public const DEFAULT_SPEED = 15;

    /** Lama tampil paling singkat, dalam detik. */
    public const DEFAULT_MIN = 2;

    /**
     * Lama tampil paling lama, dalam detik.
     *
     * Dua puluh detik bukan angka asal: di atas 20 000 milidetik Evolution API
     * memecah satu permintaan presence menjadi beberapa bagian, jadi menjaga
     * batasnya di bawah itu membuat kelima gateway menempuh jalur yang sama.
     */
    public const DEFAULT_MAX = 20;

    /**
     * Batas mutlak satu indikator, dalam detik.
     *
     * Salah tulis di .env — mis. `WHATSAPP_TYPING_MAX=20000` yang dimaksudkan
     * `20` — tidak boleh menahan proses pemanggil selama berjam-jam. Batas ini
     * berlaku sama seperti {@see Pacing::MAX_DELAY} pada jeda antar pesan.
     */
    public const MAX_SECONDS = 600;

    /**
     * @param bool $enabled Apakah indikator dimunculkan sendiri oleh SDK.
     * @param int  $speed   Kecepatan ketik, karakter per detik. Minimal 1.
     * @param int  $min     Lama tampil paling singkat, detik.
     * @param int  $max     Lama tampil paling lama, detik.
     */
    private function __construct(
        private readonly bool $enabled,
        private readonly int $speed,
        private readonly int $min,
        private readonly int $max
    ) {
    }

    /** Typing yang tidak mengubah apa pun. */
    public static function off(): self
    {
        return new self(false, self::DEFAULT_SPEED, self::DEFAULT_MIN, self::DEFAULT_MAX);
    }

    /**
     * Bangun dari isi environment: `1`, `15`, `2`, `20`.
     *
     * Sama seperti {@see Pacing::fromConfig()}: nilai yang tidak bisa dibaca
     * memakai bawaannya, bukan mematikan fiturnya — salah tulis satu kunci
     * tidak boleh membatalkan kunci lain.
     */
    public static function fromConfig(
        mixed $enabled,
        mixed $speed = null,
        mixed $min = null,
        mixed $max = null
    ): self {
        return self::build(
            self::parseEnabled($enabled),
            self::parseSpeed($speed),
            self::parseSeconds($min, self::DEFAULT_MIN),
            self::parseSeconds($max, self::DEFAULT_MAX)
        );
    }

    /**
     * Bangun dari opsi pemanggil, mis.
     * `['enabled' => true, 'speed' => 8, 'max' => 30]`.
     *
     * Menyebut kunci apa pun selain `enabled` sudah dianggap permintaan untuk
     * menyalakannya — pemanggil yang menulis `['speed' => 8]` jelas ingin
     * indikatornya muncul, dan membiarkannya tetap mati hanya menghasilkan
     * kebingungan. Untuk mematikannya, tulis `['enabled' => false]`.
     *
     * @param array<string,mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        return self::off()->merge($spec);
    }

    /**
     * Timpa sebagian nilai, tanpa menyentuh yang tidak disebutkan.
     *
     * Sengaja menggabung, bukan mengganti: pemanggil yang cuma ingin
     * memperlambat ketikannya tidak perlu mengulang pengaturan yang sudah ada
     * di .env.
     *
     * @param array<string,mixed>|null $spec
     */
    public function merge(?array $spec): self
    {
        if ($spec === null || $spec === []) {
            return $this;
        }

        return self::build(
            array_key_exists('enabled', $spec) ? self::parseEnabled($spec['enabled']) : true,
            array_key_exists('speed', $spec) ? self::parseSpeed($spec['speed']) : $this->speed,
            array_key_exists('min', $spec) ? self::parseSeconds($spec['min'], self::DEFAULT_MIN) : $this->min,
            array_key_exists('max', $spec) ? self::parseSeconds($spec['max'], self::DEFAULT_MAX) : $this->max
        );
    }

    /** Apakah SDK memunculkan indikator sendiri sebelum mengirim. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Lama indikator ditampilkan untuk pesan sepanjang `$length` karakter.
     *
     * `$length` dihitung dalam **karakter**, bukan byte — lihat
     * {@see Text::length()}, sama seperti ambang pesan panjang milik pacing.
     *
     * Null berarti "tidak ada indikator", dan pemanggil yang memutuskan
     * berikutnya. Nol berarti "indikator aktif, tapi pesan ini tidak perlu
     * ditunggu" — dua hal yang berbeda, jadi keduanya tidak boleh disamakan.
     */
    public function durationFor(int $length): ?int
    {
        if (! $this->enabled) {
            return null;
        }

        // Pesan kosong tidak sedang "diketik" oleh siapa pun.
        if ($length <= 0) {
            return 0;
        }

        return max($this->min, min($this->max, (int) ceil($length / $this->speed)));
    }

    /** Kecepatan ketik, karakter per detik. */
    public function speed(): int
    {
        return $this->speed;
    }

    /** Lama tampil paling singkat, detik. */
    public function min(): int
    {
        return $this->min;
    }

    /** Lama tampil paling lama, detik. */
    public function max(): int
    {
        return $this->max;
    }

    /** Susun objek sekaligus rapikan batasnya, supaya `min` tidak melewati `max`. */
    private static function build(bool $enabled, int $speed, int $min, int $max): self
    {
        return new self($enabled, $speed, min($min, $max), max($min, $max));
    }

    /** Baca saklar on/off; apa pun yang tidak dikenali berarti mati. */
    private static function parseEnabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Kecepatan ketik; nilai tak terbaca atau nol kembali ke bawaan. */
    private static function parseSpeed(mixed $value): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) ? max(1, (int) $text) : self::DEFAULT_SPEED;
    }

    /** Batas lama tampil dalam detik; nilai tak terbaca kembali ke bawaan. */
    private static function parseSeconds(mixed $value, int $default): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) ? min(self::MAX_SECONDS, max(0, (int) $text)) : $default;
    }
}
