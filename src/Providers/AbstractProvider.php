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
use Sikuwa\Whatsapp\Session;
use Sikuwa\Whatsapp\Support\Pacing;

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
     * Penidur pengganti, dipakai test supaya jeda tidak benar-benar ditunggu.
     *
     * @var (callable(int):void)|null
     */
    private static $sleeper = null;

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

    /**
     * Header autentikasi gateway ini.
     *
     * Satu-satunya tempat token dipasang, supaya tiap provider cukup
     * menyebutkan *bagaimana* ia mengautentikasi — `Authorization: Bearer`,
     * `X-API-Key`, `apikey`, atau `Token` — tanpa mengulang cara request
     * dikirim dan dibaca.
     *
     * @return array<string,string>
     */
    abstract protected function authHeaders(): array;

    /**
     * Buat sesi/instance baru di gateway.
     *
     * Sengaja abstrak: setiap gateway punya istilah, endpoint, dan kredensial
     * sendiri untuk ini, jadi tidak ada perilaku bawaan yang masuk akal.
     *
     * @param array<string,mixed> $options
     */
    abstract public function createSession(array $options = []): Session;

    /**
     * Baca keadaan sesi yang sudah ada.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     */
    abstract public function checkSession(?string $id = null): Session;

    /**
     * Ambil QR sesi yang sudah ada, untuk dipindai.
     *
     * Sama seperti {@see self::createSession()}: abstrak, karena endpoint dan
     * bentuk balasannya khas tiap gateway — Fonnte mengirim base64 telanjang
     * di `url`, OpenWA dan wuzapi mengirim data URI yang sudah lengkap.
     * Penyeragamannya ada di {@see Support\Qr}.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     */
    abstract public function showQr(?string $id = null): Session;

    public function config(): Config
    {
        return $this->config;
    }

    public function executor(): HttpExecutor
    {
        return $this->http;
    }

    /**
     * Pasang penidur sendiri. Kirim null untuk kembali ke `sleep()` biasa.
     *
     * Ada supaya jeda antar pesan bisa diuji tanpa benar-benar menunggu —
     * sama seperti {@see Config::useResolver()} yang jadi jalur test untuk
     * environment. Hanya dipakai test.
     *
     * @param (callable(int):void)|null $sleeper
     */
    public static function useSleeper(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    /** Tunggu `$seconds` detik, lewat penidur yang sedang terpasang. */
    protected function pause(int $seconds): void
    {
        if (self::$sleeper !== null) {
            (self::$sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }

    /**
     * Pacing yang berlaku untuk satu panggilan pengiriman.
     *
     * @param array<string,mixed>|null $override Opsi `pacing` dari pemanggil;
     *                                           digabung sebagian atas nilai
     *                                           dari konfigurasi.
     */
    protected function pacing(?array $override = null): Pacing
    {
        return $this->config->pacing()->merge($override);
    }

    /**
     * Normalisasi bentuk pesan menjadi list yang seragam, sekaligus memisahkan
     * pengaturan pacing dari isi pesan.
     *
     * `delay` dibiarkan null bila pemanggil tidak mengisinya, supaya tiap
     * gateway bisa memakai nilai bawaannya sendiri (Fonnte 2 detik, OpenWA 3
     * detik, sisanya 0) atau nilai dari pacing. Mengisinya dengan 0 berarti
     * "tanpa jeda" — dan itu tetap menang atas pacing, karena pemanggil yang
     * menyebut angka pasti lebih tahu daripada nilai bawaan.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     * @return array{
     *     items:array<int,array{destination:string,message:string,delay:?int}>,
     *     pacing:array<string,mixed>|null
     * }
     *
     * @throws ConfigurationException
     */
    protected function plan(array|string $message): array
    {
        if (\is_string($message)) {
            throw new ConfigurationException(
                'Format pesan tidak valid: ' . $this->getProvider() . ' membutuhkan array pesan'
            );
        }

        // Pacing per panggilan. Dua bentuk diterima, karena daftar pesan polos
        // tidak punya tempat untuk menaruh kunci pengaturan:
        //   ['messages' => [...], 'pacing' => [...]]   (amplop)
        //   ['pacing' => [...], [...], [...]]          (kunci di samping daftar)
        $override = null;

        if (array_key_exists('pacing', $message) && $message['pacing'] !== null) {
            if (! \is_array($message['pacing'])) {
                throw new ConfigurationException(
                    "Gagal menyusun pesan: kunci 'pacing' harus berupa array, "
                    . "mis. ['cycle' => '0,30', 'interval' => '20-30']"
                );
            }

            $override = $message['pacing'];
        }

        if (isset($message['messages']) && \is_array($message['messages'])) {
            $message = $message['messages'];
        }

        // Dibuang supaya tidak ikut terbaca sebagai pesan pada bentuk daftar.
        unset($message['pacing']);

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

        return ['items' => $items, 'pacing' => $override];
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
     * Susun header untuk request berbadan JSON.
     *
     * @param array<string,string> $extra Header khusus request ini, mis.
     *                                     `Idempotency-Key` milik ApiMe.
     * @return array<string,string>
     */
    protected function jsonHeaders(array $extra = []): array
    {
        return array_merge(['Content-Type' => 'application/json'], $this->authHeaders(), $extra);
    }

    /**
     * POST JSON, lalu baca + tolak + wajib-JSON dalam satu langkah.
     *
     * Keempat gateway self-hosted melakukan urutan yang persis sama; hanya URL,
     * payload, dan header tambahannya yang berbeda.
     *
     * @param array<string,mixed>|null $payload Body JSON, atau null untuk
     *                                          endpoint yang hanya butuh header
     *                                          autentikasi (mis. Fonnte
     *                                          `get-devices`).
     * @param array<string,string>     $extraHeaders
     * @return array<string,mixed> Body terdecode; tidak pernah null.
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function postJson(string $url, ?array $payload = null, array $extraHeaders = []): array
    {
        $body = $payload === null ? '' : (string) json_encode($payload);

        return $this->decode($this->http->post($url, $body, $this->jsonHeaders($extraHeaders)));
    }

    /**
     * GET, lalu baca + tolak + wajib-JSON. Dipakai endpoint status sesi.
     *
     * @return array<string,mixed> Body terdecode; tidak pernah null.
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    protected function getJson(string $url): array
    {
        return $this->decode($this->http->get($url, $this->authHeaders()));
    }

    /**
     * Terjemahkan satu respons menjadi body JSON, atau lempar exception yang
     * sesuai.
     *
     * @return array<string,mixed>
     *
     * @throws TimeoutException
     * @throws ApiException
     */
    private function decode(HttpResponse $response): array
    {
        $body = $this->read($response);

        if (! $response->isSuccess()) {
            $this->reject($response, $body);
        }

        return $this->requireJson($body, $response->status);
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
     * Urutan penentuan jeda: `delay` pada pesan itu sendiri, lalu pacing, lalu
     * nol. Pesan pertama tidak pernah ditunggu — jeda sebelum pengiriman
     * pertama adalah urusan pemanggil, bukan urusan SDK.
     *
     * @param array<int,array{message:mixed,delay:?int}> $items
     * @param callable(mixed):void                      $send
     * @param callable(mixed):string                    $label Penanda pesan untuk pesan error.
     * @param Pacing|null $pacing Pengatur jeda antar pesan; null berarti tidak ada.
     *
     * @throws ApiException
     */
    protected function sendSequentially(
        array $items,
        callable $send,
        callable $label,
        ?Pacing $pacing = null
    ): string {
        $sukses = 0;
        $gagal = [];
        $terakhir = null;

        foreach ($items as $i => $item) {
            $jeda = $item['delay'] ?? $pacing?->delayFor($i) ?? 0;

            if ($i > 0 && $jeda > 0) {
                $this->pause($jeda);
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
