<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/**
 * Gateway menjawab, tapi menolak pesannya.
 *
 * Membawa status HTTP dan body hasil decode, jadi pemanggil bisa memeriksa
 * detailnya tanpa harus mengurai ulang pesan exception. Pakai subclass bernama
 * untuk status yang umum, atau bercabang pada {@see getStatus()}.
 *
 * Perlu dicatat: beberapa gateway membalas HTTP 200 walau pesannya gagal
 * (Fonnte memakai `status`, wuzapi memakai `success`). Kegagalan seperti itu
 * tetap dilaporkan sebagai ApiException, dengan status dari amplop body.
 */
class ApiException extends WhatsappException
{
    /** @param mixed $body */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly mixed $body = null,
        private readonly ?string $errorKind = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /** Penanda jenis error dari gateway, bila ada (mis. `error` pada amplop). */
    public function getErrorKind(): ?string
    {
        return $this->errorKind;
    }

    /**
     * Bangun subclass paling spesifik untuk sebuah status HTTP.
     *
     * @param mixed $body
     */
    public static function classify(
        int $status,
        string $message,
        mixed $body = null,
        ?string $errorKind = null,
        ?\Throwable $previous = null
    ): self {
        return match ($status) {
            401 => new AuthException($message, $status, $body, $errorKind, $previous),
            403 => new ForbiddenException($message, $status, $body, $errorKind, $previous),
            404 => new NotFoundException($message, $status, $body, $errorKind, $previous),
            409 => new ConflictException($message, $status, $body, $errorKind, $previous),
            429 => new RateLimitException($message, $status, $body, $errorKind, $previous),
            503 => new ServiceUnavailableException($message, $status, $body, $errorKind, $previous),
            default => new self($message, $status, $body, $errorKind, $previous),
        };
    }
}
