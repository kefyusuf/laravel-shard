<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Dashboard card showing the request distribution across shard connections.
 */
#[Lazy]
class ShardUsageCard extends Card
{
    public function render(): Renderable
    {
        [$usage, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(
                'shard_request',
                ['count', 'sum'],
                'sum',
            ),
        );

        return View::make('redis-shard::pulse.shard-usage', [
            'time' => $time,
            'runAt' => $runAt,
            'usage' => $usage,
        ]);
    }
}
