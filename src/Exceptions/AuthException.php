<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/** HTTP 401 — token ditolak gateway. Periksa `WHATSAPP_TOKEN`. */
class AuthException extends ApiException
{
}
