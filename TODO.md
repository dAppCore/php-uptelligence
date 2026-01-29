# TODO - core-uptelligence

Upstream vendor tracking and dependency intelligence for Host UK.

**Previous review:** See `changelog/2026/jan/code-review.md` for historical context. Many issues were fixed in the 2026-01-21 wave.

## P1 - Critical / Security

### Migration Mismatch - Uptime Monitoring vs Vendor Tracking
The first migration (`0001_01_01_000001_create_uptelligence_tables.php`) creates uptime monitoring tables (`uptelligence_monitors`, `uptelligence_checks`, `uptelligence_incidents`, `uptelligence_daily_stats`) rather than vendor tracking tables.

**Note:** The code review from 2026-01-21 mentions migrations were created (`2026_01_21_100000_create_uptelligence_tables.php`), but this file is not present in the repository. The current migration appears to be for a different purpose (uptime monitoring).

**Files affected:**
- `database/migrations/0001_01_01_000001_create_uptelligence_tables.php` - Contains uptime monitoring tables
- `Models/Vendor.php` - References `vendors` table
- `Models/VersionRelease.php` - References `version_releases` table
- `Models/UpstreamTodo.php` - References `upstream_todos` table
- `Models/DiffCache.php` - References `diff_cache` table
- `Models/AnalysisLog.php` - References `analysis_logs` table
- `Models/Asset.php` - References `assets` table
- `Models/AssetVersion.php` - References `asset_versions` table

**Acceptance criteria:**
- [ ] Clarify whether uptime monitoring is part of this package or a separate concern
- [ ] If vendor tracking is the focus, replace or supplement migration with vendor tables
- [ ] Ensure all model tables are created with appropriate columns
- [ ] Add indexes as noted in the prior code review

### Webhook Signature Timing Attack Vulnerability
The `verifyGitLabSignature` method uses direct string comparison which may be vulnerable to timing attacks.

**File:** `Models/UptelligenceWebhook.php:250-253`
```php
protected function verifyGitLabSignature(string $signature, string $secret): bool
{
    return hash_equals($secret, $signature);  // This is correct, but see below
}
```

**Note:** The method itself uses `hash_equals`, but verify all callers pass correctly. Additionally, consider constant-time comparison for all providers.

**Acceptance criteria:**
- [ ] Audit all signature verification paths for timing safety
- [ ] Add unit tests for signature verification edge cases

### API Key Exposure in Logs
The `AIAnalyzerService` and other services may log sensitive data in error scenarios.

**Files affected:**
- `Services/AIAnalyzerService.php` - Logs API responses which could contain sensitive context
- `Services/IssueGeneratorService.php` - Logs response bodies on failure

**Acceptance criteria:**
- [ ] Audit all Log::error calls for sensitive data
- [ ] Truncate or redact sensitive information in logs
- [ ] Never log full API request/response bodies containing credentials

### Missing Input Validation on Webhook Payloads
The `WebhookController` accepts JSON payloads without size limits or schema validation.

**File:** `Controllers/Api/WebhookController.php`

**Acceptance criteria:**
- [ ] Add maximum payload size validation (e.g., 1MB limit)
- [ ] Add basic schema validation for expected payload structure
- [ ] Add protection against deeply nested JSON (DoS vector)

---

## P2 - High Priority

### Missing Table Name on Vendor Model
The `Vendor` model doesn't explicitly set `$table`, relying on Laravel's convention which would create `vendors` table.

**File:** `Models/Vendor.php`

**Acceptance criteria:**
- [ ] Add explicit `protected $table = 'uptelligence_vendors';` for consistency with other models
- [ ] Update migration to match

### DiffAnalyzerService Constructor Pattern Inconsistency
`DiffAnalyzerService` requires a `Vendor` in constructor unlike other services (which use dependency injection).

**File:** `Services/DiffAnalyzerService.php:27-33`

**Acceptance criteria:**
- [ ] Refactor to accept Vendor as method parameter instead of constructor
- [ ] Update all callers
- [ ] Register as singleton like other services

### ~~Missing Process Shell Injection Protection in AssetTrackerService~~ FIXED
~~The `AssetTrackerService` uses `Process::run()` with string interpolation.~~

**FIXED:** Now uses array-based Process invocation with package name validation.

**Changes made:**
- [x] Use array syntax for Process commands to prevent shell injection
- [x] Validate package names against allowlist pattern (Composer and NPM patterns)
- [x] Added tests for shell injection prevention in `tests/Unit/AssetTrackerServiceTest.php`

### VendorStorageService Uses Undefined File Facade
The `extractMetadata` and `getDirectorySize` methods use `File::` facade which is not imported.

**File:** `Services/VendorStorageService.php:391,417,562`

