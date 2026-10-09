<?php

declare(strict_types=1);

namespace SellDataToAI;

/** Non-2xx answer (status > 0) or transport failure (status 0). */
final class ApiException extends \RuntimeException
{
    public int $status;
    public ?string $errorCode;
    /** @var mixed */
    public $body;

    /** @param mixed $body */
    public function __construct(int $status, ?string $errorCode, string $message, $body = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
    }
}
