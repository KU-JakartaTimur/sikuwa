<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR sesi OpenWA — menormalkan balasan `GET /api/sessions/{id}/qr`.
 *
 * Balasannya `{"qrCode": "data:image/png;base64,…", "status": "qr_ready"}`.
 * Sebagian versi membungkusnya sebagai `{success, data}` dan menamai fieldnya
 * `qr`, jadi keduanya diterima — seperti di {@see OpenWASession}.
 *
 * Perlu diperhatikan: `status` di sini **bukan** status sesi, melainkan
 * kesiapan QR-nya. Endpoint ini hanya menjawab saat sesi memang sedang menunggu
 * dipindai; sesi yang sudah tersambung ditolak dengan HTTP 400.
 */
final class OpenWAShowQr
{
    /**
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $data = self::unwrap($body);

        return new Session(
            provider: OpenWA::NAME,
            id: Text::first($data['id'] ?? null, $fallbackId),
            status: strtoupper(Text::of($data['status'] ?? null)),
            // Endpoint ini hanya menjawab kalau sesinya belum tersambung.
            connected: false,
            qr: Qr::dataUri(Text::first($data['qrCode'] ?? null, $data['qr'] ?? null)),
            raw: $body,
        );
    }

    /**
     * Sebagian versi OpenWA membungkus balasan sebagai `{success, data}`, yang
     * lain mengembalikan objeknya langsung.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function unwrap(array $body): array
    {
        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : $body;
    }
}
