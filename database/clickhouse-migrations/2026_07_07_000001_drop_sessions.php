<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {
        // Superseded by `video_usage`: bandwidth is tracked by video + ip, not by session.
        $this->clickhouseClient->write('DROP TABLE IF EXISTS sessions');
    }
};
