<?php

namespace App\Events;

use App\Models\ErrorEvent;
use App\Models\Issue;
use Illuminate\Foundation\Events\Dispatchable;

class PingProcessed
{
    use Dispatchable;

    public function __construct(
        public ErrorEvent $event,
        public Issue $issue,
        public bool $isNewIssue,
        public bool $isRegression,
    ) {}
}
