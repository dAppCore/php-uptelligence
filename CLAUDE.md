# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

`lthn/php-uptelligence` - A Laravel package for upstream vendor tracking and dependency intelligence. Tracks software vendors (licensed, OSS, plugins), analyses version diffs with AI, generates upgrade todos, and dispatches digest notifications.

**Namespace:** `Core\Mod\Uptelligence\`
**Dependency:** `lthn/php` (Core PHP Framework) via local path repository at `../php`

## Commands

```bash
composer run lint             # Format with Pint
composer run test             # Run all tests with Pest
./vendor/bin/pest --filter="test name"  # Run single test
./vendor/bin/pint --dirty     # Format only changed files
```

**Artisan commands** (when installed in a host application):
```bash
php artisan upstream:check              # Display vendor status table
php artisan upstream:analyze            # Run AI diff analysis between versions
php artisan upstream:issues             # Create GitHub/Gitea issues from todos
php artisan upstream:check-updates      # Poll registries (GitHub, Gitea, AltumCode)
php artisan upstream:send-digests       # Send scheduled digest emails
php artisan upstream:sync-forge         # Sync with internal Gitea instance
php artisan upstream:sync-altum-versions # Read deployed Altum versions from disk
```

## Architecture

This is a **standalone Laravel package** (not an application). It registers as a service provider via `Boot.php` and integrates into the Core PHP Framework's event-driven module system.

### Data Flow

1. **Detection** — `VendorUpdateCheckerService` polls registries (GitHub, Gitea, AltumCode) or `WebhookReceiverService` receives push notifications
2. **Storage** — `VendorStorageService` manages vendor files locally, with S3 cold archival for older versions
3. **Analysis** — `DiffAnalyzerService` generates file-level diffs, then `AIAnalyzerService` sends grouped diffs to Claude/OpenAI for categorisation
4. **Task Generation** — AI analysis produces `UpstreamTodo` records with type, priority, effort, and conflict detection
5. **Action** — `IssueGeneratorService` creates GitHub/Gitea issues; `UptelligenceDigestService` sends email summaries

### Key Components

| Layer | Location | Purpose |
|-------|----------|---------|
| Boot | `Boot.php` | Service provider, event listeners, rate limiters, config validation |
| Models | `Models/` | Eloquent: Vendor, VersionRelease, UpstreamTodo, DiffCache, Asset, etc. |
| Services | `Services/` | Business logic (9 singleton services) |
| Commands | `Console/` | 7 Artisan commands |
| Jobs | `Jobs/` | Async: CheckVendorUpdatesJob, ProcessUptelligenceWebhook |
| Admin UI | `View/Modal/Admin/` | 7 Livewire modals (Dashboard, VendorManager, TodoList, etc.) |
| API | `Controllers/Api/` | Webhook receiver endpoint |
| Config | `config.php` | Storage, AI provider, registry, rate limit settings |

### Event-Driven Module Registration

The package uses lazy loading — components only boot when the host fires the relevant event:

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

### Service Layer

All services are registered as singletons in `Boot::register()`:

- `VendorStorageService` — Local + S3 cold storage with tar.gz archival and SHA256 integrity
- `VendorUpdateCheckerService` — Registry polling (GitHub releases/tags, Gitea, AltumCode info.php)
- `DiffAnalyzerService` — Recursive directory diff with transactional DiffCache storage
- `AIAnalyzerService` — Anthropic Claude / OpenAI with rate limiting, retry, and API key redaction
- `IssueGeneratorService` — GitHub/Gitea issue creation from todos
- `UpstreamPlanGeneratorService` — Porting roadmap generation with dependency ordering
- `AssetTrackerService` — Composer/npm package monitoring and licence tracking
- `UptelligenceDigestService` — Filtered email digests (daily/weekly/monthly)
- `WebhookReceiverService` — Provider-specific signature verification (GitHub, GitLab, npm, Packagist)

### Key Model Relationships

`Vendor` is the central entity. Each vendor has many `VersionRelease` records. Comparing two releases produces `DiffCache` entries (file-level changes). AI analysis of diffs generates `UpstreamTodo` items. All operations are logged in `AnalysisLog`.

Vendors support **path mapping** (`mapToHostHub()`) to translate upstream file paths to target repository paths, and **path filtering** (`shouldIgnorePath()`, `isPriorityPath()`) via fnmatch patterns.

### Webhook Architecture

Webhooks use a **circuit breaker** pattern (auto-disable after 10 consecutive failures) and support **secret rotation** with a 24-hour grace period for dual-validation during transitions.

### Rate Limiters

Defined in `Boot.php`: `uptelligence-ai` (10/min), `uptelligence-registry` (30/min), `uptelligence-issues` (10/min), `uptelligence-webhooks` (60/min per endpoint).

## Conventions

- **UK English** — colour, organisation, analyse, behaviour, licence (noun) / license (verb)
- **Strict types** — `declare(strict_types=1);` in every PHP file
- **Full type hints** — Parameters and return types required
- **PSR-12** — Laravel Pint for formatting
- **Pest** — Not PHPUnit directly
- **Livewire 3 + Flux Pro** — Admin UI components (not vanilla Alpine, not Heroicons)
- **Font Awesome Pro** — For icons

### Naming

| Type | Convention | Example |
|------|------------|---------|
| Model | Singular PascalCase | `Vendor`, `VersionRelease` |
| Table | `uptelligence_` prefix, plural snake_case | `uptelligence_vendors` |
| Livewire Modal | `{Feature}` in `View/Modal/Admin/` | `VendorManager`, `TodoList` |

## Testing

Tests live in `tests/`. The package uses Orchestra Testbench (`TestCase` extends `Orchestra\Testbench\TestCase` with `Boot::class` as provider). SQLite in-memory database by default.

```bash
composer run test                           # All tests
./vendor/bin/pest tests/Unit/              # Unit tests only
./vendor/bin/pest tests/Feature/           # Feature tests only
./vendor/bin/pest --filter="VendorTest"    # Specific test class
```

## Configuration

All config is in `config.php` — key env vars:

| Variable | Purpose |
|----------|---------|
| `UPSTREAM_STORAGE_DISK` | `local` or `s3` |
| `ANTHROPIC_API_KEY` | AI analysis via Claude |
| `OPENAI_API_KEY` | AI analysis via OpenAI (alternative) |
| `GITHUB_TOKEN` | GitHub releases + issue creation |
| `GITEA_TOKEN` | Gitea (forge.lthn.ai) integration |

## License

EUPL-1.2 (copyleft for the `Core\` namespace)
