<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Skills\Remote;

use RuntimeException;

/**
 * 从 GitHub 仓库发现并下载 Skills
 * 使用 curl_multi_exec 并发下载文件，对标 Laravel Boost 的 Guzzle EachPromise
 */
class GitHubSkillProvider
{
    private const CONCURRENT_DOWNLOADS = 25;
    private const API_TIMEOUT          = 30;
    private const DOWNLOAD_TIMEOUT     = 60;
    private const USER_AGENT           = 'ThinkPHP-Boost';

    /** @var array<string, mixed>|null */
    private ?array $cachedTree = null;

    private ?string $defaultBranch = null;

    public function __construct(private readonly GitHubRepository $repository) {}

    /**
     * 发现仓库中所有可用的 Skills
     *
     * @return array<string, RemoteSkill>  键为 skill name
     * @throws RuntimeException
     */
    public function discoverSkills(): array
    {
        $tree = $this->fetchRepositoryTree();

        $basePath     = $this->repository->path;
        $skillMarkers = [];

        foreach ($tree['tree'] as $item) {
            if ($item['type'] !== 'blob') {
                continue;
            }
            $basename = basename((string)$item['path']);
            if ($basename !== 'SKILL.md') {
                continue;
            }

            $skillDir = dirname((string)$item['path']);

            // 过滤路径前缀
            if ($basePath !== '') {
                $prefix = $basePath . '/';
                if (!str_starts_with($skillDir, $prefix)) {
                    continue;
                }
                // 不允许嵌套子目录
                $relative = substr($skillDir, strlen($prefix));
                if (str_contains($relative, '/')) {
                    continue;
                }
            } else {
                if (str_contains($skillDir, '/')) {
                    continue;
                }
            }

            $skillMarkers[] = $item;
        }

        $skills = [];
        foreach ($skillMarkers as $item) {
            $name = basename(dirname((string)$item['path']));
            $skills[$name] = new RemoteSkill(
                name: $name,
                repo: $this->repository->fullName(),
                path: dirname((string)$item['path']),
            );
        }

        return $skills;
    }

    /**
     * 下载单个 Skill 到本地目录
     *
     * @throws RuntimeException
     */
    public function downloadSkill(RemoteSkill $skill, string $targetPath): bool
    {
        $tree = $this->fetchRepositoryTree();

        $prefix     = $skill->path . '/';
        $skillFiles = array_filter(
            $tree['tree'],
            fn(array $item): bool => str_starts_with((string)$item['path'], $prefix)
        );

        if (empty($skillFiles)) {
            return false;
        }

        if (!$this->ensureDir($targetPath)) {
            return false;
        }

        // 先创建子目录
        foreach ($skillFiles as $item) {
            if ($item['type'] === 'tree') {
                $relative = substr((string)$item['path'], strlen($prefix));
                $this->ensureDir($targetPath . DIRECTORY_SEPARATOR . $relative);
            }
        }

        // 并发下载所有文件
        $files = array_filter($skillFiles, fn(array $item): bool => $item['type'] === 'blob');

        return $this->downloadFilesConcurrently(array_values($files), $skill->path, $targetPath);
    }

    /**
     * 并发下载多个文件（curl_multi）
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function downloadFilesConcurrently(array $files, string $basePath, string $targetPath): bool
    {
        $urls = [];
        foreach ($files as $item) {
            $urls[(string)$item['path']] = $this->buildRawUrl((string)$item['path']);
        }

        $results = $this->curlMultiGet($urls);

        foreach ($files as $item) {
            $filePath = (string)$item['path'];
            $content  = $results[$filePath] ?? null;

            if ($content === null || $content === false) {
                return false;
            }

            $relative  = substr($filePath, strlen($basePath . '/'));
            $localPath = $targetPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!$this->ensureDir(dirname($localPath))) {
                return false;
            }

            if (file_put_contents($localPath, $content) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 并发 GET 多个 URL，返回 [url_key => body_string|false]
     *
     * @param array<string, string> $urlMap  [key => url]
     * @return array<string, string|false>
     */
    private function curlMultiGet(array $urlMap): array
    {
        $results  = [];
        $handles  = [];
        $multiHandle = curl_multi_init();

        foreach ($urlMap as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
            ]);
            $this->applyToken($ch);
            $handles[$key] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
        }

        // 分批并发，每批 CONCURRENT_DOWNLOADS 个
        $keys   = array_keys($handles);
        $chunks = array_chunk($keys, self::CONCURRENT_DOWNLOADS);

        foreach ($chunks as $chunk) {
            $active = 0;
            do {
                $status = curl_multi_exec($multiHandle, $active);
                if ($active) {
                    curl_multi_select($multiHandle, 0.1);
                }
            } while ($active > 0 && $status === CURLM_OK);

            foreach ($chunk as $key) {
                $ch            = $handles[$key];
                $body          = curl_multi_getcontent($ch);
                $results[$key] = ($body !== false && curl_errno($ch) === 0) ? $body : false;
            }
        }

        foreach ($handles as $ch) {
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * 获取仓库完整文件树（带缓存）
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function fetchRepositoryTree(): array
    {
        if ($this->cachedTree !== null) {
            return $this->cachedTree;
        }

        $branch = $this->resolveDefaultBranch();
        $url    = sprintf(
            'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
            $this->repository->owner,
            $this->repository->repo,
            urlencode($branch)
        );

        [$body, $httpCode] = $this->get($url);

        if ($httpCode === 403) {
            throw new RuntimeException(
                'GitHub API 访问被拒绝（可能触发速率限制）。请通过环境变量 GITHUB_TOKEN 配置 Personal Access Token。'
            );
        }

        if ($httpCode !== 200 || $body === false) {
            $msg = $body ? (json_decode($body, true)['message'] ?? 'Unknown error') : 'Request failed';
            throw new RuntimeException("无法获取仓库文件树：{$msg}（HTTP {$httpCode}）");
        }

        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['tree'])) {
            throw new RuntimeException('GitHub API 返回格式异常');
        }

        $this->cachedTree = $data;
        return $data;
    }

    private function resolveDefaultBranch(): string
    {
        if ($this->defaultBranch !== null) {
            return $this->defaultBranch;
        }

        $url          = "https://api.github.com/repos/{$this->repository->owner}/{$this->repository->repo}";
        [$body, $code] = $this->get($url, 15);

        if ($code === 200 && $body !== false) {
            $data = json_decode($body, true);
            if (is_string($data['default_branch'] ?? null)) {
                $this->defaultBranch = $data['default_branch'];
                return $this->defaultBranch;
            }
        }

        $this->defaultBranch = 'main';
        return $this->defaultBranch;
    }

    private function buildRawUrl(string $path): string
    {
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            $this->repository->owner,
            $this->repository->repo,
            $this->resolveDefaultBranch(),
            ltrim($path, '/')
        );
    }

    /**
     * @return array{string|false, int}  [body, http_code]
     */
    private function get(string $url, int $timeout = self::API_TIMEOUT): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $this->applyToken($ch);

        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$body, $httpCode];
    }

    /** @param resource $ch */
    private function applyToken($ch): void
    {
        $token = getenv('GITHUB_TOKEN') ?: null;
        if ($token !== null && $token !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/vnd.github.v3+json',
                "Authorization: Bearer {$token}",
            ]);
        }
    }

    private function ensureDir(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0755, true);
    }
}
