<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\EvolutionAPI;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks untuk Evolution API.
 *
 * Kolom `number` berupa string bebas: nomor internasional tanpa tanda plus
 * (mis. "6281234567890") atau JID lengkap untuk grup ("...@g.us"), yang
 * diteruskan apa adanya.
 */
final class EvolutionAPIMessage
{
    public string $number;

    /** @param int $delay jeda sebelum kirim, dalam DETIK (dikonversi ke ms) */
    public function __construct(string $destination, public readonly string $message, public readonly int $delay = 0)
    {
        $this->number = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);
    }

    /** Payload untuk POST /message/sendText/{instance} */
    public function toArray(): array
    {
        $payload = [
            'number' => $this->number,
            'text' => $this->message,
        ];

        // Evolution menghitung delay dalam milidetik, sementara antarmuka
        // SDK ini memakai detik seperti Fonnte.
        if ($this->delay > 0) {
            $payload['delay'] = $this->delay * 1000;
        }

        return $payload;
    }
}
