# AI Agent Instructions

> For Jules, Devin, and other autonomous coding agents.

## Quick Start

1. This is a Laravel 12 + Livewire 3 application
2. Modules go in `app/Mod/{Name}/Boot.php`
3. Use UK English (colour, not color)
4. Run `vendor/bin/pint --dirty` before committing
5. Run `vendor/bin/pest` to test

## Architecture

**Modular monolith** - Features are self-contained modules that register via events.

### Creating a Module

```bash
php artisan make:mod {Name} --all
```

Or manually create `app/Mod/{Name}/Boot.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mod\{Name};

use Core\Events\WebRoutesRegistering;

class Boot
{
    public static array $listens = [
        WebRoutesRegistering::class => 'onWebRoutes',
    ];

    public function onWebRoutes(WebRoutesRegistering $event): void
    {
        $event->routes(fn() => require __DIR__.'/Routes/web.php');
        $event->views('{name}', __DIR__.'/Views');
    }
}
```

## Task Checklist

When implementing features:

- [ ] Create module in `app/Mod/{Name}/`
- [ ] Add `Boot.php` with event listeners
- [ ] Create routes in `Routes/web.php` or `Routes/api.php`
- [ ] Create Livewire components in `Livewire/`
- [ ] Create Blade views in `Views/`
- [ ] Add migrations in `Migrations/`
- [ ] Write tests in `Tests/`
- [ ] Run `vendor/bin/pint --dirty`
- [ ] Run `vendor/bin/pest`

## File Locations

| What | Where |
|------|-------|
| Models | `app/Mod/{Name}/Models/` |
| Livewire | `app/Mod/{Name}/Livewire/` |
| Views | `app/Mod/{Name}/Views/` |
| Routes | `app/Mod/{Name}/Routes/` |
| Migrations | `app/Mod/{Name}/Migrations/` |
| Tests | `app/Mod/{Name}/Tests/` |
| Services | `app/Mod/{Name}/Services/` |

## Critical Rules

1. **UK English** - colour, organisation, centre (never American spellings)
2. **Strict types** - `declare(strict_types=1);` in every PHP file
3. **Type hints** - All parameters and return types
4. **Flux Pro** - Use Flux components, not vanilla Alpine
5. **Font Awesome** - Use FA icons, not Heroicons
6. **Pest** - Write tests using Pest syntax, not PHPUnit

## Example Livewire Component

```php
<?php

declare(strict_types=1);

namespace App\Mod\Blog\Livewire;

use App\Mod\Blog\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class PostListPage extends Component
{
    use WithPagination;

    public function render(): View
    {
        return view('blog::posts.index', [
            'posts' => Post::latest()->paginate(10),
        ]);
    }
}
```

## Testing Example

```php
<?php

use App\Mod\Blog\Models\Post;

it('displays posts on the index page', function () {
    $posts = Post::factory()->count(3)->create();

    $this->get('/blog')
        ->assertOk()
        ->assertSee($posts->first()->title);
});
```
