<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Wuzapi;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Gateway wuzapi (https://github.com/asternic/wuzapi), WhatsApp self-hosted
 * berbasis Go + WhatsMeow.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = Wuzapi
 *   WHATSAPP_TOKEN    = token milik user/sesi (header `Token`)
 *   WHATSAPP_URL      = base URL instance, mis. https://v4.example.com
 *
 * Tidak butuh `WHATSAPP_INSTANCE`: tokennya sendiri yang menentukan sesi
 * WhatsApp mana yang dipakai, jadi URL-nya tanpa id instance.
 *
 * Catatan: README wuzapi menyebut endpoint user memakai header
 * `Authorization`. Kodenya tidak demikian — `authalice()` membaca header
 * `token`, sedangkan `Authorization` hanya dibaca `authadmin()` untuk endpoint
 * `/admin`. Yang dipakai di sini adalah yang sesuai kode.
 */
final class Wuzapi extends AbstractProvider
{
    public const NAME = 'Wuzapi';

    public const DEFAULT_URL = 'https://wuzapi.whatsapp.com';

    private string $baseUrl;

    /**
     * @param array{
     *     token?:string, url?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $this->baseUrl = $this->config->url(self::DEFAULT_URL);
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    /**
     * Kirim pesan lewat wuzapi.
     *
     * wuzapi tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu. Jeda antar pesan dihormati lewat kunci `delay`, sehingga mengirim
     * banyak pesan MEMBLOKIR pemanggil selama total jeda itu. Halaman scan
     * hanya mengirim satu pesan.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function sendMessage(array|string $message): string
    {
        $items = $this->parse($message);

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        $prepared = $this->compose(fn (): array => $this->build($items));

        return \count($prepared) === 1
            ? $this->sendText($prepared[0]['message'])
            : $this->sendSequentially(
                $prepared,
                fn (WuzapiMessage $m): string => $this->sendText($m),
                static fn (WuzapiMessage $m): string => $m->phone
            );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int}> $items
     * @return array<int,array{message:WuzapiMessage,delay:int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $prepared = [];

        foreach ($items as $i => $item) {
            $message = new WuzapiMessage($item['destination'], $item['message']);

            if ($message->phone === '') {
                throw new ConfigurationException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $prepared[] = ['message' => $message, 'delay' => $item['delay'] ?? 0];
        }

        return $prepared;
    }

    /** POST /chat/send/text */
    private function sendText(WuzapiMessage $message): string
    {
        $response = $this->http->post(
            "{$this->baseUrl}/chat/send/text",
            (string) json_encode($message->toArray()),
            [
                'Content-Type' => 'application/json',
                'Token' => $this->getToken(),
            ]
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        $body = $this->requireJson($body, $response->status);

        // wuzapi membalas HTTP 200 pada hampir semua jalur, tapi amplopnya
        // sendiri punya penanda `success` yang lebih dipercaya.
        if (($body['success'] ?? false) !== true) {
            $status = (int) ($body['code'] ?? $response->status);

            throw ApiException::classify(
                $status,
                $this->describe($status, $body),
                $body,
                $this->kind($body)
            );
        }

        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        return 'Sukses, messageId: ' . ($data['Id'] ?? '-');
    }

    /**
     * Amplop error wuzapi: `{"code":N,"error":"...","success":false}`
     * (lihat `server.Respond` di handlers.go).
     */
    protected function describe(int $status, ?array $body): string
    {
        $detail = $this->detail($body);

        $konteks = match (true) {
            $status === 401 => 'token salah, cek WHATSAPP_TOKEN',
            str_contains($detail, 'no session') => 'sesi WhatsApp belum tersambung, scan QR di /login',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }
}
