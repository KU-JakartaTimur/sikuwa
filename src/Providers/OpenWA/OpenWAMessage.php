<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks untuk OpenWA.
 *
 * Nomor tujuan dinormalisasi menjadi WID (mis. "6281234567890@c.us") karena
 * OpenWA menolak nomor mentah.
 */
final class OpenWAMessage
{
    public const MAX_LENGTH = 4096;

    public string $chatId;

    public function __construct(string $destination, public readonly string $message)
    {
        $this->chatId = PhoneNumber::toWid($destination);
    }

    /** Payload untuk POST .../messages/send-text */
    public function toArray(): array
    {
        return [
            'chatId' => $this->chatId,
            'text' => mb_substr($this->message, 0, self::MAX_LENGTH),
        ];
    }

    /** Payload untuk satu item di dalam POST .../messages/send-bulk */
    public function toBulkItem(): array
    {
        return [
            'chatId' => $this->chatId,
            'type' => 'text',
            'content' => ['text' => mb_substr($this->message, 0, self::MAX_LENGTH)],
        ];
    }
}
