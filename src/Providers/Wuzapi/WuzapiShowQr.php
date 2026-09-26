<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wuzapi;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Envelope;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR sesi wuzapi — menormalkan balasan `GET /session/qr`.
 *
 * Balasannya `{"code":200,"success":true,"data":{"QRCode":"data:image/png;base64,…",
 * "passkeyPending":false,"publicKey":null}}`. Nama field `QRCode` ditulis persis
 * seperti di handler wuzapi; sebagian versi mengembalikannya di akar body tanpa
 * amplop `data`, jadi keduanya dibaca.
 *
 * wuzapi hanya mengeluarkan QR saat sesinya tersambung ke server WhatsApp tetapi
 * belum login. Ketiga penolakannya — `no session`, `not connected`, dan
 * `already logged in` — dibedakan di {@see Wuzapi::showQr()}.
 */
final class WuzapiShowQr
{
    /**
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body): Session
    {
        $data = Envelope::data($body);
        $qr = Text::first($data['QRCode'] ?? null, $data['qrcode'] ?? null, $body['QRCode'] ?? null);

        return new Session(
            provider: Wuzapi::NAME,
            id: Text::of($data['jid'] ?? null),
            status: $qr !== '' ? 'qr_ready' : '',
            connected: false,
            qr: Qr::dataUri($qr),
            raw: $body,
        );
    }

    /** Sesi sudah login, jadi memang tidak ada QR yang perlu dipindai. */
    public static function alreadyLoggedIn(array $body): Session
    {
        return new Session(
            provider: Wuzapi::NAME,
            status: 'connected',
            connected: true,
            raw: $body,
        );
    }

    /** Apakah amplop penolakan wuzapi berarti "sudah login". */
    public static function isAlreadyLoggedIn(array $body): bool
    {
        $reason = strtolower(Text::first($body['error'] ?? null, $body['reason'] ?? null));

        return str_contains($reason, 'already logged in');
    }
}
