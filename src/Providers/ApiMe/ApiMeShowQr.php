<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\ApiMe;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Envelope;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR instance ApiMe — menormalkan balasan `GET /api/instances/{id}/qr`.
 *
 * OpenAPI ApiMe menyebut balasan endpoint ini hanya sebagai "QR code base64",
 * tanpa mendefinisikan skemanya sama sekali. Karena itu pembacaannya sengaja
 * longgar: beberapa kemungkinan nama field dicoba, dan `data` yang berupa
 * string dianggap langsung sebagai payload QR-nya. Memaku satu bentuk tertentu
 * akan membuat SDK ini rusak diam-diam begitu ApiMe mengganti namanya.
 */
final class ApiMeShowQr
{
    /** Kemungkinan nama field payload QR, dari yang paling mungkin. */
    private const FIELDS = ['qr', 'qrcode', 'qrCode', 'base64', 'image'];

    /**
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $data = Envelope::unwrap($body);

        return new Session(
            provider: ApiMe::NAME,
            id: Text::first($data['id'] ?? null, $data['instance_id'] ?? null, $fallbackId),
            status: Text::first($data['status'] ?? null, $data['state'] ?? null),
            connected: false,
            qr: Qr::dataUri(self::payload($body, $data)),
            raw: $body,
        );
    }

    /**
     * @param array<string,mixed> $body Amplop lengkap, untuk kasus `data` berupa string.
     * @param array<string,mixed> $data Isi `data` bila memang berupa objek.
     */
    private static function payload(array $body, array $data): string
    {
        foreach (self::FIELDS as $field) {
            $value = Text::of($data[$field] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        // Sebagian versi menaruh base64-nya langsung di `data`, bukan di dalamnya.
        return Text::of($body['data'] ?? null);
    }

}
