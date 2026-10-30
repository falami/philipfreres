<?php

declare(strict_types=1);
namespace App\Service\Intescia;

/** Only deliberately sanitized, user-facing messages belong in this exception. */
final class InsightException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
