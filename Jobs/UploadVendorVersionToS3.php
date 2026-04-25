<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Jobs;

use Core\Mod\Uptelligence\Models\AssetVersion;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Core\Mod\Uptelligence\Services\VendorStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class UploadVendorVersionToS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $modelClass,
        public int|string $modelId,
    ) {
        $this->onQueue('uptelligence-storage');
    }

    public function handle(VendorStorageService $storage): void
    {
        $model = $this->resolveModel();

        if ($model instanceof VersionRelease) {
            $storage->archiveToS3Synchronously($model);

            return;
        }

        if ($model instanceof AssetVersion) {
            $storage->uploadAssetVersionToS3Synchronously($model);

            return;
        }

        throw new RuntimeException('Unsupported vendor archive model: '.$this->modelClass);
    }

    private function resolveModel(): Model
    {
        if (! is_a($this->modelClass, Model::class, true)) {
            throw new RuntimeException('Invalid model class for vendor archive upload.');
        }

        /** @var Model|null $model */
        $model = $this->modelClass::query()->find($this->modelId);

        if (! $model instanceof Model) {
            throw new RuntimeException("Vendor archive model not found: {$this->modelClass}#{$this->modelId}");
        }

        return $model;
    }
}
