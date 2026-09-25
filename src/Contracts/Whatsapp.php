<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Contracts;

use Sikuwa\Whatsapp\Session;

/**
 * Antarmuka tunggal yang harus dipenuhi setiap gateway WhatsApp.
 *
 * Semua provider memakai bentuk pesan yang sama supaya mengganti gateway —
 * atau membiarkan SDK memilihnya sendiri — tidak mengubah kode pemanggil:
 *
 * ```php
 * ['destination' => '081234567890', 'message' => 'Halo', 'delay' => 2]
 * ```
 *
 * `delay` opsional dan dihitung dalam detik; artinya jeda sebelum pesan
 * dikirim (Fonnte dan Evolution API) atau jeda antar pesan pada pengiriman
 * berurutan.
 *
 * Sesi WhatsApp juga seragam: {@see self::createSession()},
 * {@see self::checkSession()}, dan {@see self::showQr()} selalu mengembalikan
 * {@see Session}, apa pun gateway-nya — walaupun tiap gateway menyebutnya
 * berbeda (OpenWA "session", ApiMe dan Evolution API "instance", wuzapi dan
 * Fonnte "device").
 */
interface Whatsapp
{
    /**
     * Kirim satu pesan, atau beberapa sekaligus.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *        Satu pesan `['destination' => ..., 'message' => ..., 'delay' => ...]`,
     *        atau list dari array seperti itu untuk pengiriman massal.
     *
     * @return string Detail hasil yang siap dicatat ke log. Setiap provider
     *                mengawalinya dengan `"Sukses"` supaya pemanggil bisa
     *                menentukan level log tanpa tahu gateway mana yang dipakai.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException Bila pesan gagal
     *         dikirim. Pakai {@see \Sikuwa\Whatsapp\Client::notify()} kalau
     *         pemanggil lebih suka menerima string alih-alih exception.
     */
    public function sendMessage(array|string $message): string;

    /**
     * Buat sesi/instance baru di gateway.
     *
     * Nama sesi diambil dari `$options` bila ada, selain itu dari konfigurasi
     * yang sudah terpasang (`WHATSAPP_SESSION` / `WHATSAPP_INSTANCE`). Karena
     * itu `createSession()` tanpa argumen pun masuk akal di aplikasi yang
     * seluruh kredensialnya sudah ada di `.env`. Fonnte adalah pengecualian:
     * ia memang menuntut `name` dan `device` di `$options`, karena perangkat
     * baru butuh nomor yang belum pernah dipakai.
     *
     * Kunci `$options` yang dikenali berbeda per gateway dan diteruskan apa
     * adanya — mis. OpenWA `id`, `name`, `config`; ApiMe `name`,
     * `webhook_url`; Evolution API `instanceName`, `webhook`; Fonnte `name`,
     * `device`; wuzapi `subscribe`, `immediate`.
     *
     * Sesi yang baru dibuat biasanya belum tersambung: pindai QR-nya, lalu
     * pantau dengan {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function createSession(array $options = []): Session;

    /**
     * Baca keadaan sesi yang sudah ada.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function checkSession(?string $id = null): Session;

    /**
     * Ambil QR milik sesi yang sudah ada, untuk dipindai.
     *
     * Dipanggil setelah {@see self::createSession()} atau
     * {@see self::checkSession()} menunjukkan sesi belum tersambung.
     * Mengembalikan {@see Session} yang sama, dengan {@see Session::$qr}
     * terisi data URI PNG:
     *
     * ```php
     * $qr = $client->showQr();
     *
     * if ($qr->hasQr()) {
     *     echo '<img src="' . $qr->qrImage() . '">';   // atau $qr->qrBase64()
     * }
     * ```
     *
     * Sesi yang sudah tersambung tidak punya QR untuk dipindai. Gateway yang
     * mengatakannya terus terang — Evolution API membalas keadaan instance,
     * Fonnte dan wuzapi menyebutnya di alasan penolakan — dilaporkan sebagai
     * `connected === true` dengan `$qr` kosong, bukan sebagai exception.
     * Gateway yang hanya membalas error umum (OpenWA) tetap melempar, karena
     * di sana penyebabnya bisa beberapa hal sekaligus.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     *                        Fonnte memakainya sebagai nomor perangkat.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function showQr(?string $id = null): Session;

    /** Nama gateway, dipakai untuk memilih token dan menandai baris log. */
    public function getProvider(): string;

    /** Token efektif: token eksplisit bila ada, selain itu dari environment. */
    public function getToken(): string;
}