**Acceptance criteria:**
- [ ] Add `use Illuminate\Support\Facades\File;` import
- [ ] Or refactor to use Storage facade consistently

### Missing Authorisation Checks
No authorisation checks on admin Livewire components - relies entirely on route middleware.

**Files affected:**
- `View/Modal/Admin/*.php` - All admin modals

**Acceptance criteria:**
- [ ] Add explicit authorisation checks in Livewire components
- [ ] Use Gates/Policies for fine-grained access control
- [ ] Consider workspace-level permissions for multi-tenant isolation

### Missing Vendor Model Workspace Scope
The `Vendor` model doesn't use `BelongsToWorkspace` trait, but `UptelligenceDigest` does.

**File:** `Models/Vendor.php`

**Acceptance criteria:**
- [ ] Determine if vendors should be workspace-scoped or global
- [ ] If workspace-scoped, add `BelongsToWorkspace` trait
- [ ] Add `workspace_id` to migration

---

## P3 - Medium Priority

### TestCase Incorrect Namespace
The test case uses wrong namespace and doesn't extend Orchestra TestCase.

**File:** `tests/TestCase.php`
```php
namespace Tests;  // Should be Core\Mod\Uptelligence\Tests
```

**Acceptance criteria:**
- [ ] Fix namespace to `Core\Mod\Uptelligence\Tests`
- [ ] Extend `Orchestra\Testbench\TestCase`
- [ ] Configure package service provider loading

### Missing Test Coverage
The `tests/Feature/` and `tests/Unit/` directories contain only `.gitkeep` files.

**Acceptance criteria:**
- [ ] Add unit tests for all Services
- [ ] Add unit tests for Model methods and scopes
- [ ] Add feature tests for webhook endpoints
- [ ] Add feature tests for console commands
- [ ] Target: 80% code coverage

### Missing Rate Limiter Key Consistency
Different services use different rate limiter key formats.

**Files affected:**
- `Boot.php` - Defines limiters
- `Services/AIAnalyzerService.php:221` - Uses key without user context
- `Services/IssueGeneratorService.php:91` - Uses same global key

**Acceptance criteria:**
- [ ] Standardise rate limiter key naming
- [ ] Consider per-vendor or per-user rate limits where appropriate

### Missing Retry Logic Consistency
Some HTTP calls have retry logic, others don't.

**File:** `Services/IssueGeneratorService.php:393-406` - `createWeeklyDigest` lacks retry logic unlike `createGitHubIssue`

**Acceptance criteria:**
- [ ] Add retry logic to all external HTTP calls
- [ ] Extract common HTTP client configuration to shared method

### DiffCache Table Name Hardcoded
Model sets table name to `diff_cache` but other tables use `uptelligence_` prefix.

**File:** `Models/DiffCache.php:22`

**Acceptance criteria:**
- [ ] Rename to `uptelligence_diff_cache` for consistency
- [ ] Update migration

### Missing Carbon Import in UptelligenceDigest
Uses `\Carbon\Carbon` with full path instead of import.

**File:** `Models/UptelligenceDigest.php:258`

**Acceptance criteria:**
- [ ] Add `use Carbon\Carbon;` import
- [ ] Replace `\Carbon\Carbon` with `Carbon`

### Emoji Usage in Models
Models use emoji characters for icons which may cause encoding issues.

**Files affected:**
- `Models/Vendor.php:208-215`
- `Models/UpstreamTodo.php:215-228`
- `Models/DiffCache.php:213-250`
- `Models/AnalysisLog.php:156-170`

**Acceptance criteria:**
- [ ] Replace emojis with icon class names (Font Awesome)
- [ ] Or create separate icon mapping service
- [ ] Ensure UTF-8 encoding throughout

---

## P4 - Low Priority

### Inconsistent Method Naming
Some methods use British spelling, others American.

**Examples:**
- `normaliseVersion` (British) in `WebhookReceiverService.php`
- `normaliseVersion` (British) in `VendorUpdateCheckerService.php`
- Consistent, but verify across codebase

**Acceptance criteria:**
- [ ] Audit all method names for spelling consistency (prefer British)
- [ ] Document spelling conventions in CLAUDE.md

### Missing PHPDoc Return Types
Some methods lack `@return` documentation.

**Files affected:** Most service methods

**Acceptance criteria:**
- [ ] Add comprehensive PHPDoc blocks to all public methods
- [ ] Include `@param`, `@return`, `@throws` annotations

### Console Commands Missing Progress Bars
Commands don't show progress for long-running operations.

**Files affected:**
- `Console/CheckCommand.php`
- `Console/AnalyzeCommand.php`
- `Console/IssuesCommand.php`

**Acceptance criteria:**
- [ ] Add progress bars for iterating over vendors/assets
- [ ] Add `--verbose` option for detailed output

