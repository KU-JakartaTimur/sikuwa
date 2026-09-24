<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\ApiMe;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan teks untuk ApiMe.
 *
 * ApiMe menerima nomor dalam format internasional tanpa tanda plus
 * (mis. "6281234567890") dan menambahkan sendiri sufiks "@s.whatsapp.net".
 * JID grup ("...@g.us") harus ditulis lengkap dan diteruskan apa adanya.
 */
final class ApiMeMessage
{
    public string $to;

    public function __construct(string $destination, public readonly string $message)
    {
        $this->to = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);
    }

    /** Payload untuk POST /api/instances/{id}/messages/text */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'text' => $this->message,
        ];
    }

    /**
     * Idempotency-Key deterministik: dua permintaan dengan instance, tujuan,
     * dan isi yang persis sama dalam 24 jam hanya menghasilkan satu pesan
     * WhatsApp. Berguna kalau kartu ter-scan dua kali beruntun.
     */
    public function idempotencyKey(string $instanceId): string
    {
        return 'siku-' . hash('sha256', $instanceId . '|' . $this->to . '|' . $this->message);
    }
}
