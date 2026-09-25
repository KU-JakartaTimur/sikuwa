<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR perangkat Fonnte — menormalkan balasan `POST /qr`.
 *
 * Fonnte membalas base64 PNG **telanjang** di field `url`, tanpa awalan `data:`
 * — dokumennya sendiri menyarankan merangkainya menjadi
 * `<img src="data:image/png;base64,…">`. Perangkaian itu dikerjakan
 * {@see Qr::dataUri()}, jadi pemanggil tidak perlu tahu bedanya.
 *
 * Kalau perangkatnya sudah tersambung, Fonnte tidak mengirim QR melainkan
 * `{"status":false,"reason":"device already connect"}`. Itu keadaan, bukan
 * kegagalan, jadi diterjemahkan menjadi sesi yang `connected`.
 */
final class FonnteShowQr
{
    /**
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body): Session
    {
        $qr = Text::of($body['url'] ?? null);

        return new Session(
            provider: Fonnte::NAME,
            id: Text::of($body['device'] ?? null),
            status: $qr !== '' ? 'qr_ready' : '',
            connected: false,
            qr: Qr::dataUri($qr),
            raw: $body,
        );
    }

    /** Perangkat sudah tersambung, jadi tidak ada QR yang perlu dipindai. */
    public static function alreadyConnected(array $body): Session
    {
        return new Session(
            provider: Fonnte::NAME,
            status: FonnteSession::CONNECTED,
            connected: true,
            raw: $body,
        );
    }

    /** Apakah amplop penolakan Fonnte berarti "perangkat sudah tersambung". */
    public static function isAlreadyConnected(array $body): bool
    {
        $reason = strtolower(Text::of($body['reason'] ?? null));

        return str_contains($reason, 'already connect');
    }
}
