<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Normalisasi nomor HP Indonesia ke format internasional tanpa tanda plus,
 * mis. "0812-3456-7890" / "+62 812 3456 7890" => "6281234567890".
 */
final class PhoneNumber
{
    public const COUNTRY_CODE = '62';

    public static function normalize(string $number): string
    {
        // buang spasi, strip, kurung, titik, dan tanda plus
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return '';
        }

        // 0812... => 62812...
        if (str_starts_with($digits, '0')) {
            return self::COUNTRY_CODE . substr($digits, 1);
        }

        // 62812... sudah benar
        if (str_starts_with($digits, self::COUNTRY_CODE)) {
            return $digits;
        }

        // 812... (tanpa awalan apa pun) => 62812...
        if (str_starts_with($digits, '8')) {
            return self::COUNTRY_CODE . $digits;
        }

        // nomor luar negeri atau format tak dikenal: biarkan apa adanya
        return $digits;
    }

    /**
     * Ubah nomor menjadi WhatsApp ID perorangan (WID), mis. "6281234567890@c.us".
     * Nomor yang sudah berupa JID/WID (mengandung "@") dikembalikan apa adanya.
     */
    public static function toWid(string $number): string
    {
        if (str_contains($number, '@')) {
            return $number;
        }

        return self::normalize($number) . '@c.us';
    }
}
