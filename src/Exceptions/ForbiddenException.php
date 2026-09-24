<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * HTTP 403 — token dikenali tapi tidak punya hak untuk operasi ini.
 *
 * Kasus yang paling sering: ApiMe menuntut token ber-scope instance, sehingga
 * JWT hasil login maupun API token global ditolak di endpoint pengiriman.
 */
class ForbiddenException extends ApiException
{
}
