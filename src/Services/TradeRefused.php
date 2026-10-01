<?php

declare(strict_types=1);

namespace BNT\Services;

/** A rule-based refusal (not a bug): carries a stable machine-readable code. */
class TradeRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'REFUSED')
    {
        parent::__construct($message);
    }
}
