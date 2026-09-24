<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * HTTP 404 — endpoint atau instance tidak ditemukan.
 *
 * Biasanya berarti `WHATSAPP_INSTANCE` / `WHATSAPP_SESSION` salah, bukan
 * gateway-nya yang mati.
 */
class NotFoundException extends ApiException
{
}
