<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * Kelas dasar untuk setiap error yang dilempar SDK ini.
 *
 * Menangkap kelas ini berarti menangkap semuanya — termasuk kegagalan
 * konfigurasi, kegagalan transport, dan penolakan dari gateway.
 */
class WhatsappException extends \Exception
{
}
