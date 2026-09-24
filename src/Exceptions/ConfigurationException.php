<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * Konfigurasi belum lengkap atau tidak konsisten — token kosong, URL tidak
 * valid, session/instance belum diisi, atau bentuk pesan salah.
 *
 * Kegagalan jenis ini tidak akan sembuh kalau diulang, jadi jangan diretry.
 */
class ConfigurationException extends WhatsappException
{
}
