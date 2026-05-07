<?php

declare(strict_types = 1);

namespace rconserver;

final readonly class RconConfig{

    public function __construct(
        public string $ip,
        public int $port,
        public string $password,
        public int $maxConnections
    ){}
}