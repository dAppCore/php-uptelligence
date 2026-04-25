<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence;

use Core\Events\AdminPanelBooting;
use Core\Events\ApiRoutesRegistering;
use Core\Events\ConsoleBooting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Uptelligence Module Boot
 *
 * Upstream vendor tracking and dependency intelligence.
 * Manages vendor versions, diffs, todos, and asset tracking.
 */
class Boot extends ServiceProvider
{
    protected string $moduleName = 'uptelligence';

    /**
     * Events this module listens to for lazy loading.
     *
     * @var array<class-string, string>
     */
    public static array $listens = [
        AdminPanelBooting::class => 'onAdminPanel',
        ApiRoutesRegistering::class => 'onApiRoutes',
        ConsoleBooting::class => 'onConsole',
    ];

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->configureRateLimiting();
        $this->validateConfig();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config.php',
            'upstream'
        );

        $this->mergeConfigFrom(
            __DIR__.'/config.php',
            'uptelligence'
        );

        $this->app->singleton(\Core\Mod\Uptelligence\Services\IssueGeneratorService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\UpstreamPlanGeneratorService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\VendorStorageService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\DiffAnalyzerService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\AssetTrackerService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\AIAnalyzerService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\VendorUpdateCheckerService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\UptelligenceDigestService::class);
        $this->app->singleton(\Core\Mod\Uptelligence\Services\WebhookReceiverService::class);
    }

    // -------------------------------------------------------------------------
    // Event-driven handlers
    // -------------------------------------------------------------------------

    public function onAdminPanel(AdminPanelBooting $event): void
    {
        $event->views($this->moduleName, __DIR__.'/View/Blade');

        if (file_exists(__DIR__.'/routes/admin.php')) {
            $event->routes(fn () => require __DIR__.'/routes/admin.php');
        }

        // Admin components
        $event->livewire('uptelligence.admin.dashboard', View\Modal\Admin\Dashboard::class);
        $event->livewire('uptelligence.admin.vendor-manager', View\Modal\Admin\VendorManager::class);
        $event->livewire('uptelligence.admin.todo-list', View\Modal\Admin\TodoList::class);
        $event->livewire('uptelligence.admin.diff-viewer', View\Modal\Admin\DiffViewer::class);
        $event->livewire('uptelligence.admin.asset-manager', View\Modal\Admin\AssetManager::class);
        $event->livewire('uptelligence.admin.digest-preferences', View\Modal\Admin\DigestPreferences::class);
        $event->livewire('uptelligence.admin.webhook-manager', View\Modal\Admin\WebhookManager::class);
    }

    /**
     * Handle API routes registration event.
     */
    public function onApiRoutes(ApiRoutesRegistering $event): void
    {
        if (file_exists(__DIR__.'/routes/api.php')) {
            $event->routes(fn () => require __DIR__.'/routes/api.php');
        }
    }

    public function onConsole(ConsoleBooting $event): void
    {
        $event->command(Console\CheckCommand::class);
        $event->command(Console\AnalyzeCommand::class);
        $event->command(Console\IssuesCommand::class);
        $event->command(Console\CheckUpdatesCommand::class);
        $event->command(Console\SendDigestsCommand::class);
        $event->command(Console\SyncForgeCommand::class);
        $event->command(Console\SyncAltumVersionsCommand::class);
    }

    /**
     * Configure rate limiting for AI API calls.
     */
    protected function configureRateLimiting(): void
    {
        // Rate limit for AI API calls: 10 per minute
        // Prevents excessive API costs and respects provider rate limits
        RateLimiter::for('upstream-ai-api', function () {
            return Limit::perMinute(config('upstream.ai.rate_limit', 10));
        });

        // Rate limit for external registry checks (Packagist, NPM): 30 per minute
        // Prevents hammering public registries
        RateLimiter::for('upstream-registry', function () {
            return Limit::perMinute(30);
        });

        // Rate limit for GitHub/Gitea issue creation: 10 per minute
        // Respects GitHub API rate limits
        RateLimiter::for('upstream-issues', function () {
            return Limit::perMinute(10);
        });

        // Rate limit for incoming webhooks: 60 per minute per endpoint
        // Webhooks from external vendor systems need reasonable limits
        RateLimiter::for('uptelligence-webhooks', function (Request $request) {
            // Use webhook UUID or IP for rate limiting
            $webhook = $request->route('webhook');

            return $webhook
                ? Limit::perMinute(60)->by('uptelligence-webhook:'.$webhook)
                : Limit::perMinute(30)->by('uptelligence-webhook-ip:'.$request->ip());
        });
    }

    /**
     * Validate configuration and warn about missing API keys.
     */
    protected function validateConfig(): void
    {
        // Only validate in non-testing environments
        if ($this->app->environment('testing')) {
            return;
        }

        $warnings = [];

        // Check AI provider configuration
        $aiProvider = config('upstream.ai.provider', 'anthropic');
        if ($aiProvider === 'anthropic' && empty(config('services.anthropic.api_key'))) {
            $warnings[] = 'Anthropic API key not configured - AI analysis will be disabled';
        } elseif ($aiProvider === 'openai' && empty(config('services.openai.api_key'))) {
            $warnings[] = 'OpenAI API key not configured - AI analysis will be disabled';
        }

        // Check GitHub configuration
        if (config('upstream.github.enabled', true) && empty(config('upstream.github.token'))) {
            $warnings[] = 'GitHub token not configured - issue creation will be disabled';
        }

        // Check Gitea configuration
        if (config('upstream.gitea.enabled', true) && empty(config('upstream.gitea.token'))) {
            $warnings[] = 'Gitea token not configured - Gitea issue creation will be disabled';
        }

        // Log warnings
        foreach ($warnings as $warning) {
            Log::warning("Uptelligence: {$warning}");
        }
    }
}
