# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

`host-uk/core-uptelligence` - A Laravel module for upstream vendor tracking and dependency intelligence. Tracks software vendors (licensed, OSS, plugins), analyses version diffs with AI, generates upgrade todos, and dispatches digest notifications.

**Namespace:** `Core\Mod\Uptelligence\`

## Commands

```bash
composer run lint             # Format with Pint
composer run test             # Run all tests with Pest
./vendor/bin/pest --filter="test name"  # Run single test
./vendor/bin/pint --dirty     # Format only changed files
```

**Artisan commands** (when installed in a host application):
```bash
php artisan upstream:check           # Check vendors for updates
php artisan upstream:analyze         # Analyse version diffs
php artisan upstream:issues          # Generate issues from todos
php artisan upstream:check-updates   # Check external registries
php artisan upstream:send-digests    # Send digest emails
```

## Architecture

This is a **standalone Laravel package** (not an application). It registers as a service provider via `Boot.php`.

### Key Components

| Layer | Location | Purpose |
|-------|----------|---------|
| Boot | `Boot.php` | Service provider, event listeners, rate limiters |
| Models | `Models/` | Eloquent: Vendor, VersionRelease, UpstreamTodo, Asset, etc. |
| Services | `Services/` | Business logic: AI analysis, diff generation, webhooks |
| Commands | `Console/` | Artisan commands for CLI operations |
| Admin UI | `View/Modal/Admin/` | Livewire modals for admin panel |
| API | `Controllers/Api/` | Webhook receiver endpoints |

### Service Layer

Services are registered as singletons in `Boot.php`:

- `VendorStorageService` - File storage (local/S3)
- `VendorUpdateCheckerService` - Registry polling (Packagist, npm)
- `DiffAnalyzerService` - Version diff generation
- `AIAnalyzerService` - Anthropic/OpenAI code analysis
- `IssueGeneratorService` - GitHub/Gitea issue creation
- `UptelligenceDigestService` - Email digests
- `WebhookReceiverService` - Inbound webhook processing

### Event Registration

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

## Conventions

- **UK English** - colour, organisation, analyse (not American spellings)
- **Strict types** - `declare(strict_types=1);` in every PHP file
- **Full type hints** - Parameters and return types required
- **PSR-12** - Laravel Pint for formatting
- **Pest** - Not PHPUnit directly
- **Livewire + Flux Pro** - Admin UI components

## Testing

Tests live in `tests/`. The package uses Orchestra Testbench for Laravel testing in isolation.

```bash
composer run test                           # All tests
./vendor/bin/pest tests/Unit/              # Unit tests only
./vendor/bin/pest tests/Feature/           # Feature tests only
./vendor/bin/pest --filter="VendorTest"    # Specific test class
```

## License

EUPL-1.2 (copyleft for the `Core\` namespace)