<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;

/**
 * Keadaan "sedang mengetik" yang diminta pemanggil, dinormalkan ke satu
 * kosakata sebelum diterjemahkan tiap gateway.
 *
 * Kelima gateway menyebut hal yang sama dengan kata yang berbeda: OpenWA
 * memakai `typing`, ApiMe menerima `typing` maupun `composing`, Evolution API
 * dan wuzapi memakai `composing`, dan Fonnte tidak punya keadaan sama sekali —
 * ia hanya kenal "mulai mengetik" dan "berhenti". Pemanggil cukup menulis satu
 * istilah, dan padanannya diurus di sini, bukan diulang di lima provider.
 *
 * Objek ini juga yang menegakkan satu aturan yang tidak boleh diserahkan ke
 * masing-masing provider: **durasi wajib ada saat indikator ditampilkan**.
 * Fonnte menuntutnya di sisi server, sedangkan Evolution API menunggu selama
 * durasi itu lalu menghapus indikatornya sendiri — dengan durasi 0 keduanya
 * tidak menampilkan apa pun, dan kegagalannya senyap.
 */
final class Presence
{
    /** Indikator "sedang mengetik". */
    public const COMPOSING = 'composing';

    /** Menghapus indikator yang sedang tampil. */
    public const PAUSED = 'paused';

    /** Indikator "sedang merekam suara". Tidak semua gateway punya. */
    public const RECORDING = 'recording';

    /**
     * Istilah lain yang diterima, dipetakan ke kosakata baku di atas.
     *
     * Sengaja lengkap: pemanggil yang sudah terbiasa dengan salah satu gateway
     * akan menulis istilah gateway itu, dan menolaknya hanya karena beda kata
     * akan terasa seperti kesalahan yang dibuat-buat.
     */
    private const ALIASES = [
        'composing' => self::COMPOSING,
        'compose' => self::COMPOSING,
        'typing' => self::COMPOSING,
        'type' => self::COMPOSING,
        'menulis' => self::COMPOSING,
        'paused' => self::PAUSED,
        'pause' => self::PAUSED,
        'stop' => self::PAUSED,
        'stopped' => self::PAUSED,
        'clear' => self::PAUSED,
        'berhenti' => self::PAUSED,
        'recording' => self::RECORDING,
        'record' => self::RECORDING,
        'audio' => self::RECORDING,
        'suara' => self::RECORDING,
    ];

    private function __construct(
        /** Salah satu dari {@see self::COMPOSING}, {@see self::PAUSED}, {@see self::RECORDING}. */
        public readonly string $state,
        /** Lama indikator ditampilkan, dalam detik. 0 berarti tidak ada durasi. */
        public readonly int $duration,
    ) {
    }

    /**
     * Susun keadaan dari kunci pesan pemanggil.
     *
     * `$duration` sengaja bertipe `mixed`, bukan `int|float|string|null`:
     * nilainya datang langsung dari array pesan pemanggil, dan tipe yang
     * sempit hanya memindahkan kegagalannya menjadi `TypeError` — bukan
     * {@see ConfigurationException} yang menjelaskan cara memperbaikinya.
     *
     * @param string $state    Keadaan yang diminta; kosong berarti
     *                         {@see self::COMPOSING}.
     * @param mixed  $duration Lama indikator ditampilkan, dalam detik. Wajib
     *                         saat menampilkan indikator.
     *
     * @throws ConfigurationException
     */
    public static function from(string $state = '', mixed $duration = null): self
    {
        $canonical = self::canonical($state);
        $seconds = self::seconds($duration);

        // Fonnte dan Evolution API memakai durasi untuk menentukan berapa lama
        // indikator tampil. Tanpa angka, keduanya tidak menampilkan apa pun —
        // jadi lebih baik ditolak di sini daripada gagal tanpa jejak.
        if ($canonical !== self::PAUSED && $seconds === null) {
            throw new ConfigurationException(
                "Keadaan '{$canonical}' membutuhkan kunci 'duration' dalam detik, mis. "
                . "['destination' => '081234567890', 'state' => '{$canonical}', 'duration' => 5]. "
                . 'Tanpa durasi, Fonnte dan Evolution API tidak menampilkan indikator sama sekali'
            );
        }

        return new self($canonical, $seconds ?? 0);
    }

    /** Apakah keadaan ini menampilkan indikator, bukan menghapusnya? */
    public function showsIndicator(): bool
    {
        return ! $this->isPaused();
    }

    public function isComposing(): bool
    {
        return $this->state === self::COMPOSING;
    }

    public function isPaused(): bool
    {
        return $this->state === self::PAUSED;
    }

    public function isRecording(): bool
    {
        return $this->state === self::RECORDING;
    }

    /** Durasi dalam milidetik — satuan yang dipakai Evolution API. */
    public function milliseconds(): int
    {
        return $this->duration * 1000;
    }

    /** Sebutan keadaan ini untuk pesan hasil, supaya seragam di semua provider. */
    public function label(): string
    {
        return match ($this->state) {
            self::PAUSED => 'berhenti mengetik',
            self::RECORDING => 'sedang merekam suara',
            default => 'sedang mengetik',
        };
    }

    /** @throws ConfigurationException */
    private static function canonical(string $state): string
    {
        $state = trim($state);

        if ($state === '') {
            return self::COMPOSING;
        }

        $canonical = self::ALIASES[strtolower($state)] ?? null;

        if ($canonical === null) {
            throw new ConfigurationException(
                "Keadaan '{$state}' tidak dikenal. Pilih salah satu: "
                . self::COMPOSING . ', ' . self::PAUSED . ', atau ' . self::RECORDING
                . ' (istilah gateway seperti "typing", "stop", dan "audio" juga diterima)'
            );
        }

        return $canonical;
    }

    /**
     * Ubah durasi menjadi detik bulat, atau null bila memang tidak disebut.
     *
     * @throws ConfigurationException
     */
    private static function seconds(mixed $duration): ?int
    {
        if ($duration === null || $duration === '') {
            return null;
        }

        if (! \is_scalar($duration) || ! is_numeric($duration)) {
            $nilai = \is_scalar($duration)
                ? "'" . (string) $duration . "'"
                : get_debug_type($duration);

            throw new ConfigurationException(
                "Kunci 'duration' harus berupa angka detik, bukan {$nilai}"
            );
        }

        $seconds = (int) ceil((float) $duration);

        // 0 dan negatif sama saja artinya di sini: indikator tidak akan sempat
        // terlihat. Evolution API bahkan akan mengirim composing lalu langsung
        // paused tanpa jeda, sehingga pemanggil mengira sudah berhasil.
        if ($seconds <= 0) {
            throw new ConfigurationException(
                "Kunci 'duration' harus minimal 1 detik; nilai " . (float) $duration
                . ' membuat indikator tidak sempat terlihat'
            );
        }

        return $seconds;
    }
}
