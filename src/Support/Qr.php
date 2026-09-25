<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Penyeragaman payload QR antar gateway.
 *
 * Gateway tidak sepakat soal bentuk QR: Fonnte mengirim base64 telanjang,
 * OpenWA dan wuzapi mengirim data URI yang sudah lengkap, Evolution API
 * mengirim base64 di field `base64`. Pemanggil selalu menginginkan hal yang
 * sama — sesuatu yang bisa langsung dipasang di atribut `src` — jadi
 * perbedaannya dirapikan di sini, sekali, bukan di tiap provider.
 *
 * {@see self::dataUri()} sengaja idempoten: payload yang sudah berupa data URI
 * dikembalikan apa adanya. Karena itu aman dipanggil berkali-kali, dan tidak
 * pernah menghasilkan `data:image/png;base64,data:image/png;base64,...`.
 */
final class Qr
{
    /** Jenis gambar yang dipakai semua gateway di SDK ini. */
    public const MIME = 'image/png';

    /** Penanda awal bagian base64 pada sebuah data URI. */
    private const MARKER = 'base64,';

    /**
     * Ubah payload QR apa pun menjadi data URI siap pakai.
     *
     * @param string $payload base64 telanjang, atau data URI yang sudah lengkap
     * @param string $mime    Dipakai hanya kalau payload belum berupa data URI
     */
    public static function dataUri(string $payload, string $mime = self::MIME): string
    {
        $payload = trim($payload);

        if ($payload === '') {
            return '';
        }

        // Sudah data URI (OpenWA, wuzapi) — jangan dibungkus dua kali.
        if (str_starts_with($payload, 'data:')) {
            return $payload;
        }

        return "data:{$mime};" . self::MARKER . $payload;
    }

    /**
     * Ambil base64 murni dari payload QR.
     *
     * Dipakai pemanggil yang tidak menginginkan data URI — mis. menyimpan
     * gambarnya sendiri, atau mengirimkannya sebagai JSON ke klien lain.
     */
    public static function base64(string $qr): string
    {
        $marker = strpos($qr, self::MARKER);

        return $marker === false ? $qr : substr($qr, $marker + \strlen(self::MARKER));
    }
}
