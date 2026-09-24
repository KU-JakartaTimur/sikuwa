<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Hasil satu request HTTP.
 *
 * `$error` hanya terisi bila request gagal di level transport — DNS, timeout,
 * TLS. Respons HTTP 4xx/5xx tetap dianggap "sampai", jadi `$error` kosong dan
 * statusnya ada di `$status`. Pemisahan ini disengaja: penolakan dari gateway
 * perlu diurai per gateway (amplop errornya berbeda-beda), sedangkan kegagalan
 * transport tidak.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $body,
        public readonly string $error = '',
        public readonly bool $timedOut = false
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode body sebagai array. Mengembalikan null kalau body kosong atau
     * bukan JSON objek/list yang valid.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === null || $this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
