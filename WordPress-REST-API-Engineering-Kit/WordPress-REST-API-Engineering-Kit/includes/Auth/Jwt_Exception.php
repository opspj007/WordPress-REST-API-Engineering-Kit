<?php

declare(strict_types=1);

namespace RAEK\Auth;

final class Jwt_Exception extends \RuntimeException
{
    public function __construct(private string $reason)
    {
        parent::__construct($reason);
    }

    /** Machine-readable cause: malformed|unsupported_algorithm|bad_signature|bad_audience|expired|not_yet_valid */
    public function reason(): string
    {
        return $this->reason;
    }
}
