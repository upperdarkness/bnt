<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Services\NewsService;
use BNT\Services\ProtectionService;
use BNT\Services\RumourService;

/** Scheduler tasks for newbie protection, journalist candidates and rumour seeds. No LLM calls happen here. */
class ContentSchedulerTasks
{
    public function __construct(
        private ProtectionService $protection,
        private NewsService $news,
        private RumourService $rumours
    ) {}

    public function protectionTick(): string
    {
        $r = $this->protection->tick();
        return "Protection ended for {$r['ended']}, grace periods over for {$r['graces_over']}";
    }

    public function newsCandidates(): string
    {
        if (!$this->news->enabled()) {
            return 'journalist disabled';
        }
        $r = $this->news->scan();
        return "Candidates {$r['candidates']}, interviews {$r['interviews']}, below threshold {$r['skipped']}";
    }

    public function rumourSeeds(): string
    {
        if (!$this->rumours->enabled()) {
            return 'rumours disabled';
        }
        $r = $this->rumours->generate();
        return 'Seeds created: ' . json_encode($r['created']) . ", pruned {$r['pruned']}";
    }
}
