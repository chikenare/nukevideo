<?php

namespace App\Services;

use ClickHouseDB\Client;

class AnalyticsService
{
    private Client $client;

    public function __construct()
    {
        $this->client = app(Client::class);
    }

    public function summary(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                sum(bytes) AS total_bytes,
                uniqExact(session_id) AS total_sessions,
                uniqExact(video_ulid) AS unique_videos,
                uniqExact(ip) AS unique_ips
            FROM sessions
            WHERE toDate(timestamp) >= {from:Date} AND toDate(timestamp) <= {to:Date}
        SQL, ['from' => $from, 'to' => $to]);

        $row = $result->fetchOne();

        return [
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'total_sessions' => (int) ($row['total_sessions'] ?? 0),
            'unique_videos' => (int) ($row['unique_videos'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
        ];
    }

    public function bandwidthOverTime(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                toDate(timestamp) AS date,
                sum(bytes) AS bytes,
                uniqExact(session_id) AS sessions
            FROM sessions
            WHERE toDate(timestamp) >= {from:Date} AND toDate(timestamp) <= {to:Date}
            GROUP BY date
            ORDER BY date
        SQL, ['from' => $from, 'to' => $to]);

        return $result->rows();
    }

    public function topIps(string $from, string $to, int $limit = 10): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                IPv6NumToString(ip) AS ip,
                sum(bytes) AS bytes,
                uniqExact(session_id) AS sessions
            FROM sessions
            WHERE toDate(timestamp) >= {from:Date} AND toDate(timestamp) <= {to:Date}
            GROUP BY ip
            ORDER BY bytes DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        return $result->rows();
    }

    public function topVideos(string $from, string $to, int $limit = 10): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                video_ulid AS video,
                external_resource_id AS externalResourceId,
                sum(bytes) AS bytes,
                uniqExact(session_id) AS sessions,
                uniqExact(ip) AS uniqueIps
            FROM sessions
            WHERE toDate(timestamp) >= {from:Date} AND toDate(timestamp) <= {to:Date}
            GROUP BY video, externalResourceId
            ORDER BY bytes DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        return $result->rows();
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = 5): array
    {
        $topVideos = $this->client->select(<<<'SQL'
            SELECT video_ulid
            FROM sessions
            WHERE toDate(timestamp) >= {from:Date} AND toDate(timestamp) <= {to:Date}
            GROUP BY video_ulid
            ORDER BY sum(bytes) DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        $videoIds = array_column($topVideos->rows(), 'video_ulid');

        if (empty($videoIds)) {
            return [];
        }

        $placeholders = implode(',', array_map(fn ($v) => "'{$v}'", $videoIds));

        $result = $this->client->select(<<<SQL
            SELECT
                toDate(timestamp) AS date,
                video_ulid AS video,
                sum(bytes) AS bytes
            FROM sessions
            WHERE toDate(timestamp) >= '{$from}' AND toDate(timestamp) <= '{$to}'
              AND video_ulid IN ({$placeholders})
            GROUP BY date, video
            ORDER BY date, video
        SQL);

        return $result->rows();
    }

    public function encodingUsage(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                replaceOne(metric, 'encoding_', '') AS device,
                sum(value) AS total_seconds
            FROM usage
            WHERE metric IN ('encoding_cpu', 'encoding_gpu')
              AND date >= {from:Date} AND date <= {to:Date}
            GROUP BY metric
        SQL, ['from' => $from, 'to' => $to]);

        $usage = ['cpu' => 0, 'gpu' => 0];

        foreach ($result->rows() as $row) {
            $usage[$row['device']] = round($row['total_seconds'], 2);
        }

        return $usage;
    }

    public function usageSummary(string $from, string $to, ?int $userId = null): array
    {
        $where = 'date >= {from:Date} AND date <= {to:Date}';
        $params = ['from' => $from, 'to' => $to];

        if ($userId) {
            $where .= ' AND user_id = {user_id:UInt32}';
            $params['user_id'] = $userId;
        }

        $result = $this->client->select(
            "SELECT metric, sum(value) AS total FROM usage WHERE {$where} GROUP BY metric",
            $params
        );

        $usage = ['upload_bytes' => 0, 'encoding_cpu' => 0, 'encoding_gpu' => 0];

        foreach ($result->rows() as $row) {
            if (isset($usage[$row['metric']])) {
                $usage[$row['metric']] = round($row['total'], 2);
            }
        }

        return $usage;
    }

    public function topExternalUsers(string $from, string $to, ?int $userId = null, int $limit = 10): array
    {
        $where = "metric = 'upload_bytes' AND date >= {from:Date} AND date <= {to:Date} AND external_user_id != ''";
        $params = ['from' => $from, 'to' => $to, 'limit' => $limit];

        if ($userId) {
            $where .= ' AND user_id = {user_id:UInt32}';
            $params['user_id'] = $userId;
        }

        $result = $this->client->select(
            "SELECT external_user_id AS externalUserId, sum(value) AS bytes
             FROM usage
             WHERE {$where}
             GROUP BY external_user_id
             ORDER BY bytes DESC
             LIMIT {limit:UInt8}",
            $params
        );

        return $result->rows();
    }

    public function encodingUsageOverTime(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                date,
                replaceOne(metric, 'encoding_', '') AS device,
                sum(value) AS seconds
            FROM usage
            WHERE metric IN ('encoding_cpu', 'encoding_gpu')
              AND date >= {from:Date} AND date <= {to:Date}
            GROUP BY date, metric
            ORDER BY date
        SQL, ['from' => $from, 'to' => $to]);

        return $result->rows();
    }
}
