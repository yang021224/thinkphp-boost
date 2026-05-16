<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Skills\Remote;

class RemoteSkill
{
    public function __construct(
        public readonly string $name,
        public readonly string $repo,
        public readonly string $path,
    ) {}
}
