# WhatsApp Unofficial SDK (SIKUWA)

SDK PHP untuk beberapa gateway WhatsApp unofficial. Tujuannya satu antarmuka yang sama untuk banyak gateway,
sehingga penggantian provider atau otomatis pilih provider tidak mengubah kode pemanggil.

Namespace `Sikuwa\Whatsapp\`, PSR-4, dibangun di atas [Guzzle](https://docs.guzzlephp.org/) 7,
membutuhkan PHP 8.1+.

## Gateway yang didukung

| Gateway | Jenis | Basis | Tautan |
| --- | --- | --- | --- |
| Fonnte | Berbayar (cloud) | — | <https://fonnte.com/> |
| OpenWA | Self-hosted | Node.js | <https://github.com/rmyndharis/OpenWA> |
| ApiMe | Self-hosted | Go / WhatsMeow | <https://github.com/open-apime/apime> |
| Evolution API | Self-hosted | Node.js / Baileys | <https://github.com/evolution-foundation/evolution-api> |
| Wuzapi | Self-hosted | Go / WhatsMeow | <https://github.com/asternic/wuzapi> |

## Instalasi

Belum dipublikasikan ke Packagist, jadi daftarkan repositori GitHub-nya lebih dulu:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mdestafadilah/sikuwa" }
    ],
    "require": {
        "mdestafadilah/sikuwa": "dev-main"
    }
}
```

## Pemakaian

```php
<?php

require 'vendor/autoload.php';

use Sikuwa\Whatsapp\Client;

$client = new Client([
    'provider' => 'OpenWA',
    'token'    => 'owa_k1_…',
    'url'      => 'http://localhost:2785',
    'session'  => 'my-session',
]);

echo $client->send([
    'destination' => '081234567890',
    'message'     => 'Halo dari SIKUWA',
]);
// Sukses, messageId: 3EB0…
```

Opsi yang dikosongkan akan dicari di environment (`WHATSAPP_*`), jadi di aplikasi
CodeIgniter cukup `new Client()` tanpa argumen apa pun:

```php
$provider = $client->provider();      // instance gateway yang terpilih
$result   = $provider->sendMessage($message);

log_message(
    str_starts_with($result, 'Sukses') ? 'info' : 'error',
    "Notifikasi {$provider->getProvider()}: {$result}"
);
```

### Bentuk pesan

Satu bentuk yang sama untuk semua gateway:

```php
['destination' => '081234567890', 'message' => 'Halo', 'delay' => 2]
```

Untuk pengiriman massal, kirim list dari array seperti itu:

```php
$client->send([
    ['destination' => '0811111111', 'message' => 'Pesan pertama'],
    ['destination' => '0822222222', 'message' => 'Pesan kedua', 'delay' => 5],
]);
```

Nomor boleh ditulis dalam format apa pun yang lazim di Indonesia
(`0812…`, `+62 812…`, `62812…`) — SDK menormalkannya sendiri. JID grup
(`…@g.us`) dan WID (`…@c.us`) diteruskan apa adanya.

`delay` dihitung dalam **detik** dan opsional: jeda sebelum pesan dikirim
(Fonnte, Evolution API) atau jeda antar pesan pada pengiriman berurutan. Bila
tidak diisi, tiap gateway memakai bawaannya sendiri — Fonnte 2 detik, OpenWA 3
detik, sisanya tanpa jeda.

### Dua gaya pemanggilan

`send()` melempar exception kalau gagal; `notify()` mengembalikan string dan
tidak pernah melempar.

```php
// Notifikasi yang tidak boleh menggagalkan request pemanggil:
$result = $client->notify($message);   // selalu string

// Pengiriman yang kegagalannya harus ditangani:
try {
    $client->send($message);
} catch (\Sikuwa\Whatsapp\Exceptions\WhatsappException $e) {
    // ...
}
```

### Memilih gateway

`WHATSAPP_PROVIDER` menerima nama gateway (tidak peka huruf besar/kecil) atau
`Auto`. Mode `Auto` mengundi **hanya di antara gateway yang
`WHATSAPP_TOKEN_<Provider>`-nya terisi**, sehingga undian tidak pernah jatuh ke
gateway yang belum dikonfigurasi.

```php
Client::configured();   // mis. ['OpenWA', 'Wuzapi']
$client->provider();    // instance gateway terpilih
```

`provider()` mengembalikan instance baru setiap dipanggil. Untuk `Auto` itu
berarti undiannya diulang — panggil sekali lalu simpan hasilnya kalau beberapa
pesan harus lewat gateway yang sama.

## Konfigurasi

Lihat [`.env.example`](.env.example). Ringkasnya:

