<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pembacaan nilai dari amplop JSON gateway.
 *
 * Tiap gateway menaruh field yang sama dengan bentuk yang berbeda — kadang
 * string, kadang angka, kadang objek bersarang. Pemanggil biasanya hanya butuh
 * teksnya; nilai yang bukan skalar (array, null) dianggap tidak ada.
 */
final class Text
{
    public static function of(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Nilai pertama yang benar-benar terisi, dalam urutan prioritas.
     *
     * Dipakai karena nama field antar gateway berbeda untuk hal yang sama —
     * mis. nomor telepon ada sebagai `phoneNumber`, `phone_number`, atau `jid`.
     */
    public static function first(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = self::of($candidate);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /** Apakah $value sama dengan salah satu $candidates (tanpa peduli besar-kecil). */
    public static function equalsAny(string $value, string ...$candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (strcasecmp($value, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }
}
