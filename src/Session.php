<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp;

use Sikuwa\Whatsapp\Support\Qr;

/**
 * Keadaan satu sesi WhatsApp, dengan bentuk yang sama untuk semua gateway.
 *
 * Gateway menyebutnya berbeda-beda — OpenWA "session", ApiMe dan Evolution API
 * "instance", wuzapi dan Fonnte "device" — tetapi pemanggil selalu ingin tahu
 * hal yang sama: sesi mana ini, sudah tersambung atau belum, dan kalau belum,
 * di mana QR-nya. Itulah isi objek ini.
 *
 * ```php
 * $session = $client->checkSession();
 *
 * if (! $session->isConnected()) {
 *     // Sesi belum tersambung: ambil QR-nya, lalu tampilkan.
 *     echo $client->showQr()->qrTag('Scan untuk menyambungkan WhatsApp');
 * }
 * ```
 *
 * Nilai yang tidak disediakan gateway dibiarkan kosong, bukan ditebak. Yang
 * paling mentah selalu bisa dilihat di {@see Session::$raw} kalau pemanggil
 * butuh field khusus gateway.
 *
 * Sesi yang baru dibuat biasanya menerbitkan kredensialnya sendiri — lihat
 * {@see Session::$token}. Simpan nilainya, karena itulah yang dipakai untuk
 * mengirim pesan lewat sesi tersebut.
 */
final class Session
{
    /**
     * @param string              $provider    Nama gateway, mis. `OpenWA`.
     * @param string              $id          Id sesi menurut gateway. Bisa kosong
     *                                         kalau gateway membuatnya sendiri.
     * @param string              $status      Status apa adanya dari gateway, huruf
     *                                         besar dinormalkan per gateway.
     * @param bool                $connected   Apakah sesi siap mengirim pesan.
     * @param string              $qr          QR sebagai data URI, bila ada.
     *                                         Selalu data URI penuh atau string
     *                                         kosong — gateway yang mengirim
     *                                         base64 telanjang (Fonnte)
     *                                         dibungkus lebih dulu oleh
     *                                         {@see Support\Qr::dataUri()}, jadi
     *                                         nilainya bisa langsung dipasang di
     *                                         atribut `src`.
     * @param string              $token       Kredensial sesi yang baru diterbitkan
     *                                         gateway, bila ada — mis. token perangkat
     *                                         Fonnte atau `hash.apikey` Evolution API.
     *                                         Inilah yang diisi ke `WHATSAPP_TOKEN`
     *                                         supaya pesan bisa dikirim lewat sesi itu.
     * @param string              $phoneNumber Nomor yang tersambung, bila ada.
     * @param string              $profileName Nama profil WhatsApp, bila ada.
     * @param array<string,mixed> $raw         Amplop asli dari gateway.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $id = '',
        public readonly string $status = '',
        public readonly bool $connected = false,
        public readonly string $qr = '',
        public readonly string $token = '',
        public readonly string $phoneNumber = '',
        public readonly string $profileName = '',
        public readonly array $raw = [],
    ) {
    }

    /** Apakah sesi siap dipakai mengirim pesan. */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** Apakah gateway menyediakan QR untuk dipindai. */
    public function hasQr(): bool
    {
        return $this->qr !== '';
    }

    /**
     * QR sebagai data URI — siap dipasang di atribut `src`.
     *
     * ```php
     * echo '<img src="' . $session->qrImage() . '" alt="QR WhatsApp">';
     * ```
     *
     * Sama dengan {@see Session::$qr}; namanya dibuat eksplisit supaya maksud
     * pemakaiannya terbaca di templat.
     */
    public function qrImage(): string
    {
        return $this->qr;
    }

    /**
     * QR sebagai base64 murni, tanpa awalan `data:image/png;base64,`.
     *
     * Untuk pemanggil yang tidak ingin data URI — mis. menyimpan gambarnya
     * sendiri atau mengirimkannya sebagai JSON ke klien lain.
     */
    public function qrBase64(): string
    {
        return Qr::base64($this->qr);
    }

    /**
     * QR sebagai tag `<img>` siap cetak.
     *
     * ```php
     * echo $session->qrTag();                    // 260×260
     * echo $session->qrTag('Scan untuk masuk', 320);
     * ```
     *
     * Mengembalikan string kosong kalau tidak ada QR, sehingga pemanggil bisa
     * mencetaknya tanpa memeriksa {@see self::hasQr()} lebih dulu.
     */
    public function qrTag(string $alt = 'QR WhatsApp', int $size = 260): string
    {
        if (! $this->hasQr()) {
            return '';
        }

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d">',
            $this->qr,
            htmlspecialchars($alt, ENT_QUOTES),
            $size,
            $size
        );
    }

    /**
     * Bentuk yang aman disimpan atau dikirim sebagai JSON.
     *
     * `raw` dan `token` sengaja tidak ikut. `raw` bisa besar dan bentuknya
     * berbeda tiap gateway; `token` adalah kredensial, dan keluaran method ini
     * biasanya berakhir di log atau response HTTP. Keduanya tetap tersedia
     * lewat propertinya masing-masing.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'status' => $this->status,
            'connected' => $this->connected,
            'qr' => $this->qr,
            'phoneNumber' => $this->phoneNumber,
            'profileName' => $this->profileName,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }

    /** Ringkasan siap catat ke log, mis. `"OpenWA: CONNECTED (sess-1)"`. */
    public function __toString(): string
    {
        $status = $this->status !== '' ? $this->status : ($this->connected ? 'connected' : 'unknown');
        $id = $this->id !== '' ? " ({$this->id})" : '';

        return "{$this->provider}: {$status}{$id}";
    }
}
