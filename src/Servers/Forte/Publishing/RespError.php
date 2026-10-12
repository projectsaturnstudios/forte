<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

final class RespError
{
    public function __construct(public readonly string $message)
    {
    }
}
