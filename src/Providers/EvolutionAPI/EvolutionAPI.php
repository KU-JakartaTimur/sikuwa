<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\EvolutionAPI;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;

/**
 * Gateway Evolution API (https://github.com/evolution-foundation/evolution-api),
 * WhatsApp self-hosted berbasis Node.js + Baileys.
 *
 * Konfigurasi:
 *   WHATSAPP_PROVIDER = EvolutionAPI
 *   WHATSAPP_TOKEN    = API key global (AUTHENTICATION_API_KEY) atau token
 *                       instance; keduanya diterima lewat header `apikey`
 *   WHATSAPP_URL_EvolutionAPI = base URL instance, mis. https://v7.rspwa.example.com
 *                               (`WHATSAPP_URL` juga dibaca sebagai fallback umum)
 *   WHATSAPP_INSTANCE = nama instance yang sudah tersambung
 */
final class EvolutionAPI extends AbstractProvider
{
    public const NAME = 'EvolutionAPI';

    public const DEFAULT_URL = 'https://evolution-api.whatsapp.com';

    private string $baseUrl;
    private string $instanceName;

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

        $this->baseUrl = $this->config->url(self::DEFAULT_URL, self::NAME);
        $this->instanceName = $this->config->instance();
    }

    public function getProvider(): string
    {
        return self::NAME;
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    /**
     * Kirim pesan lewat Evolution API.
     *
     * Evolution tidak punya endpoint batch, jadi beberapa pesan dikirim satu
     * per satu. Jeda antar pesan diserahkan ke server lewat kolom `delay`
     * (Evolution menunggu sebelum mengirim), sehingga pemanggil tetap
     * menunggu selama jeda itu. Halaman scan hanya mengirim satu pesan.
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

        if ($this->instanceName === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        $prepared = $this->compose(fn (): array => $this->build($items));

        return \count($prepared) === 1
            ? $this->sendText($prepared[0]['message'])
            : $this->sendSequentially(
                $prepared,
                fn (EvolutionAPIMessage $m): string => $this->sendText($m),
                static fn (EvolutionAPIMessage $m): string => $m->number
            );
    }

    /**
     * @param array<int,array{destination:string,message:string,delay:?int}> $items
     * @return array<int,array{message:EvolutionAPIMessage,delay:int}>
     *
     * @throws ConfigurationException
     */
    private function build(array $items): array
    {
        $prepared = [];

        foreach ($items as $i => $item) {
            $message = new EvolutionAPIMessage(
                $item['destination'],
                $item['message'],
                $item['delay'] ?? 0
            );

            if ($message->number === '') {
                throw new ConfigurationException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            // Jeda dititipkan ke server lewat payload, jadi klien tidak perlu
            // ikut menunggu di antara request.
            $prepared[] = ['message' => $message, 'delay' => 0];
        }

        return $prepared;
    }

    /** POST /message/sendText/{instanceName} */
    private function sendText(EvolutionAPIMessage $message): string
    {
        $url = "{$this->baseUrl}/message/sendText/" . rawurlencode($this->instanceName);

        $response = $this->http->post(
            $url,
            (string) json_encode($message->toArray()),
            [
                'Content-Type' => 'application/json',
                'apikey' => $this->getToken(),
            ]
        );

        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        $body = $this->requireJson($body, $response->status);

        // Sukses mengembalikan objek pesan Baileys; id-nya ada di key.id.
        $key = \is_array($body['key'] ?? null) ? $body['key'] : [];

        return 'Sukses, messageId: ' . ($key['id'] ?? '-');
    }

    /**
     * Evolution memakai amplop error
     * `{"status":N,"error":"...","response":{"message": ... }}`,
     * dengan `message` bisa berupa string maupun array pesan validasi.
     * Pengambilan detailnya ditangani {@see AbstractProvider::detail()}.
     */
    protected function describe(int $status, ?array $body): string
    {
        $konteks = match ($status) {
            401 => 'apikey salah, cek WHATSAPP_TOKEN',
            403 => 'instance belum tersambung ke WhatsApp',
            404 => 'instance tidak ditemukan, cek WHATSAPP_INSTANCE',
            default => '',
        };

        $pesan = parent::describe($status, $body);

        return $konteks === '' ? $pesan : "{$pesan} [{$konteks}]";
    }
}
