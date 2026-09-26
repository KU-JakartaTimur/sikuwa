<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wwebjs;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Session wwebjs — menyusun payload `POST /session/start/{sessionId}` dan
 * menormalkan balasan `GET /session/status/{sessionId}` menjadi {@see Session}.
 *
 * Istilah wwebjs untuk "sesi" adalah *session*, dan namanya ditentukan
 * pemanggil — bukan diterbitkan server. Karena itu nama session masuk akal
 * dibaca dari `WHATSAPP_SESSION` seperti OpenWA, dan nama itulah yang muncul di
 * seluruh URL.
 */
final class WwebjsSession
{
    /**
     * Nilai `state` yang berarti sesi siap mengirim pesan. Diambil dari
     * `Client.getState()` whatsapp-web.js; nilai lain (`TIMEOUT`, `CONFLICT`,
     * `UNPAIRED`, …) berarti belum siap.
     */
    public const CONNECTED = 'CONNECTED';

    /**
     * Nama session dari opsi pemanggil, atau dari konfigurasi.
     *
     * Middleware wwebjs-api menolak nama di luar `[A-Za-z0-9_-]` dengan HTTP
     * 422, jadi ditolak di sini dengan pesan yang menyebut aturannya —
     * bukan meneruskan 422 yang harus ditebak.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `id` (alias `name`)
     * @param string              $default Dipakai kalau `id` tidak diberikan —
     *                                     biasanya dari `WHATSAPP_SESSION`
     *
     * @throws ConfigurationException
     */
    public static function name(array $options, string $default = ''): string
    {
        $name = Text::first($options['id'] ?? null, $options['name'] ?? null, $default);

        if ($name === '') {
            throw new ConfigurationException(
                "Wwebjs membutuhkan nama session: isi WHATSAPP_SESSION atau kirim ['id' => ...]"
            );
        }

        if (preg_match('/^[\w-]+$/', $name) !== 1) {
            throw new ConfigurationException(
                "Nama session Wwebjs hanya boleh huruf, angka, garis bawah, dan tanda minus, diberikan: {$name}"
            );
        }

        return $name;
    }

    /**
     * Body `POST /session/start/{sessionId}`.
     *
     * Endpoint ini menerima `webhookUrl` opsional — itu cara wwebjs mengabari
     * QR dan pesan masuk. Bila tidak diisi, webhook bawaan server
     * (`BASE_WEBHOOK_URL`) yang berlaku.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null Null bila tidak ada yang perlu dikirim,
     *                                  supaya body request-nya benar-benar kosong
     */
    public static function payload(array $options): ?array
    {
        $webhook = Text::of($options['webhookUrl'] ?? $options['webhook_url'] ?? null);

        return $webhook === '' ? null : ['webhookUrl' => $webhook];
    }

    /**
     * Terima balasan `POST /session/start/{sessionId}`.
     *
     * Balasannya hanya `{success, message}`, tanpa keadaan sambungan: servernya
     * baru membalas setelah Chromium selesai dimuat, dan saat itu sesinya masih
     * menunggu dipindai. Karena itu `connected` selalu false di sini — keadaan
     * sebenarnya dibaca lewat {@see Wwebjs::checkSession()}.
     *
     * @param array<string,mixed> $body
     */
    public static function fromStart(array $body, string $sessionId): Session
    {
        return new Session(
            provider: Wwebjs::NAME,
            id: $sessionId,
            status: ($body['success'] ?? false) === true ? 'starting' : 'unknown',
            connected: false,
            raw: $body,
        );
    }

    /**
     * Terima balasan `GET /session/status/{sessionId}`.
     *
     * Balasannya `{success, state, message}`, dan `state` hanya terisi saat
     * sesinya benar-benar ada. Kalau kosong, `message` yang menjelaskan
     * sebabnya (`session_not_found`, `session_not_connected`), jadi itulah yang
     * dipakai sebagai status.
     *
     * @param array<string,mixed> $body
     */
    public static function fromStatus(array $body, string $sessionId): Session
    {
        $state = Text::of($body['state'] ?? null);
        $message = Text::of($body['message'] ?? null);

        return new Session(
            provider: Wwebjs::NAME,
            id: $sessionId,
            status: $state !== '' ? $state : $message,
            connected: Text::equalsAny($state, self::CONNECTED),
            raw: $body,
        );
    }
}
