<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/** Request melewati batas waktu, baik saat menyambung maupun menunggu balasan. */
class TimeoutException extends WhatsappException
{
    public function __construct(private readonly float $timeout, ?string $detail = null)
    {
        parent::__construct(
            $detail === null || $detail === ''
                ? "Request melewati batas waktu {$timeout} detik"
                : "Request melewati batas waktu {$timeout} detik: {$detail}"
        );
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }
}
