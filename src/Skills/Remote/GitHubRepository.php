<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Skills\Remote;

use InvalidArgumentException;

class GitHubRepository
{
    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
        public readonly string $path = '',
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromInput(string $input): self
    {
        $input = self::normalizeUrl($input);
        return self::parseOwnerRepoPath($input);
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->repo}";
    }

    public function source(): string
    {
        return $this->path === '' ? $this->fullName() : $this->fullName() . '/' . $this->path;
    }

    private static function normalizeUrl(string $input): string
    {
        if (!str_starts_with($input, 'http://') && !str_starts_with($input, 'https://')) {
            return $input;
        }

        $parsed = parse_url($input);
        $host   = $parsed['host'] ?? '';

        if ($host !== 'github.com' && !str_ends_with($host, '.github.com')) {
            throw new InvalidArgumentException('Only GitHub URLs are supported.');
        }

        $path = trim($parsed['path'] ?? '', '/');

        // 去掉 /tree/branch-name 部分
        if (preg_match('#^([^/]+/[^/]+)/tree/[^/]+(?:/(.*))?$#', $path, $m)) {
            return isset($m[2]) && $m[2] !== '' ? $m[1] . '/' . $m[2] : $m[1];
        }

        return $path;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function parseOwnerRepoPath(string $input): self
    {
        $parts = explode('/', $input);

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(
                'Invalid repository format. Expected: owner/repo, owner/repo/path, or GitHub URL'
            );
        }

        return new self(
            owner: $parts[0],
            repo:  $parts[1],
            path:  implode('/', array_slice($parts, 2)),
        );
    }
}
