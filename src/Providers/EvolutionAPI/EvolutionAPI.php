<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Providers\EvolutionAPI;

use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ApiException;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Providers\AbstractProvider;
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\File;
use Sikuwa\Whatsapp\Support\PhoneNumber;

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

    protected function authHeaders(): array
    {
        return ['apikey' => $this->getToken()];
    }

    /**
     * Buat instance baru: `POST /instance/create`.
     *
     * Dengan `qrcode => true` (bawaan), balasannya sudah membawa QR di
     * `qrcode.base64` sehingga sesi bisa langsung dipindai. Nama instance hanya
     * boleh huruf kecil dan angka.
     *
     * @param array<string,mixed> $options Kunci yang dikenali: `instanceName`
     *                                     (alias `name`), `token`, `integration`,
     *                                     `webhook`, `events`, `qrcode`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function createSession(array $options = []): Session
    {
        $payload = EvolutionAPISession::payload($options, $this->instanceName);
        $body = $this->postJson("{$this->baseUrl}/instance/create", $payload);

        return EvolutionAPISession::fromResponse($body, (string) ($payload['instanceName'] ?? ''));
    }

    /**
     * Baca keadaan instance: `GET /instance/connectionState/{instance}`.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function checkSession(?string $id = null): Session
    {
        $instanceName = $id ?? $this->instanceName;

        if ($instanceName === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        return EvolutionAPISession::fromResponse(
            $this->getJson("{$this->baseUrl}/instance/connectionState/" . rawurlencode($instanceName)),
            $instanceName
        );
    }

    /**
     * Ambil QR instance: `GET /instance/connect/{instance}`.
     *
     * Balasannya berubah mengikuti keadaan instance. Selama masih `close`,
     * QR-nya ada di `base64`; begitu instance `open`, Evolution membalas
     * keadaan instancenya alih-alih QR. Kedua bentuk itu sudah dikenali
     * {@see EvolutionAPISession::fromResponse()}, jadi di sini tidak perlu
     * penormal tersendiri — hasilnya sesi `connected` tanpa QR.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    public function showQr(?string $id = null): Session
    {
        $instanceName = $id ?? $this->instanceName;

        if ($instanceName === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        return EvolutionAPISession::fromResponse(
            $this->getJson("{$this->baseUrl}/instance/connect/" . rawurlencode($instanceName)),
            $instanceName
        );
    }

    /**
     * Kirim pesan lewat Evolution API.
     *
     * Evolution tidak punya endpoint batch, jadi beberapa pesan dikirim satu
     * per satu. Jeda antar pesan diserahkan ke server lewat kolom `delay`
     * (Evolution menunggu sebelum mengirim), sehingga pemanggil tetap
     * menunggu selama jeda itu. Bila `delay` tidak diisi, nilainya diambil
     * dari pacing (`WHATSAPP_PACING_*`). Halaman scan hanya mengirim satu
     * pesan.
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

        if ($this->instanceName === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        $prepared = $this->compose(fn (): array => $this->build($items));

        // Jeda sengaja tidak diteruskan ke sendSequentially(): nilainya sudah
        // dititipkan ke server, jadi menunggu di klien juga berarti dua kali.
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
     * @return array<int,array{message:EvolutionAPIMessage,delay:?int}>
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

    /**
     * Kirim satu berkas lewat Evolution API.
     *
     * Satu endpoint untuk semua jenis (`sendMedia`); yang membedakan hanya
     * kolom `mediatype`. Evolution menerima `media` berupa URL publik atau
     * base64, tapi **bukan** data URI: pemeriksaannya memakai `isBase64()`
     * milik class-validator, yang tidak mengenali awalan `data:…;base64,`.
     * Jadi isinya harus base64 telanjang. Untuk dokumen berbasis base64,
     * `fileName` wajib diisi — tanpa itu Evolution menolak dengan HTTP 400.
     *
     * @throws ApiException
     * @throws ConfigurationException
     */
    protected function sendMedia(string $destination, File $file, string $caption): string
    {
        if ($this->instanceName === '') {
            throw new ConfigurationException('WHATSAPP_INSTANCE belum diisi di .env');
        }

        $number = str_contains($destination, '@')
            ? $destination
            : PhoneNumber::normalize($destination);

        if ($number === '') {
            throw new ConfigurationException("Nomor tujuan '{$destination}' tidak valid");
        }

        $payload = [
            'number' => $number,
            'mediatype' => $file->isImage() ? 'image' : 'document',
            'mimetype' => $file->mime,
        ];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($file->isUrl()) {
            $payload['media'] = $file->payload;
        } else {
            $payload['media'] = $file->base64();
        }

        // Nama berkas hanya bermakna untuk dokumen; bagi gambar Evolution
        // memakai `mimetype` saja.
        if (! $file->isImage()) {
            $payload['fileName'] = $file->filename;
        }

        $body = $this->postJson(
            "{$this->baseUrl}/message/sendMedia/" . rawurlencode($this->instanceName),
            $payload
        );

        // Sukses mengembalikan objek pesan Baileys; id-nya ada di key.id.
        $key = \is_array($body['key'] ?? null) ? $body['key'] : [];

        return 'Sukses, messageId: ' . ($key['id'] ?? '-');
    }

    /** POST /message/sendText/{instanceName} */
    private function sendText(EvolutionAPIMessage $message): string
    {
        $body = $this->postJson(
            "{$this->baseUrl}/message/sendText/" . rawurlencode($this->instanceName),
            $message->toArray()
        );

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
