<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Jobs;

use Core\Mod\Uptelligence\Models\UptelligenceDigest;
use Core\Mod\Uptelligence\Services\UptelligenceDigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendUptelligenceDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public UptelligenceDigest $digest,
    ) {
        $this->onQueue('uptelligence-digests');
    }

    public function handle(UptelligenceDigestService $service): void
    {
        $service->sendDigest($this->digest);
    }
}
