<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;

/**
 * Kumpulan pesan untuk endpoint send-bulk OpenWA.
 */
final class OpenWABulkMessage
{
    /** Batas item per batch sesuai spesifikasi OpenWA. */
    public const MAX_ITEMS = 100;

    /** Jeda bawaan antar pesan, dalam detik, bila pemanggil tidak mengisinya. */
    public const DEFAULT_DELAY = 3;

    /** @var OpenWAMessage[] */
    private array $items = [];

    /**
     * @param array<int,array{destination:string,message:string,delay:?int}> $messages
     *
     * @throws ConfigurationException
     */
    public function __construct(array $messages, private readonly int $delaySeconds = self::DEFAULT_DELAY)
    {
        foreach ($messages as $i => $message) {
            if (! isset($message['destination'], $message['message'])) {
                throw new ConfigurationException(
                    "Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $item = new OpenWAMessage(
                (string) $message['destination'],
                (string) $message['message']
            );

            if ($item->chatId === '' || str_starts_with($item->chatId, '@')) {
                throw new ConfigurationException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $this->items[] = $item;
        }

        if (\count($this->items) > self::MAX_ITEMS) {
            throw new ConfigurationException(
                'OpenWA membatasi ' . self::MAX_ITEMS . ' pesan per batch, diberikan ' . \count($this->items)
            );
        }
    }

    /** @return OpenWAMessage[] */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function first(): ?OpenWAMessage
    {
        return $this->items[0] ?? null;
    }

    public function toArray(): array
    {
        // delayBetweenMessages dalam milidetik, dibatasi 1000-60000 oleh OpenWA
        $delayMs = min(60000, max(1000, $this->delaySeconds * 1000));

        return [
            'messages' => array_map(static fn (OpenWAMessage $m): array => $m->toBulkItem(), $this->items),
            'options' => [
                'delayBetweenMessages' => $delayMs,
                'randomizeDelay' => true,
                'stopOnError' => false,
            ],
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }
}
