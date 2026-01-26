# Core PHP Framework Project

## Architecture

Modular monolith using Core PHP Framework. Modules live in `app/Mod/{Name}/Boot.php`.

**Event-driven registration:**
```php
class Boot
{
    public static array $listens = [
        WebRoutesRegistering::class => 'onWebRoutes',
        ApiRoutesRegistering::class => 'onApiRoutes',
        AdminPanelBooting::class => 'onAdminPanel',
    ];
}
```

## Commands

```bash
composer run dev              # Dev server (if configured)
php artisan serve             # Laravel dev server
npm run dev                   # Vite
./vendor/bin/pint --dirty     # Format changed files
php artisan test              # All tests
php artisan make:mod Blog     # Create module
```

## Module Structure

```
app/Mod/Blog/
├── Boot.php              # Event listeners
├── Models/               # Eloquent models
├── Routes/
│   ├── web.php          # Web routes
│   └── api.php          # API routes
├── Views/               # Blade templates
├── Livewire/            # Livewire components
├── Migrations/          # Database migrations
└── Tests/               # Module tests
```

## Packages

| Package | Purpose |
|---------|---------|
| `host-uk/core` | Core framework, events, module discovery |
| `host-uk/core-admin` | Admin panel, Livewire modals |
| `host-uk/core-api` | REST API, scopes, rate limiting, webhooks |
| `host-uk/core-mcp` | Model Context Protocol for AI agents |

## Conventions

- UK English (colour, organisation, centre)
- PSR-12 coding style (Laravel Pint)
- Pest for testing
- Livewire + Flux Pro for UI

## License

- `Core\` namespace and vendor packages: EUPL-1.2 (copyleft)
- `app/Mod/*`, `app/Website/*`: Your choice (no copyleft)

See LICENSE for full details.
