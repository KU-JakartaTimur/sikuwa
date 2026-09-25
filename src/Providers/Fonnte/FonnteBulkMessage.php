<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;

/**
 * Kumpulan pesan untuk satu request Fonnte.
 *
 * Fonnte menerima seluruh pesan sekaligus dalam satu field `data`, jadi bentuk
 * bulk-nya hanya soal menyusun array — tidak ada endpoint terpisah.
 */
final class FonnteBulkMessage
{
    /** @var array<int,array{target:string,message:string,delay:string}> */
    private array $messageWhatsapp = [];

    /**
     * @param array<int,array{destination:string,message:string,delay:?int}> $messages
     *        `delay` sudah diselesaikan
     *        {@see \Sikuwa\Whatsapp\Providers\AbstractProvider::plan()},
     *        termasuk bagian pacing-nya.
     *
     * @throws ConfigurationException
     */
    public function __construct(array $messages)
    {
        foreach ($messages as $i => $message) {
            if (! isset($message['destination'], $message['message'])) {
                throw new ConfigurationException(
                    "Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $this->messageWhatsapp[] = (new FonnteMessage(
                (string) $message['destination'],
                (string) $message['message'],
                $message['delay'] ?? FonnteMessage::DEFAULT_DELAY
            ))->toArray();
        }
    }

    /** @return array<int,array{target:string,message:string,delay:string}> */
    public function toArray(): array
    {
        return $this->messageWhatsapp;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }
}
