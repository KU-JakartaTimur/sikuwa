# WhatsApp Unofficial SDK (SIKUWA)

SDK PHP untuk beberapa gateway WhatsApp unofficial, dipakai pada aplikasi
**SIKU Khoiru Ummah**. Tujuannya satu antarmuka yang sama untuk banyak gateway,
sehingga penggantian provider atau otomatis pilih provider tidak mengubah kode pemanggil.

> Status: **work in progress** — baru tahap perancangan, belum ada kode yang dirilis.

## Gateway yang didukung

| Gateway | Jenis | Basis | Tautan |
| --- | --- | --- | --- |
| Fonnte | Berbayar (cloud) | — | <https://fonnte.com/> |
| OpenWA | Self-hosted | Node.js | <https://github.com/rmyndharis/OpenWA> |
| ApiMe | Self-hosted | Go / WhatsMeow | <https://github.com/open-apime/apime> |
| Evolution API | Self-hosted | Node.js / Baileys | <https://github.com/evolution-foundation/evolution-api> |
| Wuzapi | Self-hosted | Go / WhatsMeow | <https://github.com/asternic/wuzapi> |

## Butuh satu gateway saja?

Kalau hanya memakai satu gateway, SDK ini berlebihan. Gunakan langsung
[SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php)
yang sudah teruji.

## Rencana pengembangan

- [ ] Create sessions
- [ ] Send messages
- [ ] Send Media Image
- [ ] Send Media File

## Kredit

Terinspirasi dari [SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php).
