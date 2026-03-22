<?php

namespace App\Exceptions;

use RuntimeException;

class BillingApiException extends RuntimeException
{
    protected int $httpStatus;
    protected ?string $responseBody;
    protected string $endpoint;

    public function __construct(
        string $message,
        string $endpoint = '',
        int $httpStatus = 0,
        ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
        $this->endpoint     = $endpoint;

        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function context(): array
    {
        return [
            'endpoint'      => $this->endpoint,
            'http_status'   => $this->httpStatus,
            'response_body' => $this->responseBody,
        ];
    }
}
