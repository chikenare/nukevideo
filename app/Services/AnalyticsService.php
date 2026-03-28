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
                count() AS total_requests,
                uniqExact(video) AS unique_videos,
                uniqExact(ip) AS unique_ips
            FROM bandwidth_metrics
            WHERE date >= {from:Date} AND date <= {to:Date}
        SQL, ['from' => $from, 'to' => $to]);

        $row = $result->fetchOne();

        return [
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'total_requests' => (int) ($row['total_requests'] ?? 0),
            'unique_videos' => (int) ($row['unique_videos'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
        ];
    }

    public function bandwidthOverTime(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                date,
                sum(bytes) AS bytes,
                count() AS requests
            FROM bandwidth_metrics
            WHERE date >= {from:Date} AND date <= {to:Date}
            GROUP BY date
            ORDER BY date
        SQL, ['from' => $from, 'to' => $to]);

        return $result->rows();
    }

    public function topIps(string $from, string $to, int $limit = 10): array
    {
        $result = $this->client->select(<<<SQL
            SELECT
                IPv6NumToString(ip) AS ip,
                sum(bytes) AS bytes,
                count() AS requests
            FROM bandwidth_metrics
            WHERE date >= {from:Date} AND date <= {to:Date}
            GROUP BY ip
            ORDER BY bytes DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        return $result->rows();
    }

    public function topVideos(string $from, string $to, int $limit = 10): array
    {
        $result = $this->client->select(<<<SQL
            SELECT
                video,
                extid,
                sum(bytes) AS bytes,
                count() AS requests,
                uniqExact(ip) AS unique_ips
            FROM bandwidth_metrics
            WHERE date >= {from:Date} AND date <= {to:Date}
            GROUP BY video, extid
            ORDER BY bytes DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        return $result->rows();
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = 5): array
    {
        // Get top N videos first
        $topVideos = $this->client->select(<<<SQL
            SELECT video
            FROM bandwidth_metrics
            WHERE date >= {from:Date} AND date <= {to:Date}
            GROUP BY video
            ORDER BY sum(bytes) DESC
            LIMIT {limit:UInt8}
        SQL, ['from' => $from, 'to' => $to, 'limit' => $limit]);

        $videoIds = array_column($topVideos->rows(), 'video');

        if (empty($videoIds)) {
            return [];
        }

        $placeholders = implode(',', array_map(fn ($v) => "'{$v}'", $videoIds));

        $result = $this->client->select(<<<SQL
            SELECT
                date,
                video,
                sum(bytes) AS bytes
            FROM bandwidth_metrics
            WHERE date >= '{$from}' AND date <= '{$to}'
              AND video IN ({$placeholders})
            GROUP BY date, video
            ORDER BY date, video
        SQL);

        return $result->rows();
    }
}
