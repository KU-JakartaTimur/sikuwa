<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp;

use Sikuwa\Whatsapp\Support\PhoneNumber;

/**
 * Hasil pemeriksaan "nomor mana yang benar-benar terdaftar di WhatsApp",
 * dengan bentuk yang sama untuk semua gateway.
 *
 * WhatsApp tidak memberi tahu apakah sebuah nomor bisa dihubungi sebelum
 * pesannya benar-benar dikirim, dan menembak nomor yang tidak pernah ada
 * adalah salah satu pemicu pemblokiran yang paling sering. Objek inilah yang
 * menjawab pertanyaan itu, apa pun gateway yang dipakai:
 *
 * ```php
 * $hasil = $client->checkNumbers(['081234567890', '089876543210']);
 *
 * foreach ($hasil->registered() as $nomor) {
 *     $client->send(['destination' => $nomor, 'message' => 'Halo']);
 * }
 * ```
 *
 * **Tanyakan selalu lewat {@see self::isRegistered()}, bukan dengan membaca
 * {@see self::$results} langsung.** Nomor di dalamnya sudah dinormalkan ke
 * bentuk internasional tanpa tanda plus (`62812…`), jadi kunci yang ditulis
 * pemanggil (`0812…`) tidak akan cocok apa adanya — method itulah yang
 * menormalkannya untuk Anda.
 *
 * Nomor yang tidak disebut gateway tidak muncul di sini: itu berarti **belum
 * terperiksa**, bukan "tidak terdaftar". {@see self::isRegistered()} menjawab
 * `false` untuk keduanya, karena arah yang aman untuk pemakaiannya adalah
 * tidak mengirim.
 */
final class NumberCheck
{
    /**
     * @param string $provider Nama gateway yang memeriksa, mis. `OpenWA`.
     * @param array<int|string,bool> $results Nomor (sudah dinormalkan) =>
     *        terdaftar. PHP menyimpan kunci yang berbentuk angka sebagai
     *        integer, jadi bandingkan selalu lewat {@see self::isRegistered()}.
     * @param array<int|string,string> $ids Nomor => id WhatsApp kanonik (JID),
     *        untuk gateway yang menyebutkannya. Kosong bila tidak ada.
     * @param array<array-key,mixed> $raw Amplop asli gateway, untuk field khas
     *        gateway yang sengaja tidak ikut dinormalkan. Gateway yang memeriksa
     *        satu nomor per panggilan mengisinya dengan daftar balasan, bukan
     *        satu amplop.
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $results = [],
        public readonly array $ids = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * Apakah nomor ini terdaftar di WhatsApp.
     *
     * Nomornya boleh ditulis dalam bentuk apa pun yang lazim — `0812…`,
     * `+62 812…`, atau `62812…`. Nomor yang tidak ikut diperiksa, atau yang
     * tidak disebut gateway, dijawab `false`.
     */
    public function isRegistered(string $number): bool
    {
        return $this->results[PhoneNumber::normalize($number)] ?? false;
    }

    /**
     * Id WhatsApp kanonik sebuah nomor (`62812…@s.whatsapp.net`), bila gateway
     * menyebutkannya. String kosong berarti gateway tidak menyediakannya —
     * bukan bahwa nomornya tidak ada.
     */
    public function whatsappId(string $number): string
    {
        $id = $this->ids[PhoneNumber::normalize($number)] ?? null;

        return \is_string($id) ? $id : '';
    }

    /**
     * Nomor yang terdaftar, siap dipakai sebagai daftar tujuan.
     *
     * @return array<int,string>
     */
    public function registered(): array
    {
        return self::keysWhere($this->results, true);
    }

    /**
     * Nomor yang dipastikan **tidak** terdaftar. Nomor yang belum terperiksa
     * tidak muncul di sini, supaya daftar ini tidak pernah melebih-lebihkan
     * apa yang sebenarnya diketahui gateway.
     *
     * @return array<int,string>
     */
    public function unregistered(): array
    {
        return self::keysWhere($this->results, false);
    }

    /** Apakah gateway tidak memberi jawaban apa pun. */
    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * Bentuk ringkas `nomor => terdaftar`, aman dicatat ke log.
     *
     * Kuncinya berupa nomor, jadi PHP menyimpannya sebagai integer selama
     * bentuknya angka. Pakai {@see self::registered()} kalau yang dibutuhkan
     * adalah daftar nomor dalam bentuk string.
     *
     * @return array<int|string,bool>
     */
    public function toArray(): array
    {
        return $this->results;
    }

    /**
     * Hasilnya sebagai JSON.
     *
     * Dilewatkan `(object)` supaya bentuknya selalu objek JSON: tanpa itu hasil
     * yang kosong akan tercetak sebagai `[]`, padahal yang dikembalikan di sini
     * adalah peta `nomor => terdaftar`, bukan daftar.
     */
    public function toJson(): string
    {
        return (string) json_encode((object) $this->toArray());
    }

    /** Ringkasan siap catat ke log, mis. `"OpenWA: 2 terdaftar, 1 tidak terdaftar"`. */
    public function __toString(): string
    {
        return sprintf(
            '%s: %d terdaftar, %d tidak terdaftar',
            $this->provider,
            \count($this->registered()),
            \count($this->unregistered())
        );
    }

    /**
     * Kunci yang nilainya sama dengan `$expected`, dikembalikan sebagai string.
     *
     * `strval()` di sini bukan hiasan: PHP mengubah kunci array yang berbentuk
     * angka menjadi integer, sehingga tanpa itu `registered()` mengembalikan
     * `[6281234567890]` dan bukan `['6281234567890']`. Perbandingan ketat
     * dengan nomor yang datang dari pemanggil lalu gagal tanpa sebab yang
     * terlihat di mana pun.
     *
     * @param array<int|string,bool> $results
     * @return array<int,string>
     */
    private static function keysWhere(array $results, bool $expected): array
    {
        $keys = [];

        foreach ($results as $number => $value) {
            if ($value === $expected) {
                $keys[] = (string) $number;
            }
        }

        return $keys;
    }
}