| Kunci | Keterangan |
| --- | --- |
| `WA_NOTIFICATION` | Penanda notifikasi aktif. SDK **tidak** menegakkannya; tersedia lewat `$client->enabled()` |
| `WHATSAPP_PROVIDER` | `Auto` atau nama gateway |
| `WHATSAPP_TOKEN_<Provider>` | Token per gateway. Inilah yang dihitung mode `Auto` |
| `WHATSAPP_TOKEN` | Token umum, dipakai bila token khusus gateway tidak ada |
| `WHATSAPP_URL` | Base URL gateway self-hosted. **Diabaikan Fonnte** |
| `WHATSAPP_SESSION` | Khusus OpenWA |
| `WHATSAPP_INSTANCE` | Khusus ApiMe dan Evolution API |
| `WHATSAPP_TIMEOUT` | Batas waktu request, detik (1–60, default 10) |

Nilai default URL bila `WHATSAPP_URL` dikosongkan: OpenWA
`https://openwa.whatsapp.com`, ApiMe `https://api-me.whatsapp.com`,
Evolution API `https://evolution-api.whatsapp.com`, Wuzapi
`https://wuzapi.whatsapp.com`. Fonnte tidak memakainya sama sekali.

`WHATSAPP_URL` berlaku untuk provider **apa pun** yang sedang aktif, jadi jangan
mengisinya dengan URL satu gateway lalu berpindah ke gateway lain — nilai itu
akan ikut terbawa.

### Catatan per gateway

- **Fonnte** — selalu membalas HTTP 200; keberhasilan sebenarnya ada di field
  `status`. Endpoint-nya tetap, jadi `WHATSAPP_URL` sengaja **tidak** dibaca:
  kalau dibaca, satu nilai yang ditujukan untuk gateway self-hosted akan
  mengalihkan pengiriman Fonnte ke host yang salah.
- **OpenWA** — butuh `WHATSAPP_SESSION`. Satu pesan dikirim ke `send-text`
  (sinkron, balasannya `messageId`); lebih dari satu dikirim ke `send-bulk`
  (asinkron, balasannya `batchId`, maksimum 100 pesan per batch).
- **ApiMe** — butuh `WHATSAPP_INSTANCE` dan token **ber-scope instance**; JWT
  user maupun API token global ditolak dengan HTTP 403. Pengiriman memakai
  `Idempotency-Key` deterministik, sehingga kartu yang ter-scan dua kali
  beruntun tidak menghasilkan dua pesan.
- **Evolution API** — butuh `WHATSAPP_INSTANCE`. `delay` dititipkan ke server
  lewat payload (dalam milidetik), jadi klien tidak ikut menunggu.
- **Wuzapi** — tidak butuh instance: tokennya sendiri yang menentukan sesi.
  Auth memakai header `Token`, bukan `Authorization` seperti yang tertulis di
  README wuzapi.

## Error

Semua error melempar subclass dari `Sikuwa\Whatsapp\Exceptions\WhatsappException`:

| Kelas | Kapan |
| --- | --- |
| `ConfigurationException` | Token/URL/session/instance belum diisi, atau bentuk pesan salah. Tidak akan sembuh kalau diulang |
| `UnknownProviderException` | Nama gateway tidak dikenali |
| `ApiException` | Gateway menjawab tapi menolak. Membawa `getStatus()`, `getBody()`, `getErrorKind()` |
| `AuthException` | 401 — token ditolak |
| `ForbiddenException` | 403 — token kurang hak (mis. bukan instance token di ApiMe) |
| `NotFoundException` | 404 — instance/session tidak ditemukan |
| `ConflictException` | 409 — masih ada pengiriman dengan `Idempotency-Key` yang sama |
| `RateLimitException` | 429 — satu-satunya status yang aman diretry |
| `ServiceUnavailableException` | 503 — sesi WhatsApp belum siap, tidak ada pesan terkirim |
| `TimeoutException` | Request melewati `WHATSAPP_TIMEOUT` |

Pengiriman massal ke gateway tanpa endpoint batch (ApiMe, Evolution API,
wuzapi) mengirim satu per satu. Kegagalan satu nomor tidak menghentikan
sisanya; semuanya dikumpulkan lalu dilempar sebagai satu `ApiException`, jadi
pemanggil melihat gambaran lengkapnya:

```
2/3 pesan terkirim. Gagal: 62822: ApiMe menolak pesan (HTTP 400): nomor tidak terdaftar
```

## Pengujian

```bash
composer install
composer test
```

Tidak ada jaringan yang tersentuh: test menyuntikkan klien Guzzle ber-handler
`MockHandler` lewat opsi `httpClient`, sehingga tidak perlu monkey-patching global.

```php
$client = new Client(['provider' => 'Fonnte'], $mockGuzzleClient);
```

## Butuh satu gateway saja?

Kalau hanya memakai satu gateway, SDK ini berlebihan. Gunakan langsung
[SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php)
yang sudah teruji.

## Rencana pengembangan

- [ ] Create sessions
- [x] Send messages
- [ ] Send Media Image
- [ ] Send Media File

## Kredit

Terinspirasi dari [SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php).
