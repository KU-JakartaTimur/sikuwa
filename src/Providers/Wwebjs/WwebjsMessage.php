<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wwebjs;

use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Satu pesan untuk wwebjs.
 *
 * Berbeda dari gateway lain, wwebjs tidak punya endpoint terpisah per jenis
 * pesan: semuanya lewat `POST /client/sendMessage/{sessionId}`, dan yang
 * membedakan adalah kolom `contentType` —
 *
 * - `string` untuk teks biasa;
 * - `MessageMedia` untuk berkas yang ikut dikirim, berisi
 *   `{mimetype, data, filename}`;
 * - `MessageMediaFromURL` untuk berkas yang diunduh server wwebjs sendiri.
 *
 * Tujuan wajib berupa JID lengkap (`6281234567890@c.us`), sama seperti OpenWA.
 * JID grup (`...@g.us`) diteruskan apa adanya.
 */
final class WwebjsMessage
{
    /** @param array<string,mixed> $options Opsi pengiriman whatsapp-web.js */
    private function __construct(
        public readonly string $chatId,
        public readonly string $contentType,
        public readonly mixed $content,
        public readonly array $options = []
    ) {
    }

    /** Pesan teks biasa. */
    public static function text(string $destination, string $message): self
    {
        return new self(self::chatIdFor($destination), 'string', $message);
    }

    /**
     * Pesan bermedia, dengan caption opsional.
     *
     * Caption dititipkan di `options.caption`, bukan dijadikan isi pesan:
     * itulah kolom yang diteruskan whatsapp-web.js sebagai keterangan berkas.
     * Satu berkas berteks pengantar tetap satu request.
     */
    public static function media(string $destination, File $file, string $caption = ''): self
    {
        $options = $caption === '' ? [] : ['caption' => $caption];

        if ($file->isUrl()) {
            return new self(self::chatIdFor($destination), 'MessageMediaFromURL', $file->payload, $options);
        }

        return new self(self::chatIdFor($destination), 'MessageMedia', [
            'mimetype' => $file->mime,
            // whatsapp-web.js menerima base64 mentah, bukan data URI.
            'data' => $file->base64(),
            'filename' => $file->filename,
        ], $options);
    }

    /**
     * Tujuan sebagai JID, atau string kosong bila nomornya tidak bisa dibaca.
     *
     * Berbeda dari {@see PhoneNumber::toWid()} yang tetap membentuk `@c.us`
     * walau nomornya kosong: di sini kekosongan itu justru yang penting, karena
     * itulah yang membuat pemanggil tahu tujuannya tidak valid sebelum ada
     * request yang terkirim.
     */
    public static function chatIdFor(string $destination): string
    {
        $wid = PhoneNumber::toWid($destination);

        return str_starts_with($wid, '@') ? '' : $wid;
    }

    /**
     * Payload untuk POST /client/sendMessage/{sessionId}.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'chatId' => $this->chatId,
            'contentType' => $this->contentType,
            'content' => $this->content,
        ];

        // `options` sengaja tidak ikut kalau kosong: whatsapp-web.js menimpa
        // bawaannya dengan apa pun yang dikirim, termasuk objek kosong.
        if ($this->options !== []) {
            $payload['options'] = $this->options;
        }

        return $payload;
    }
}
