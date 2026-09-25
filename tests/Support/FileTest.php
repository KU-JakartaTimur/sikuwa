<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Support\File;

/**
 * Penyamaan bentuk berkas antar gateway.
 *
 * Tidak ada satu bentuk kiriman berkas yang diterima semua gateway — ada yang
 * mau data URI, ada yang mau base64 telanjang, ada yang mau byte mentah — jadi
 * kelas inilah yang menerjemahkannya. Yang diuji di sini terjemahannya, bukan
 * pengirimannya.
 */
final class FileTest extends TestCase
{
    /** Base64 dari "halo dunia" — isinya sengaja terbaca supaya mudah ditelusuri. */
    private const B64 = 'aGFsbyBkdW5pYQ==';

    public function testMimeComesFromTheExtension(): void
    {
        self::assertSame('image/png', File::from(self::B64, 'bukti.png')->mime);
        self::assertSame('application/pdf', File::from(self::B64, 'invoice.PDF')->mime);
        self::assertSame('application/zip', File::from(self::B64, 'arsip.zip')->mime);
    }

    public function testUnknownExtensionBecomesOctetStream(): void
    {
        // Berkas yang jenisnya tidak dikenali tetap bisa dikirim — WhatsApp
        // memperlakukannya sebagai dokumen biasa, bukan menolaknya.
        self::assertSame('application/octet-stream', File::from(self::B64, 'aneh.xyz')->mime);
        self::assertSame('application/octet-stream', File::from(self::B64, 'tanpa-ekstensi')->mime);
    }

    public function testMimeFromDataUriBeatsTheFilename(): void
    {
        $file = File::from('data:image/jpeg;base64,' . self::B64, 'salah-tulis.png');

        // Isi berkas lebih dipercaya daripada nama berkasnya.
        self::assertSame('image/jpeg', $file->mime);
        self::assertTrue($file->isImage());
    }

    public function testGivenFilenameIsKept(): void
    {
        self::assertSame('bukti.png', File::from(self::B64, 'bukti.png')->filename);
        // Spasi di sekitar nama dibuang supaya tidak ikut terkirim.
        self::assertSame('bukti.png', File::from(self::B64, '  bukti.png  ')->filename);
    }

    public function testDefaultFilenameFollowsTheMime(): void
    {
        // Nama berkas wajib ada: wuzapi menolak dokumen tanpa nama, jadi nama
        // bawaan harus selalu terisi.
        self::assertSame('lampiran.png', File::from('data:image/png;base64,' . self::B64)->filename);
        self::assertSame('lampiran.pdf', File::from('data:application/pdf;base64,' . self::B64)->filename);
        self::assertSame('lampiran.bin', File::from(self::B64)->filename);
    }

    public function testIsImageFollowsTheMime(): void
    {
        self::assertTrue(File::from(self::B64, 'a.png')->isImage());
        self::assertTrue(File::from(self::B64, 'a.jpeg')->isImage());
        self::assertFalse(File::from(self::B64, 'a.pdf')->isImage());
        self::assertFalse(File::from(self::B64, 'a.zip')->isImage());
    }

    public function testIsUrlOnlyForHttpAndHttps(): void
    {
        self::assertTrue(File::from('https://contoh.test/bukti.png')->isUrl());
        self::assertTrue(File::from('http://contoh.test/bukti.png')->isUrl());
        self::assertFalse(File::from(self::B64, 'a.png')->isUrl());
        // Data URI tidak pernah dianggap URL, walaupun isinya panjang dan
        // mengandung "://" di dalam base64-nya.
        self::assertFalse(File::from('data:image/png;base64,' . self::B64)->isUrl());
    }

    public function testDataUriIsIdempotent(): void
    {
        $wrapped = File::from(self::B64, 'a.png')->dataUri();

        self::assertSame('data:image/png;base64,' . self::B64, $wrapped);
        // Yang sudah data URI tidak boleh dibungkus dua kali.
        self::assertSame($wrapped, File::from($wrapped)->dataUri());
    }

    public function testBase64StripsTheDataUriPrefix(): void
    {
        self::assertSame(self::B64, File::from('data:image/png;base64,' . self::B64)->base64());
        // Yang memang sudah base64 telanjang dikembalikan apa adanya.
        self::assertSame(self::B64, File::from(self::B64, 'a.png')->base64());
    }

    public function testBytesDecodesBackToRawContent(): void
    {
        self::assertSame('halo dunia', File::from(self::B64, 'a.txt')->bytes());
        self::assertSame('halo dunia', File::from('data:text/plain;base64,' . self::B64)->bytes());
    }

    public function testBytesRejectsAPayloadThatIsNeitherBase64NorUsable(): void
    {
        $file = File::from('https://contoh.test/bukti.png');

        // Gateway yang mengunggah byte mentah tidak bisa memakai URL, dan
        // kegagalannya harus menjelaskan kenapa.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('bukan base64 yang sah');

        $file->bytes();
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Isi berkas kosong');

        File::from('   ');
    }
}
