<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Contracts\Whatsapp;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Exceptions\TimeoutException;
use Sikuwa\Whatsapp\Exceptions\WhatsappException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Http\HttpResponse;

/**
 * Bagian yang sama pada semua gateway: pemegang konfigurasi, penyunting bentuk
 * pesan, dan penerjemah respons menjadi exception.
 *
 * Setiap provider mengurus tiga hal sendiri — URL endpoint, header autentikasi,
 * dan amplop errornya. Sisanya ada di sini.
 */
abstract class AbstractProvider implements Whatsapp
{
    protected Config $config;
    protected HttpExecutor $http;

    /**
     * @param array{
     *     token?:string, url?:string, session?:string, instance?:string,
     *     timeout?:int|float, tokens?:array<string,string>, provider?:string,
     *     headers?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        $this->config = Config::from($options);
        $this->http = $http ?? new HttpExecutor(
            timeout: $this->config->timeout(),
            defaultHeaders: $this->config->headers(),
        );
    }

    public function getToken(): string
    {
        return $this->config->token($this->getProvider());
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function executor(): HttpExecutor
    {
        return $this->http;
    }

    /**
     * Normalisasi bentuk pesan menjadi list yang seragam.
     *
     * `delay` dibiarkan null bila pemanggil tidak mengisinya, supaya tiap
     * gateway bisa memakai nilai bawaannya sendiri (Fonnte 2 detik, OpenWA 3
     * detik, sisanya 0). Mengisinya dengan 0 berarti "tanpa jeda".
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     * @return array<int,array{destination:string,message:string,delay:?int}>
     *
     * @throws ConfigurationException
     */
    protected function parse(array|string $message): array
    {
        if (\is_string($message)) {
            throw new ConfigurationException(
                'Format pesan tidak valid: ' . $this->getProvider() . ' membutuhkan array pesan'
            );
        }

        // Bulk kalau elemen pertama sendiri berupa array pesan.
        $isBulk = isset($message[0]) && \is_array($message[0]);
        $messages = $isBulk ? $message : [$message];
        $items = [];

        foreach ($messages as $i => $item) {
            if (! \is_array($item) || ! isset($item['destination'], $item['message'])) {
                throw new ConfigurationException(
                    "Gagal menyusun pesan: Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $items[] = [
                'destination' => (string) $item['destination'],
                'message' => (string) $item['message'],
                'delay' => isset($item['delay']) ? max(0, (int) $item['delay']) : null,
            ];
        }

        return $items;
    }

    /**
     * Jalankan penyusun payload, ubah kegagalannya menjadi
     * {@see ConfigurationException} dengan awalan yang seragam.
     */
    protected function compose(callable $factory): mixed
    {
        try {
            return $factory();
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConfigurationException('Gagal menyusun pesan: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Baca body respons, dan lempar exception kalau request-nya tidak sampai.
     *
     * Sengaja tidak melempar untuk status 4xx/5xx: body-nya masih dibutuhkan
     * provider untuk menyusun pesan penolakan yang berguna.
     *
     * @return array<string,mixed>|null
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function read(HttpResponse $response): ?array
    {
        if ($response->timedOut) {
            throw new TimeoutException($this->http->timeout(), $response->error);
        }

        if ($response->error !== '') {
            throw new ApiException(
                'Gagal menghubungi ' . $this->getProvider() . ': ' . $response->error,
                0
            );
        }

        return $response->json();
    }

    /**
     * Lempar exception bertipe untuk respons non-2xx.
     *
     * @param array<string,mixed>|null $body
     */
    protected function reject(HttpResponse $response, ?array $body): never
    {
        throw ApiException::classify(
            $response->status,
            $this->describe($response->status, $body),
            $body,
            $this->kind($body)
        );
    }

    /**
     * Status 2xx dengan body yang tak bisa di-decode bukan bukti pesan terkirim,
     * jadi jangan pernah dilaporkan sebagai sukses.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    protected function requireJson(?array $body, int $status): array
    {
        if ($body === null) {
            throw new ApiException(
                'Respons ' . $this->getProvider() . " tidak valid (HTTP {$status})",
                $status
            );
        }

        return $body;
    }

    /**
     * Kirim beberapa pesan satu per satu, dengan jeda di antara pengiriman.
     *
     * Dipakai gateway tanpa endpoint batch (ApiMe, Evolution API, wuzapi).
     * Kegagalan satu pesan tidak menghentikan sisanya — semuanya dikumpulkan
     * lalu dilempar sebagai satu {@see ApiException} supaya pemanggil melihat
     * gambaran lengkapnya, bukan cuma kegagalan pertama.
     *
     * Jeda dihormati dengan `sleep()`, jadi mengirim banyak pesan akan
     * MEMBLOKIR pemanggil selama total jeda tersebut.
     *
     * @param array<int,array{message:mixed,delay:int}> $items
     * @param callable(mixed):void                      $send
     * @param callable(mixed):string                    $label Penanda pesan untuk pesan error.
     *
     * @throws ApiException
     */
    protected function sendSequentially(array $items, callable $send, callable $label): string
    {
        $sukses = 0;
        $gagal = [];
        $terakhir = null;

        foreach ($items as $i => $item) {
            if ($i > 0 && $item['delay'] > 0) {
                sleep($item['delay']);
            }

            try {
                $send($item['message']);
                $sukses++;
            } catch (WhatsappException $e) {
                $terakhir = $e;
                $gagal[] = $label($item['message']) . ': ' . $e->getMessage();
            }
        }

        $total = \count($items);

        if ($gagal !== []) {
            throw new ApiException(
                "{$sukses}/{$total} pesan terkirim. Gagal: " . implode(' | ', $gagal),
                $terakhir instanceof ApiException ? $terakhir->getStatus() : 0,
                null,
                null,
                $terakhir
            );
        }

        return "Sukses, {$sukses}/{$total} pesan terkirim";
    }

    /**
     * Rangkai pesan penolakan dari amplop body milik gateway.
     *
     * @param array<string,mixed>|null $body
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);
        $pesan = $this->getProvider() . " menolak pesan (HTTP {$status})";

        return $detail === '' ? $pesan : "{$pesan}: {$detail}";
    }

    /**
     * Ambil teks detail dari amplop body. Bentuknya berbeda-beda antar gateway
     * — OpenWA memakai amplop NestJS, Evolution API menyelipkan
     * `response.message`, ApiMe dan wuzapi memakai `error`, Fonnte `reason`.
     *
     * @param array<string,mixed>|null $body
     */
    protected function detail(?array $body): string
    {
        $candidates = [
            $body['response']['message'] ?? null,
            $body['message'] ?? null,
            $body['error'] ?? null,
            $body['reason'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (\is_array($candidate)) {
                // Kegagalan validasi per-field dikirim sebagai array.
                $flat = array_filter(
                    array_map(
                        static fn ($part) => \is_scalar($part) ? (string) $part : (json_encode($part) ?: ''),
                        $candidate
                    ),
                    static fn (string $part) => $part !== ''
                );

                if ($flat !== []) {
                    return implode('; ', $flat);
                }
            } elseif (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Penanda jenis error dari amplop body, bila gateway menyediakannya.
     *
     * @param array<string,mixed>|null $body
     */
    protected function kind(?array $body): ?string
    {
        $value = $body['error'] ?? $body['reason'] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
