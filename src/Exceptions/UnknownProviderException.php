<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Exceptions;

/** Nama gateway tidak dikenali. */
class UnknownProviderException extends ConfigurationException
{
    /** @param array<int,string> $known */
    public static function forName(string $name, array $known): self
    {
        return new self(sprintf(
            'Provider "%s" tidak dikenali. Yang tersedia: %s.',
            $name,
            implode(', ', $known)
        ));
    }
}
