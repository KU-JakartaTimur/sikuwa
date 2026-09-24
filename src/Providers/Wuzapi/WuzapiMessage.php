<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wuzapi;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks untuk wuzapi.
 *
 * Kunci payload memakai PascalCase (`Phone`, `Body`) sesuai struct Go-nya.
 * Kolom Phone menerima nomor polos (awalan "+" dibuang server) atau JID
 * lengkap yang mengandung "@", yang diteruskan apa adanya.
 */
final class WuzapiMessage
{
    public string $phone;

    public function __construct(string $destination, public readonly string $message)
    {
        $this->phone = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);
    }

    /** Payload untuk POST /chat/send/text */
    public function toArray(): array
    {
        return [
            'Phone' => $this->phone,
            'Body' => $this->message,
        ];
    }
}
