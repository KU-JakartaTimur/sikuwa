<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\EvolutionAPI;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Qr;
use Sikuwa\Whatsapp\Support\Text;

/**
 * Instance Evolution API — menyusun payload `POST /instance/create` dan
 * menormalkan balasan menjadi {@see Session}.
 *
 * Baik `POST /instance/create` maupun `GET /instance/connectionState/{instance}`
 * membungkus datanya di dalam kunci `instance`, jadi satu penormal cukup untuk
 * keduanya. Bedanya hanya nama field status: create memakai `status`, sedangkan
 * connectionState memakai `state` — dengan nilai yang sama
 * (`open`, `connecting`, `close`, `refused`).
 */
final class EvolutionAPISession
{
    /** State Evolution API yang berarti sesi siap mengirim pesan. */
    public const CONNECTED = 'open';

    /**
     * Payload untuk POST /instance/create.
     *
     * @param array<string,mixed> $options     Kunci yang dikenali: `instanceName`
     *                                         (alias `name`), `token`, `integration`,
     *                                         `webhook`, `events`, `qrcode`.
     * @param string              $defaultName Dipakai kalau nama tidak diberikan —
     *                                         biasanya dari `WHATSAPP_INSTANCE`.
     * @return array<string,mixed>
     *
     * @throws ConfigurationException
     */
    public static function payload(array $options, string $defaultName = ''): array
    {
        $name = Text::first($options['instanceName'] ?? null, $options['name'] ?? null, $defaultName);

        if ($name === '') {
            throw new ConfigurationException(
                "Evolution API membutuhkan nama instance: isi WHATSAPP_INSTANCE atau kirim ['instanceName' => ...]"
            );
        }

        // Evolution menolak nama bersimbol ("use only non-accented lowercase
        // alphabetic characters or numbers"). Menangkapnya di sini membuat
        // kesalahannya jelas, bukan HTTP 400 yang harus ditebak.
        if (preg_match('/^[a-z0-9]+$/', $name) !== 1) {
            throw new ConfigurationException(
                "Nama instance Evolution API hanya boleh huruf kecil dan angka, diberikan: {$name}"
            );
        }

        $payload = [
            'instanceName' => $name,
            'qrcode' => (bool) ($options['qrcode'] ?? true),
        ];

        foreach (['token', 'integration', 'webhook'] as $key) {
            $value = Text::of($options[$key] ?? null);

            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        if (\is_array($options['events'] ?? null) && $options['events'] !== []) {
            $payload['events'] = $options['events'];
        }

        return $payload;
    }

    /**
     * Terima balasan `POST /instance/create` maupun
     * `GET /instance/connectionState/{instance}`.
     *
     * @param array<string,mixed> $body
     */
    public static function fromResponse(array $body, string $fallbackId = ''): Session
    {
        $instance = \is_array($body['instance'] ?? null) ? $body['instance'] : [];
        $qrcode = \is_array($body['qrcode'] ?? null) ? $body['qrcode'] : [];

        $status = Text::first($instance['state'] ?? null, $instance['status'] ?? null);

        // `POST /instance/create` menerbitkan API key instance di `hash` —
        // sebagian versi mengirim objek `{apikey: ...}`, sebagian string.
        $hash = $body['hash'] ?? null;

        return new Session(
            provider: EvolutionAPI::NAME,
            id: Text::first($instance['instanceName'] ?? null, $fallbackId),
            status: $status,
            connected: Text::equalsAny($status, self::CONNECTED),
            // Saat instance masih `close`, QR-nya ada di dalam blok `qrcode`;
            // `GET /instance/connect` mengembalikannya di akar body.
            qr: Qr::dataUri(Text::first($qrcode['base64'] ?? null, $body['base64'] ?? null)),
            token: \is_array($hash) ? Text::of($hash['apikey'] ?? null) : Text::of($hash),
            phoneNumber: Text::of($instance['owner'] ?? null),
            profileName: Text::first($instance['profileName'] ?? null, $instance['profile_name'] ?? null),
            raw: $body,
        );
    }
}
