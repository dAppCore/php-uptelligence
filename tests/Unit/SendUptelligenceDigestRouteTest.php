<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression coverage: Notifications/SendUptelligenceDigest.php called
 * route('hub.admin.uptelligence.dashboard') for its "View Dashboard" mail
 * action, but routes/admin.php registers the dashboard route bare, as
 * 'hub.admin.uptelligence' — every digest email threw
 * RouteNotFoundException building that link.
 */
class SendUptelligenceDigestRouteTest extends TestCase
{
    public function test_the_dashboard_route_the_digest_notification_links_to_is_registered(): void
    {
        require __DIR__.'/../../routes/admin.php';

        $names = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->all();

        $this->assertContains('hub.admin.uptelligence', $names);
        $this->assertNotContains('hub.admin.uptelligence.dashboard', $names);

        // Must not throw RouteNotFoundException.
        $url = route('hub.admin.uptelligence');

        $this->assertStringContainsString('/hub/admin/uptelligence', $url);
    }
}
