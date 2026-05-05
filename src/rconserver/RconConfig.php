<?php

declare(strict_types = 1);

namespace rconserver;

class RconConfig{

    public function __construct(
        public readonly string $ip,
        public readonly int $port,
        public readonly int $maxConnections,
        public readonly string $password
    ){}
}