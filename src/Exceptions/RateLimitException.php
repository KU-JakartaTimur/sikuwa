<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/** HTTP 429 — kena batas laju gateway. Satu-satunya status yang aman diretry. */
class RateLimitException extends ApiException
{
}