### Missing Soft Deletes on Some Models
`DiffCache` and `AnalysisLog` lack soft deletes while related models have them.

**Acceptance criteria:**
- [ ] Add `SoftDeletes` trait to `DiffCache` and `AnalysisLog`
- [ ] Add `deleted_at` column to migrations

### Missing Model Events
No model observers or events for audit logging.

**Acceptance criteria:**
- [ ] Add observer for `UpstreamTodo` status changes
- [ ] Add events for version detection, analysis completion
- [ ] Integrate with activity logging system

---

## P5 - Nice to Have

### Add MCP Tool Integration
The package is designed for AI analysis but has no MCP tool handlers.

**Acceptance criteria:**
- [ ] Add `McpToolsRegistering` event listener to Boot.php
- [ ] Create MCP tools for:
  - Listing pending todos
  - Checking vendor status
  - Triggering analysis
  - Getting quick wins summary

### Add WebSocket/Broadcast Support
No real-time updates for webhook deliveries or analysis progress.

**Acceptance criteria:**
- [ ] Add Laravel Echo events for webhook received
- [ ] Broadcast analysis progress updates
- [ ] Add Livewire polling as fallback

### Add Slack/Discord Notifications
Config has webhook URLs but no implementation.

**File:** `config.php:223-225`

**Acceptance criteria:**
- [ ] Implement Slack notification channel
- [ ] Implement Discord notification channel
- [ ] Add notification preferences per user

### Support GitLab Self-Hosted
`isGiteaUrl` method only checks configured Gitea host.

**File:** `Services/VendorUpdateCheckerService.php:409-418`

**Acceptance criteria:**
- [ ] Add support for GitLab self-hosted instances
- [ ] Make git host detection more flexible

### Add Changelog Parsing
Extract structured changelog data from releases.

**Acceptance criteria:**
- [ ] Parse Keep a Changelog format
- [ ] Extract breaking changes automatically
- [ ] Link changelog entries to todos

---

## P6+ - Future / Backlog

### Database Performance Optimisation
- [ ] Add database indexes for common query patterns
- [ ] Implement query caching for dashboard stats
- [ ] Consider read replicas for analytics queries

### Multi-Language Support
- [ ] Extract all user-facing strings to lang files
- [ ] Add translation support for notifications

### API Endpoints for External Integration
- [ ] Create REST API for querying todos
- [ ] Add GraphQL support
- [ ] Implement API versioning

### Archive Management UI
- [ ] Add S3 storage browser in admin
- [ ] Implement bulk archive/restore operations
- [ ] Add storage quota monitoring

### Advanced AI Analysis
- [ ] Implement multi-file context analysis
- [ ] Add code complexity metrics
- [ ] Generate migration scripts automatically

### Vendor Dependency Graph
- [ ] Track inter-vendor dependencies
- [ ] Visualise dependency tree
- [ ] Detect cascading update requirements

---

## Completed

Items completed as part of the 2026-01-21 code review wave (per `changelog/2026/jan/code-review.md`):

- [x] **Path traversal validation** - Added to `DiffAnalyzerService::validatePath()` to prevent directory traversal attacks
- [x] **Shell injection fix in DiffAnalyzerService** - Now uses array syntax: `Process::run(['diff', '-u', $prevPath, $currPath])`
- [x] **Shell injection fix in AssetTrackerService** - Now uses array syntax with package name validation for all Process::run() calls
- [x] **Rate limiting for AI API calls** - Implemented in `AIAnalyzerService` with configurable limit
- [x] **Retry logic with exponential backoff** - Added to Packagist, NPM, GitHub, Gitea, Anthropic, and OpenAI API calls
- [x] **Enhanced error logging** - Improved logging for API failures beyond just `report($e)`
- [x] **Database transactions** - Added to `DiffAnalyzerService::cacheDiffs()` with rollback on failure
- [x] **Soft deletes** - Added to relevant models (Vendor, VersionRelease, UpstreamTodo, UptelligenceWebhook)
- [x] **Database indexes** - Added indexes on `diff_cache.version_release_id` and `upstream_todos.vendor_id`
- [x] **Target repo validation** - Added validation in `IssueGeneratorService` before using `explode('/', ...)`
- [x] **Storage facade standardisation** - `VendorStorageService` now uses Storage facade consistently
- [x] **Config validation on boot** - Added warnings for missing API keys in `Boot::validateConfig()`
- [x] **Timestamp validation** - Fixed `released_at` parsing in `AssetTrackerService::parseReleaseTimestamp()`
- [x] **Agentic module soft dependency** - `UpstreamPlanGeneratorService` now checks module availability
- [x] **Webhook system** - Implemented inbound webhook endpoints, signature verification, and async processing
