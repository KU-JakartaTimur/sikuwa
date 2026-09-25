<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

use Sikuwa\Whatsapp\Exceptions\ConfigurationException;

/**
 * Satu berkas media yang siap dikirim, apa pun gateway-nya.
 *
 * Gateway tidak sepakat soal bentuk kiriman berkas — dan tidak ada satu bentuk
 * yang diterima semua. Fonnte menuntut berkasnya diunggah sebagai multipart;
 * OpenWA menerima base64 telanjang dengan `mimetype` terpisah; wuzapi hanya
 * mau data URI; Evolution API justru menolak data URI dan baru menerima base64
 * telanjang. Pemanggil tidak perlu tahu semua itu: ia cukup menyerahkan isi
 * berkas dalam bentuk apa pun yang sudah ia punya — data URI, base64
 * telanjang, atau URL publik — dan kelas ini yang menerjemahkannya ke bentuk
 * yang diminta masing-masing gateway.
 *
 * Jenis berkas ditentukan dari data URI bila ada, sebab data URI membawa
 * jenisnya sendiri dan itu lebih dipercaya daripada nama berkas yang bisa
 * salah tulis; kalau tidak ada, baru dari ekstensi nama berkas. Jenis inilah
 * yang menentukan sebuah berkas dikirim sebagai gambar (muncul dengan
 * pratinjau di WhatsApp) atau sebagai dokumen.
 */
final class File
{
    /** Dipakai kalau jenis berkas sama sekali tidak bisa dikenali. */
    public const DEFAULT_MIME = 'application/octet-stream';

    /** Penanda awal bagian base64 pada sebuah data URI. */
    private const MARKER = 'base64,';

    /**
     * Ekstensi yang dikenali, sengaja terbatas pada bentuk yang benar-benar
     * dipakai untuk notifikasi. Yang tidak ada di sini tetap bisa dikirim —
     * jenisnya jadi `application/octet-stream`, dan WhatsApp memperlakukannya
     * sebagai dokumen biasa.
     *
     * @var array<string,string>
     */
    private const MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];

    /**
     * Kebalikan dari peta di atas, dipakai menyusun nama berkas bawaan saat
     * pemanggil tidak menyebutkan namanya. Satu jenis hanya diwakili satu
     * ekstensi — `jpeg` dan `jpg` sama-sama menjadi `jpg`.
     *
     * @var array<string,string>
     */
    private const EXTENSION_BY_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    private function __construct(
        /** Isi berkas apa adanya: data URI, base64 telanjang, atau URL publik. */
        public readonly string $payload,
        /** Nama yang akan dilihat penerima; tidak pernah kosong. */
        public readonly string $filename,
        /** Jenis berkas yang sudah disimpulkan dari data URI atau ekstensi. */
        public readonly string $mime
    ) {
    }

    /**
     * Susun berkas dari isi yang diberikan pemanggil.
     *
     * @param string $payload  data URI, base64 telanjang, atau URL publik
     * @param string $filename Opsional; kalau kosong disusun dari jenis berkas
     *
     * @throws ConfigurationException Bila isinya kosong
     */
    public static function from(string $payload, string $filename = ''): self
    {
        $payload = trim($payload);
        $filename = trim($filename);

        if ($payload === '') {
            throw new ConfigurationException(
                'Isi berkas kosong: kirim data URI, base64 telanjang, atau URL publik '
                . 'lewat kunci media pada pesan'
            );
        }

        // Data URI menang atas nama berkas: isinya sudah menyebut jenisnya
        // sendiri, sedangkan nama berkas bisa saja kosong atau salah ekstensi.
        $mime = self::mimeFromDataUri($payload) ?? self::mimeFromExtension($filename);

        if ($filename === '') {
            $filename = self::defaultFilename($mime);
        }

        return new self($payload, $filename, $mime);
    }

    /**
     * Apakah isinya URL publik, bukan data yang ikut dikirim.
     *
     * Hanya sebagian gateway yang mau mengunduh sendiri berkas dari URL — yang
     * lain menuntut isinya diunggah — jadi tiap provider perlu bisa
     * membedakannya.
     */
    public function isUrl(): bool
    {
        // Data URI diperiksa lebih dulu: "data:" tidak pernah berupa URL, dan
        // memeriksanya duluan menjaga niat kode ini tetap terbaca.
        return ! str_starts_with($this->payload, 'data:')
            && preg_match('#^https?://#i', $this->payload) === 1;
    }

    /** Gambar dikirim dengan pratinjau; selainnya menjadi dokumen. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Isi berkas sebagai data URI, siap dipakai gateway yang menuntutnya.
     *
     * Idempoten seperti {@see Qr::dataUri()}: yang sudah berupa data URI
     * dikembalikan apa adanya, jadi aman dipanggil berkali-kali.
     */
    public function dataUri(): string
    {
        if (str_starts_with($this->payload, 'data:')) {
            return $this->payload;
        }

        return "data:{$this->mime};" . self::MARKER . $this->payload;
    }

    /**
     * Isi berkas sebagai base64 telanjang, tanpa awalan data URI.
     *
     * Dibutuhkan gateway yang memisahkan isi dari jenisnya — OpenWA mengirim
     * `mimetype` di kolom sendiri, dan Evolution API tidak mengenali awalan
     * `data:` sama sekali.
     */
    public function base64(): string
    {
        $marker = strpos($this->payload, self::MARKER);

        return $marker === false
            ? $this->payload
            : substr($this->payload, $marker + \strlen(self::MARKER));
    }

    /**
     * Isi berkas sebagai byte mentah, untuk gateway yang menuntut unggahan
     * multipart (Fonnte dan ApiMe).
     *
     * @throws ConfigurationException Bila isinya bukan base64 yang sah — mis.
     *         ketika pemanggil menyerahkan URL ke gateway yang tidak bisa
     *         mengunduhnya sendiri
     */
    public function bytes(): string
    {
        $decoded = base64_decode($this->base64(), true);

        if ($decoded === false) {
            throw new ConfigurationException(
                "Isi berkas '{$this->filename}' bukan base64 yang sah, dan bukan URL publik"
            );
        }

        return $decoded;
    }

    /** Jenis berkas yang disebut sebuah data URI, bila isinya memang data URI. */
    private static function mimeFromDataUri(string $payload): ?string
    {
        if (! str_starts_with($payload, 'data:')) {
            return null;
        }

        // Bentuknya `data:<mime>;base64,<isi>`. Data URI tanpa `;base64`
        // (`data:text/plain,halo`) juga sah, jadi koma dipakai sebagai
        // cadangan penanda akhir jenis.
        $end = strpos($payload, ';');

        if ($end === false) {
            $end = strpos($payload, ',');
        }

        if ($end === false) {
            return null;
        }

        $mime = substr($payload, 5, $end - 5);

        return $mime !== '' ? $mime : null;
    }

    private static function mimeFromExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? self::DEFAULT_MIME;
    }

    /** Nama berkas bawaan, disusun dari jenisnya supaya penerima tetap tahu isinya. */
    private static function defaultFilename(string $mime): string
    {
        return 'lampiran.' . (self::EXTENSION_BY_MIME[$mime] ?? 'bin');
    }
}
