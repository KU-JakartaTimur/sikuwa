<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

/**
 * Satu pesan teks untuk Fonnte.
 *
 * Fonnte menerima `delay` sebagai string, bukan angka, dan satuannya detik.
 */
final class FonnteMessage
{
    /** Jeda bawaan antar pesan, dalam detik, bila pemanggil tidak mengisinya. */
    public const DEFAULT_DELAY = 2;

    public function __construct(
        public readonly string $target,
        public readonly string $message,
        public readonly int $delay = self::DEFAULT_DELAY
    ) {
    }

    /** @return array{target:string,message:string,delay:string} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'message' => $this->message,
            'delay' => (string) $this->delay,
        ];
    }
}
