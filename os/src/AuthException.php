<?php
declare(strict_types=1);

namespace BesharOs;

final class AuthException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
