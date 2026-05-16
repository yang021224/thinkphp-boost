<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Skills\Remote;

/**
 * 调用安全审计 API（与 Laravel Boost 共用同一个审计服务端点）
 */
class SkillAuditor
{
    private const AUDIT_URL      = 'https://skills.laravel.cloud/api/v1/skills/audit';
    private const TIMEOUT        = 5;

    /**
     * @param  string[]            $skillSlugs
     * @return array<string, AuditResult[]>  [skill_name => AuditResult[]]
     */
    public function audit(string $source, array $skillSlugs): array
    {
        if (empty($skillSlugs)) {
            return [];
        }

        $url  = self::AUDIT_URL . '?' . http_build_query([
            'source' => $source,
            'skills' => implode(',', $skillSlugs),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'ThinkPHP-Boost',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return [];
        }

        return $this->parseResponse($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, AuditResult[]>
     */
    private function parseResponse(array $data): array
    {
        $results = [];

        foreach ($data as $skill => $partners) {
            if (!is_array($partners)) {
                continue;
            }

            $skillResults = [];

            foreach ($partners as $partner => $audit) {
                if (!is_array($audit)) {
                    continue;
                }

                $risk = Risk::tryFrom((string)($audit['risk'] ?? ''));
                if ($risk === null) {
                    continue;
                }

                $skillResults[] = new AuditResult(
                    partner:    (string)$partner,
                    risk:       $risk,
                    alerts:     isset($audit['alerts']) ? (int)$audit['alerts'] : null,
                    analyzedAt: isset($audit['analyzedAt']) ? (string)$audit['analyzedAt'] : null,
                );
            }

            if (!empty($skillResults)) {
                $results[(string)$skill] = $skillResults;
            }
        }

        return $results;
    }
}
