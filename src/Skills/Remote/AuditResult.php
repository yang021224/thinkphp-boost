<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Skills\Remote;

class AuditResult
{
    public function __construct(
        public readonly string  $partner,
        public readonly Risk    $risk,
        public readonly ?int    $alerts     = null,
        public readonly ?string $analyzedAt = null,
    ) {}
}
