<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\ApiMe;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Gateway ApiMe (https://github.com/open-apime/apime), WhatsApp self-hosted
 * berbasis Go + WhatsMeow.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = ApiMe
 *   WHATSAPP_TOKEN    = instance token (header `Authorization: Bearer ...`)
 *   WHATSAPP_URL_ApiMe = base URL instance, mis. https://v14.example.com
 *                       (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_INSTANCE = UUID instance yang sudah tersambung
 *
 * Catatan: ApiMe menuntut token BER-SCOPE INSTANCE. Token user (JWT login)
 * maupun API token global ditolak dengan HTTP 403 oleh endpoint pengiriman.
 * Ambil tokennya saat membuat instance, atau putar lewat
 * POST /api/instances/{id}/token/rotate.
 */
final class ApiMe extends AbstractProvider
{
    public const NAME = 'ApiMe';

    public const DEFAULT_URL = 'https://api-me.whatsapp.com';

    private string $baseUrl;
    private string $instanceId;

    /**
     * @param array{
     *     token?:string, url?:string, instance?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>,
     *     urls?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $url = $this->config->url(self::DEFAULT_URL, self::NAME);

        // Terima "http://host:8080" maupun "http://host:8080/api" tanpa jadi "/api/api".
        $this->baseUrl = preg_replace('#/api$#', '', $url) ?? $url;
        $this->instanceId = $this->config->instance();
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Kirim pesan lewat ApiMe.
     *
     * ApiMe tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu secara berurutan. Jeda antar pesan dihormati lewat kunci `delay`,
     * sehingga mengirim banyak pesan akan MEMBLOKIR pemanggil selama total
     * jeda tersebut. Pemakaian di halaman scan hanya mengirim satu pesan.
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

        if ($this->instanceId === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        $prepared = $this->compose(fn (): array => $this->build($items));

        return \count($prepared) === 1
            ? $this->sendText($prepared[0]['message'])
            : $this->sendSequentially(
                $prepared,
                fn (ApiMeMessage $message): string => $this->sendText($message),
                static fn (ApiMeMessage $message): string => $message->to
            );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int}> $items
     * @return array<int,array{message:ApiMeMessage,delay:int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $prepared = [];

        foreach ($items as $i => $item) {
            $message = new ApiMeMessage($item['destination'], $item['message']);

            if ($message->to === '') {
                throw new ConfigurationException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $prepared[] = ['message' => $message, 'delay' => $item['delay'] ?? 0];
        }

        return $prepared;
    }

    /** POST /api/instances/{instanceId}/messages/text */
    private function sendText(ApiMeMessage $message): string
    {
        $url = "{$this->baseUrl}/api/instances/" . rawurlencode($this->instanceId) . '/messages/text';

        $response = $this->http->post(
            $url,
            (string) json_encode($message->toArray()),
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getToken(),
                'Idempotency-Key' => $message->idempotencyKey($this->instanceId),
            ]
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        $body = $this->requireJson($body, $response->status);

        // Sukses dibungkus sebagai {"data": {...Message}}.
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        return 'Sukses, messageId: ' . ($data['whatsappId'] ?? $data['id'] ?? '-');
    }

    /**
     * ApiMe selalu memakai amplop error `{"error": "..."}` (internal/pkg/response).
     * Beberapa status punya arti khusus yang layak dijelaskan di log.
     */
    protected function describe(int $status, ?array $body): string
    {
        $konteks = match ($status) {
            403 => 'token harus instance token, bukan JWT user atau API token global',
            404 => 'instance tidak ditemukan, cek WHATSAPP_INSTANCE',
            409 => 'pengiriman dengan Idempotency-Key yang sama masih berjalan',
            422 => 'Idempotency-Key sudah dipakai untuk isi pesan yang berbeda',
            503 => 'sesi WhatsApp belum siap, tidak ada pesan yang terkirim',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }
}
