<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * HTTP 503 — sesi WhatsApp belum siap, jadi tidak ada pesan yang terkirim.
 *
 * Aman diretry setelah sesi tersambung kembali.
 */
class ServiceUnavailableException extends ApiException
{
}
