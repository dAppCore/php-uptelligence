<?php

declare(strict_types=1);

use Core\Mod\Uptelligence\Data\DiffResult;
use Core\Mod\Uptelligence\Services\DiffAnalyzerService;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->diffRoot = sys_get_temp_dir().'/uptelligence-diff-test-'.bin2hex(random_bytes(4));
    File::makeDirectory($this->diffRoot, 0755, true);
});

afterEach(function (): void {
    if (isset($this->diffRoot) && File::isDirectory($this->diffRoot)) {
        File::deleteDirectory($this->diffRoot);
    }
});

function writeDiffFixture(string $path, string $content): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $content);
}

describe('_Good', function (): void {
    it('returns changed files, breaking changes, and migration steps for version directories', function (): void {
        $old = $this->diffRoot.'/old';
        $new = $this->diffRoot.'/new';

        writeDiffFixture($old.'/app/Services/BillingService.php', "<?php\nclass BillingService\n{\n    public function charge(int \$amount): bool\n    {\n        return true;\n    }\n}\n");
        writeDiffFixture($old.'/app/LegacyGateway.php', "<?php\nclass LegacyGateway {}\n");
        writeDiffFixture($new.'/app/Services/BillingService.php', "<?php\nclass BillingService\n{\n    public function charge(int \$amount, string \$currency): bool\n    {\n        return true;\n    }\n}\n");
        writeDiffFixture($new.'/database/migrations/2026_04_25_000001_create_orders_table.php', "<?php\nreturn new class {};\n");

        $result = (new DiffAnalyzerService)->diff($old, $new);

        expect($result)->toBeInstanceOf(DiffResult::class)
            ->and($result->changedFiles)->toContain('app/Services/BillingService.php')
            ->and($result->changedFiles)->toContain('app/LegacyGateway.php')
            ->and($result->changedFiles)->toContain('database/migrations/2026_04_25_000001_create_orders_table.php')
            ->and($result->filesChanged)->toBe(3)
            ->and($result->additions)->toBeGreaterThan(0)
            ->and($result->deletions)->toBeGreaterThan(0)
            ->and($result->breakingChanges)->not->toBeEmpty()
            ->and($result->migrationSteps)->not->toBeEmpty();
    });
});

describe('_Bad', function (): void {
    it('filters vendored, build, minified, and binary noise', function (): void {
        $old = $this->diffRoot.'/old';
        $new = $this->diffRoot.'/new';

        writeDiffFixture($old.'/vendor/package/File.php', "<?php\nreturn 'old';\n");
        writeDiffFixture($new.'/vendor/package/File.php', "<?php\nreturn 'new';\n");
        writeDiffFixture($old.'/public/build/app.min.js', "const value=1;\n");
        writeDiffFixture($new.'/public/build/app.min.js', "const value=2;\n");
        writeDiffFixture($old.'/public/images/logo.png', "old\0binary");
        writeDiffFixture($new.'/public/images/logo.png', "new\0binary");

        $result = (new DiffAnalyzerService)->diff($old, $new);

        expect($result->changedFiles)->toBeEmpty()
            ->and($result->filesChanged)->toBe(0)
            ->and($result->migrationSteps)->toBeEmpty();
    });
});

describe('_Ugly', function (): void {
    it('rejects null-byte version paths before invoking diff tooling', function (): void {
        $old = $this->diffRoot.'/old';
        $new = $this->diffRoot.'/new';

        File::makeDirectory($old, 0755, true);
        File::makeDirectory($new, 0755, true);

        expect(fn () => (new DiffAnalyzerService)->diff($old."\0", $new))
            ->toThrow(InvalidArgumentException::class);
    });
});
