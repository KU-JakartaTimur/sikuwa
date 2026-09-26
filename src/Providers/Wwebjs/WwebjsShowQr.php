<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wwebjs;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Text;

/**
 * QR session wwebjs — menormalkan balasan `GET /session/qr/{sessionId}/image`.
 *
 * Endpoint itu mengirim PNG biner, bukan JSON: itulah sebabnya
 * {@see self::fromImage()} menerima byte mentahnya lalu membungkusnya menjadi
 * data URI. Balasan JSON hanya muncul saat QR-nya memang tidak ada — lihat
 * {@see Wwebjs::showQr()}.
 */
final class WwebjsShowQr
{
    /** Sebutan wwebjs saat QR-nya sudah dipindai. */
    private const ALREADY_SCANNED = 'already scanned';

    /**
     * PNG dari gateway, dibungkus menjadi data URI siap pasang.
     *
     * Base64 dilakukan di sini, bukan diserahkan pemanggil: `Session::$qr`
     * berjanji selalu berupa data URI penuh.
     *
     * @param string $png Byte PNG apa adanya dari body respons
     */
    public static function fromImage(string $png, string $sessionId): Session
    {
        return new Session(
            provider: Wwebjs::NAME,
            id: $sessionId,
            status: 'qr_ready',
            connected: false,
            qr: 'data:image/png;base64,' . base64_encode($png),
        );
    }

    /** Sesi sudah tersambung, jadi memang tidak ada QR yang perlu dipindai. */
    public static function alreadyConnected(array $body, string $sessionId): Session
    {
        return new Session(
            provider: Wwebjs::NAME,
            id: $sessionId,
            status: WwebjsSession::CONNECTED,
            connected: true,
            raw: $body,
        );
    }

    /**
     * Apakah amplop penolakan wwebjs berarti "QR-nya sudah dipindai".
     *
     * wwebjs menyatukan dua sebab dalam satu kalimat — `qr code not ready or
     * already scanned` — jadi yang dibaca di sini hanya yang kedua. Sesi yang
     * masih memuat Chromium memang belum menerbitkan QR, dan itu keadaan biasa,
     * bukan kegagalan.
     */
    public static function isAlreadyScanned(array $body): bool
    {
        return str_contains(strtolower(Text::of($body['message'] ?? null)), self::ALREADY_SCANNED);
    }
}
