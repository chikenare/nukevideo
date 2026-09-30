<?php

namespace App\Data\Node;

use App\Data\RequestData;

class StopNodeData extends RequestData
{
    public function __construct(
        /** `docker kill` instead of letting Horizon finish its in-flight jobs. */
        public bool $force = false,
    ) {}
}
