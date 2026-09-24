<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * HTTP 409 — bertabrakan dengan permintaan lain yang masih berjalan.
 *
 * Pada ApiMe ini muncul saat pengiriman dengan `Idempotency-Key` yang sama
 * masih diproses; pesannya kemungkinan besar tetap terkirim, jadi jangan
 * langsung dikirim ulang.
 */
class ConflictException extends ApiException
{
}
