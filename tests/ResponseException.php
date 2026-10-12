<?php

namespace ProjectSaturnStudios\Forte\Tests;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** An HTTP answer of 400 or above; the exception code is the status. */
final class ResponseException extends RuntimeException
{
    public function __construct(private ResponseInterface $response)
    {
        parent::__construct(
            "HTTP status code {$response->getStatusCode()} ({$response->getReasonPhrase()})",
            $response->getStatusCode(),
        );
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
