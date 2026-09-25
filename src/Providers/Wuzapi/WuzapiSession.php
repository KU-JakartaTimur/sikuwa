<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wuzapi;

use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Sesi wuzapi — menyusun payload `POST /session/connect` dan menormalkan
 * balasan menjadi {@see Session}.
 *
 * wuzapi tidak mengenal id sesi: token-nya sendiri yang menentukan sesi mana
 * yang dipakai. Karena itu `createSession()` di sini berarti *menyambungkan*
 * sesi (setara tombol "connect" di dashboard), bukan mendaftarkan yang baru —
 * pendaftaran user/sesi adalah urusan endpoint `/admin`, di luar SDK ini.
 *
 * Dua endpoint sesi memakai amplop `data` yang berbeda isinya, jadi keduanya
 * punya penormal sendiri: {@see self::fromConnect()} untuk balasan
 * `/session/connect`, dan {@see self::fromStatus()} untuk `/session/status`.
 */
final class WuzapiSession
{
    /** Jenis event yang dilanggankan bila pemanggil tidak memilih. */
    public const DEFAULT_EVENTS = ['Message'];

    /**
     * Payload untuk POST /session/connect.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `subscribe`
     *                                     (list event), `immediate` (bool).
     * @return array<string,mixed>
     */
    public static function payload(array $options): array
    {
        $subscribe = $options['subscribe'] ?? null;

        if (! \is_array($subscribe) || $subscribe === []) {
            $subscribe = self::DEFAULT_EVENTS;
        }

        return [
            'Subscribe' => array_values(array_map(static fn (mixed $e): string => Text::of($e), $subscribe)),
            // Bawaannya Immediate, supaya pemanggil tidak tertahan ~10 detik
            // menunggu wuzapi memverifikasi login. Keadaan sebenarnya dibaca
            // lewat checkSession().
            'Immediate' => (bool) ($options['immediate'] ?? true),
        ];
    }

    /**
     * Terima balasan `POST /session/connect`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromConnect(array $body): Session
    {
        $data = self::data($body);
        $connected = ($body['success'] ?? false) === true;

        return new Session(
            provider: Wuzapi::NAME,
            // wuzapi tidak punya id sesi; JID inilah identitas sesi yang tersambung.
            id: Text::of($data['jid'] ?? null),
            status: $connected ? 'connected' : 'disconnected',
            connected: $connected,
            raw: $body,
        );
    }

    /**
     * Terima balasan `GET /session/status`.
     *
     * `Connected` berarti websocket sudah terbentuk, `LoggedIn` berarti QR-nya
     * sudah dipindai dan sesi benar-benar siap dipakai. Yang menentukan bisa
     * tidaknya mengirim pesan adalah `LoggedIn`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromStatus(array $body): Session
    {
        $data = self::data($body);
        $connected = ($data['Connected'] ?? false) === true;
        $loggedIn = ($data['LoggedIn'] ?? false) === true;

        return new Session(
            provider: Wuzapi::NAME,
            status: match (true) {
                $loggedIn => 'connected',
                $connected => 'connecting',
                default => 'disconnected',
            },
            connected: $loggedIn,
            raw: $body,
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function data(array $body): array
    {
        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : [];
    }
}
