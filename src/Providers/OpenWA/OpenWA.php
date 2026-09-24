<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Gateway OpenWA (https://github.com/rmyndharis/OpenWA), WhatsApp self-hosted
 * berbasis Node.js.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = OpenWA
 *   WHATSAPP_TOKEN    = API key OpenWA, dikirim sebagai header `X-API-Key`
 *   WHATSAPP_URL_OpenWA = base URL instance, mis. https://v15.example.com
 *                         (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_SESSION  = id session yang sudah di-start dan tersambung
 */
final class OpenWA extends AbstractProvider
{
    public const NAME = 'OpenWA';

    public const DEFAULT_URL = 'https://openwa.whatsapp.com';



    private string $baseUrl;
    private string $sessionId;

    /**
     * @param array{
     *     token?:string, url?:string, session?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>,
     *     urls?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $this->baseUrl = $this->config->url(self::DEFAULT_URL, self::NAME);
        $this->sessionId = $this->config->session();
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Kirim pesan lewat OpenWA.
     *
     * Satu pesan dikirim ke endpoint send-text (sinkron, balasannya messageId).
     * Lebih dari satu pesan dikirim ke send-bulk (asinkron, balasannya batchId).
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

        if ($this->sessionId === '') {
            throw new ConfigurationException('WHATSAPP_SESSION belum diisi di .env');
        }

        $delay = $items[0]['delay'] ?? OpenWABulkMessage::DEFAULT_DELAY;

        $bulk = $this->compose(fn (): OpenWABulkMessage => new OpenWABulkMessage($items, $delay));

        if ($bulk->count() === 0) {
            return 'Tidak ada pesan untuk dikirim';
        }

        return $bulk->count() === 1
            ? $this->sendText($bulk->first())
            : $this->sendBulk($bulk);
    }

    /** POST /api/sessions/{sessionId}/messages/send-text */
    private function sendText(OpenWAMessage $message): string
    {
        $response = $this->post('messages/send-text', $message->toArray());

        if (\is_string($response)) {
            return $response;
        }

        return 'Sukses, messageId: ' . ($response['messageId'] ?? '-');
    }

    /** POST /api/sessions/{sessionId}/messages/send-bulk */
    private function sendBulk(OpenWABulkMessage $bulk): string
    {
        $response = $this->post('messages/send-bulk', $bulk->toArray());

        if (\is_string($response)) {
            return $response;
        }

        $total = $response['totalMessages'] ?? $bulk->count();
        $batchId = $response['batchId'] ?? '-';

        return "Batch diterima ({$total} pesan), batchId: {$batchId}";
    }

    /**
     * Kirim request JSON ke OpenWA.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|string body terdecode kalau sukses, atau string penanda kegagalan
     */
    private function post(string $path, array $payload): array|string
    {
        $url = "{$this->baseUrl}/api/sessions/" . rawurlencode($this->sessionId) . "/{$path}";

        $response = $this->http->post(
            $url,
            (string) json_encode($payload),
            [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->getToken(),
            ]
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        return $this->requireJson($body, $response->status);
    }
}
