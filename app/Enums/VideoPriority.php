<?php

namespace App\Enums;

/**
 * Where a pending video sits in the dispatch queue. It only reorders PENDING videos: one already
 * claimed keeps its slot, and its chunks share the same FIFO encode queue whatever its priority.
 */
enum VideoPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';

    /**
     * The order `videos:dispatch` offers pending videos in, one level at a time: each level is an
     * equality on the `(status, priority)` index, so the walk inside it stays an index scan
     * instead of sorting the whole backlog on a CASE expression.
     *
     * @return list<self>
     */
    public static function dispatchOrder(): array
    {
        return [self::HIGH, self::NORMAL, self::LOW];
    }
}
