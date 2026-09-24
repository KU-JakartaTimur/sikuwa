<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\Fonnte;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Gateway Fonnte (https://fonnte.com), layanan WhatsApp berbayar berbasis cloud.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = Fonnte
 *   WHATSAPP_TOKEN    = token perangkat Fonnte, dikirim sebagai header `Authorization`
 *
 * Berbeda dari gateway self-hosted lain di SDK ini, Fonnte adalah layanan
 * pihak ketiga dengan satu endpoint tetap. Karena itu `WHATSAPP_URL` **tidak**
 * dibaca di sini — kalau dibaca, satu nilai `WHATSAPP_URL` yang dipakai
 * gateway lain akan mengalihkan pengiriman Fonnte ke host yang salah.
 * Override URL hanya lewat opsi `url` yang eksplisit.
 */
final class Fonnte extends AbstractProvider
{
    public const NAME = 'Fonnte';

    public const DEFAULT_URL = 'https://api.fonnte.com/send';

    private string $urlApi;

    /**
     * @param array{
     *     token?:string, url?:string, timeout?:int|float,
     *     tokens?:array<string,string>, headers?:array<string,string>
     * }|Config|null $options
     */
    public function __construct(array|Config|null $options = null, ?HttpExecutor $http = null)
    {
        parent::__construct($options, $http);

        $this->urlApi = $this->config->explicitUrl() ?? self::DEFAULT_URL;
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    /**
     * Kirim pesan ke Fonnte.
     *
     * Fonnte selalu membalas HTTP 200; keberhasilan sebenarnya ada di field
     * `status`. Karena itu respons 2xx dengan `status` kosong tetap dilaporkan
     * sebagai kegagalan.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *
     * @return string `"Sukses: <detail>"` atau `"Sukses"` — awalan `Sukses`
     *                adalah penanda seragam antar provider, dipakai pemanggil
     *                untuk memilih level log.
     *
     * @throws ApiException
     */
    public function sendMessage(array|string $message): string
    {
        $items = $this->parse($message);

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        $payload = $this->compose(fn (): string => (new FonnteBulkMessage($items))->toJson());

        $response = $this->http->post(
            $this->urlApi,
            ['data' => $payload],
            ['Authorization' => $this->getToken()]
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        $body = $this->requireJson($body, $response->status);

        if (empty($body['status'])) {
            $reason = $this->detail($body);

            throw new ApiException(
                $reason !== '' ? $reason : "Fonnte menolak pesan (HTTP {$response->status})",
                $response->status,
                $body,
                $reason !== '' ? $reason : null
            );
        }

        $detail = $body['detail'] ?? '';
        $detail = \is_array($detail) ? implode('; ', array_map('strval', $detail)) : (string) $detail;

        return $detail !== '' ? "Sukses: {$detail}" : 'Sukses';
    }
}
