<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Contracts;

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

    /** Nama gateway, dipakai untuk memilih token dan menandai baris log. */
    public function getProvider(): string;

    /** Token efektif: token eksplisit bila ada, selain itu dari environment. */
    public function getToken(): string;
}
