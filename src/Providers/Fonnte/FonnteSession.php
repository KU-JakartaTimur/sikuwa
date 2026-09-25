<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\PhoneNumber;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Perangkat Fonnte — menyusun payload `POST /add-device` dan menormalkan
 * balasan Device API menjadi {@see Session}.
 *
 * Istilah Fonnte untuk "sesi" adalah *device* (perangkat). Dua hal yang mudah
 * tertukar dan sengaja dibedakan di sini:
 *
 * - **Token perangkat** — yang dipakai mengirim pesan, tersimpan di
 *   `WHATSAPP_TOKEN`. Perangkat yang baru dibuat menerbitkannya, dan nilainya
 *   dikembalikan lewat {@see Session::$token}.
 * - **Token akun** — yang dipakai mengurus perangkat. Device API menolak
 *   token perangkat dengan `"unknown user"`.
 *
 * `POST /add-device` mengembalikan `status` sebagai *keberhasilan permintaan*
 * (boolean), bukan keadaan sambungan — perangkat yang baru dibuat belum
 * tersambung. `POST /get-devices` baru memakai `status` sebagai keadaan
 * sesungguhnya (`connect` / `disconnect`). Keduanya punya penormal sendiri
 * supaya perbedaan itu tidak hilang.
 */
final class FonnteSession
{
    /** Nilai `status` perangkat yang berarti siap mengirim pesan. */
    public const CONNECTED = 'connect';

    /**
     * Payload untuk POST /add-device.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `name` (wajib),
     *                                     `device` (wajib), dan opsional
     *                                     `autoread`, `personal`, `group`.
     * @return array<string,mixed>
     *
     * @throws ConfigurationException
     */
    public static function payload(array $options): array
    {
        $name = Text::of($options['name'] ?? null);

        if ($name === '') {
            throw new ConfigurationException("Fonnte membutuhkan nama perangkat: kirim ['name' => ...]");
        }

        $device = PhoneNumber::normalize(Text::of($options['device'] ?? null));

        if ($device === '') {
            throw new ConfigurationException("Fonnte membutuhkan nomor perangkat: kirim ['device' => '0812...']");
        }

        $payload = ['name' => $name, 'device' => $device];

        // Fonnte membaca flag ini sebagai string "true"/"false", bukan boolean.
        foreach (['autoread', 'personal', 'group'] as $flag) {
            if (\array_key_exists($flag, $options)) {
                $payload[$flag] = $options[$flag] ? 'true' : 'false';
            }
        }

        return $payload;
    }

    /**
     * Terima balasan `POST /add-device`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromCreated(array $body): Session
    {
        return new Session(
            provider: Fonnte::NAME,
            id: Text::of($body['device'] ?? null),
            // `status` di sini adalah keberhasilan permintaan, jadi sesi belum
            // bisa dipakai sampai perangkatnya tersambung.
            status: ($body['status'] ?? false) === true ? 'created' : 'unknown',
            connected: false,
            token: Text::of($body['token'] ?? null),
            phoneNumber: Text::of($body['device'] ?? null),
            profileName: Text::of($body['name'] ?? null),
            raw: $body,
        );
    }

    /**
     * Terima satu entri dari `POST /get-devices`.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $body Amplop lengkap, untuk {@see Session::$raw}.
     */
    public static function fromDevice(array $device, array $body = []): Session
    {
        $status = Text::of($device['status'] ?? null);

        return new Session(
            provider: Fonnte::NAME,
            id: Text::of($device['device'] ?? null),
            status: $status,
            connected: strcasecmp($status, self::CONNECTED) === 0,
            token: Text::of($device['token'] ?? null),
            phoneNumber: Text::of($device['device'] ?? null),
            profileName: Text::of($device['name'] ?? null),
            raw: $body === [] ? $device : $body,
        );
    }

    /**
     * Cari satu perangkat di dalam balasan `POST /get-devices`.
     *
     * Pencocokan mencoba nomor, nama, lalu token sekaligus: pemanggil biasanya
     * hanya punya salah satunya, dan menuntut satu bentuk tertentu akan membuat
     * pemanggil menyesuaikan diri pada detail internal Fonnte.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    public static function findDevice(array $body, string $needle): ?array
    {
        $devices = $body['data'] ?? null;

        if ($needle === '' || ! \is_array($devices)) {
            return null;
        }

        foreach ($devices as $device) {
            if (! \is_array($device)) {
                continue;
            }

            foreach (['device', 'name', 'token'] as $key) {
                if (Text::of($device[$key] ?? null) === $needle) {
                    return $device;
                }
            }
        }

        return null;
    }
}
