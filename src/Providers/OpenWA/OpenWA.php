<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\OpenWA;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;

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

    protected function authHeaders(): array
    {
        return ['X-API-Key' => $this->getToken()];
    }

    /**
     * Buat sesi baru: `POST /api/sessions`.
     *
     * Sesi baru berstatus `INITIALIZING`, jadi belum bisa dipakai mengirim.
     * Ambil QR-nya lewat `GET /api/sessions/{id}/qr`, lalu pantau dengan
     * {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `id`, `name`, `config`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $payload = OpenWASession::payload($options, $this->sessionId);
        $body = $this->postJson("{$this->baseUrl}/api/sessions", $payload);

        return OpenWASession::fromResponse($body, (string) ($payload['id'] ?? ''));
    }

    /**
     * Baca keadaan sesi: `GET /api/sessions/{id}`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $sessionId = $id ?? $this->sessionId;

        if ($sessionId === '') {
            throw new ConfigurationException('WHATSAPP_SESSION belum diisi di .env');
        }

        return OpenWASession::fromResponse(
            $this->getJson("{$this->baseUrl}/api/sessions/" . rawurlencode($sessionId)),
            $sessionId
        );
    }

    /**
     * Ambil QR sesi: `GET /api/sessions/{id}/qr`.
     *
     * Hanya menjawab saat sesi sedang menunggu dipindai. Sesi yang belum
     * mencapai `qr_ready` — termasuk yang sudah tersambung, dan yang sedang
     * menyambung ulang — ditolak dengan HTTP 400, sedangkan API key yang bukan
     * kunci berperan operator ditolak dengan HTTP 403. Karena satu status
     * dipakai untuk beberapa sebab sekaligus, pesan dari gateway sendiri yang
     * paling menentukan.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $sessionId = $id ?? $this->sessionId;

        if ($sessionId === '') {
            throw new ConfigurationException('WHATSAPP_SESSION belum diisi di .env');
        }

        return OpenWAShowQr::fromResponse(
            $this->getJson("{$this->baseUrl}/api/sessions/" . rawurlencode($sessionId) . '/qr'),
            $sessionId
        );
    }

    /**
     * Kirim pesan lewat OpenWA.
     *
     * Satu pesan dikirim ke endpoint send-text (sinkron, balasannya messageId).
     * Lebih dari satu pesan dikirim ke send-bulk (asinkron, balasannya batchId).
     *
     * Jeda antar pesan di send-bulk diisi dari `delay` pesan pertama, atau
     * dari pacing (`WHATSAPP_PACING_*`) bila tidak diisi. OpenWA juga
     * menambahkan pengacakan sendiri di sisinya (`randomizeDelay`).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function sendMessage(array|string $message): string
    {
        $plan = $this->plan($message);
        $items = $plan['items'];

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        if ($this->sessionId === '') {
            throw new ConfigurationException('WHATSAPP_SESSION belum diisi di .env');
        }

        $pacing = $this->pacing($plan['pacing']);

        // OpenWA hanya menerima satu angka jeda untuk seluruh batch, bukan jeda
        // per pesan. Yang paling mewakili siklus adalah jeda sebelum pesan
        // kedua: jeda pertama yang benar-benar terasa di antara dua pesan.
        $delay = $items[0]['delay'] ?? $pacing->delayFor(1) ?? OpenWABulkMessage::DEFAULT_DELAY;

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
        $body = $this->postJson($this->sessionUrl('messages/send-text'), $message->toArray());

        return 'Sukses, messageId: ' . ($body['messageId'] ?? '-');
    }

    /** POST /api/sessions/{sessionId}/messages/send-bulk */
    private function sendBulk(OpenWABulkMessage $bulk): string
    {
        $body = $this->postJson($this->sessionUrl('messages/send-bulk'), $bulk->toArray());

        $total = $body['totalMessages'] ?? $bulk->count();
        $batchId = $body['batchId'] ?? '-';

        return "Batch diterima ({$total} pesan), batchId: {$batchId}";
    }

    /** URL endpoint yang bernaung di bawah sesi, mis. `messages/send-text`. */
    private function sessionUrl(string $path): string
    {
        return "{$this->baseUrl}/api/sessions/" . rawurlencode($this->sessionId) . "/{$path}";
    }
}
