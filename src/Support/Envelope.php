<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pembukaan amplop JSON gateway.
 *
 * Sebagian gateway membungkus datanya di dalam kunci `data`, sebagian lagi
 * mengirim objeknya langsung di akar body — dan beberapa di antaranya
 * mengganti-ganti perilaku itu antar versi. Dua penolong di sini menyatukan
 * perbedaan itu, supaya tiap penormal sesi tidak menulis ulang pemeriksaan
 * yang sama.
 *
 * Bedanya cuma soal isi cadangan: {@see self::unwrap()} memakai body apa
 * adanya, {@see self::data()} memakai array kosong.
 */
final class Envelope
{
    /**
     * Isi `data`; kalau `data` tidak ada atau bukan objek, body dikembalikan
     * apa adanya.
     *
     * Dipakai gateway yang sebagian versinya mengirim objeknya langsung di akar
     * body — OpenWA dan ApiMe.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function unwrap(array $body): array
    {
        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : $body;
    }

    /**
     * Isi `data`; kalau `data` tidak ada atau bukan objek, array kosong.
     *
     * Dipakai gateway yang seluruh datanya memang selalu ada di `data` —
     * wuzapi.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function data(array $body): array
    {
        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : [];
    }
}
