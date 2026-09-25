<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\ApiMe;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;

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

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->getToken()];
    }

    /**
     * Buat instance baru: `POST /api/instances`.
     *
     * Endpoint ini menuntut token **user** (JWT) atau API token. Token
     * ber-scope instance — yang dipakai untuk mengirim pesan — ditolak dengan
     * HTTP 403, jadi pembuatan instance biasanya dijalankan sekali dari
     * dashboard atau skrip admin, bukan dari jalur notifikasi.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `name`,
     *                                     `webhook_url`, `webhook_secret`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $payload = ApiMeSession::payload($options, $this->instanceId);
        $body = $this->postJson("{$this->baseUrl}/api/instances", $payload);

        return ApiMeSession::fromResponse($body, (string) ($payload['name'] ?? ''));
    }

    /**
     * Baca keadaan instance: `GET /api/instances/{id}`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $instanceId = $id ?? $this->instanceId;

        if ($instanceId === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        return ApiMeSession::fromResponse(
            $this->getJson("{$this->baseUrl}/api/instances/" . rawurlencode($instanceId)),
            $instanceId
        );
    }

    /**
     * Ambil QR instance: `GET /api/instances/{id}/qr`.
     *
     * Inilah QR yang dipindai untuk menyambungkan instance yang baru dibuat.
     * ApiMe tidak mendokumentasikan skema balasannya — OpenAPI-nya hanya
     * menyebut "QR code base64" — jadi pembacaannya diserahkan ke
     * {@see ApiMeShowQr}.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $instanceId = $id ?? $this->instanceId;

        if ($instanceId === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        return ApiMeShowQr::fromResponse(
            $this->getJson("{$this->baseUrl}/api/instances/" . rawurlencode($instanceId) . '/qr'),
            $instanceId
        );
    }

    /**
     * Kirim pesan lewat ApiMe.
     *
     * ApiMe tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu secara berurutan. Jeda antar pesan dihormati lewat kunci `delay`
     * atau, bila tidak diisi, lewat pacing (`WHATSAPP_PACING_*`), sehingga
     * mengirim banyak pesan akan MEMBLOKIR pemanggil selama total jeda
     * tersebut. Pemakaian di halaman scan hanya mengirim satu pesan.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function sendMessage(array|string $message): string
    {
        $items = $this->plan($message);

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
     * @return array<int,array{message:ApiMeMessage,delay:?int}>
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

            // `delay` dibiarkan null supaya sendSequentially() bisa mengisinya
            // dari pacing; angka 0 tetap berarti "tanpa jeda".
            $prepared[] = ['message' => $message, 'delay' => $item['delay']];
        }

        return $prepared;
    }

    /**
     * Kirim satu berkas lewat ApiMe.
     *
     * ApiMe menuntut berkasnya diunggah sebagai multipart, bukan dikirim
     * sebagai base64 di dalam JSON, jadi isinya diubah dulu menjadi byte
     * mentah. Ada dua endpoint terpisah dengan nama kolom yang berbeda:
     * gambar memakai `type=image`, dokumen memakai `fileName`.
     *
     * URL publik tidak bisa dipakai di sini — ApiMe tidak mengunduh apa pun
     * sendiri, ia hanya menerima unggahan.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        if ($this->instanceId === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        if ($file->isUrl()) {
            throw new ConfigurationException(
                'ApiMe menerima berkasnya sebagai unggahan biner, bukan URL: '
                . 'unduh berkasnya lebih dulu, lalu kirim isinya sebagai base64 atau data URI'
            );
        }

        $to = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);

        if ($to === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        if ($file->isImage()) {
            $endpoint = 'messages/media';
            $fields = ['to' => $to, 'type' => 'image'];
        } else {
            $endpoint = 'messages/document';
            $fields = ['to' => $to, 'fileName' => $file->filename];
        }

        if ($caption !== '') {
            $fields['caption'] = $caption;
        }

        $parts = [];

        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }

        $parts[] = [
            'name' => 'file',
            'contents' => $file->bytes(),
            'filename' => $file->filename,
            'headers' => ['Content-Type' => $file->mime],
        ];

        $body = $this->postMultipartJson(
            "{$this->baseUrl}/api/instances/" . rawurlencode($this->instanceId) . "/{$endpoint}",
            $parts
        );

        // Sukses dibungkus sebagai {"data": {...Message}}, sama seperti teks.
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        return 'Sukses, messageId: ' . ($data['whatsappId'] ?? $data['id'] ?? '-');
    }

    /** POST /api/instances/{instanceId}/messages/text */
    private function sendText(ApiMeMessage $message): string
    {
        $body = $this->postJson(
            "{$this->baseUrl}/api/instances/" . rawurlencode($this->instanceId) . '/messages/text',
            $message->toArray(),
            // Idempotency-Key deterministik per isi pesan, supaya kartu yang
            // ter-scan dua kali beruntun tidak jadi dua pesan.
            ['Idempotency-Key' => $message->idempotencyKey($this->instanceId)]
        );

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
