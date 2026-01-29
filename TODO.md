# TODO - core-uptelligence

Upstream vendor tracking and dependency intelligence for Host UK.

**Previous review:** See `changelog/2026/jan/code-review.md` for historical context. Many issues were fixed in the 2026-01-21 wave.

## P1 - Critical / Security

### ~~Migration Mismatch - Uptime Monitoring vs Vendor Tracking~~ FIXED (P2-058)

**FIXED:** 2026-01-29

The package now clarifies that it serves dual purposes:
1. **Uptime monitoring** (existing migration 000001) - for server health tracking
2. **Vendor tracking** (new migration 000004) - for upstream dependency intelligence

**Changes made:**
- [x] Created new migration `0001_01_01_000004_create_uptelligence_vendor_tables.php` with all vendor tracking tables
- [x] Added explicit `$table` property to all models with `uptelligence_` prefix:
  - `Vendor` -> `uptelligence_vendors`
  - `VersionRelease` -> `uptelligence_version_releases`
  - `UpstreamTodo` -> `uptelligence_upstream_todos`
  - `DiffCache` -> `uptelligence_diff_cache`
  - `AnalysisLog` -> `uptelligence_analysis_logs`
  - `Asset` -> `uptelligence_assets`
  - `AssetVersion` -> `uptelligence_asset_versions`
- [x] Added appropriate indexes for common query patterns
- [x] Documented dual-purpose nature in migration comments

### ~~Webhook Signature Timing Attack Vulnerability~~ FIXED (P2-059)

**FIXED:** 2026-01-29

**Audit result:** All signature verification methods already use `hash_equals()` for timing-safe comparison. The implementation is correct.

**Changes made:**
- [x] Audited all signature verification paths - all use `hash_equals()`
- [x] Added comprehensive unit tests in `tests/Unit/WebhookSignatureVerificationTest.php`:
  - Tests for all providers (GitHub, GitLab, npm, Packagist, custom)
  - Tests for grace period/secret rotation
  - Tests for malformed signatures
  - Tests for binary payloads
  - Tests for edge cases (empty payloads, large payloads)

### ~~API Key Exposure in Logs~~ FIXED (P2-060)

**FIXED:** 2026-01-29

**Changes made:**
- [x] Added `redactSensitiveData()` method to `AIAnalyzerService`
- [x] Added `redactSensitiveData()` method to `IssueGeneratorService`
- [x] Added `redactSensitiveData()` method to `VendorUpdateCheckerService`
- [x] All Log::error calls now pass through redaction before logging
- [x] Redaction patterns cover:
  - Anthropic API keys (sk-ant-...)
  - OpenAI API keys (sk-...)
  - GitHub tokens (ghp_..., gho_..., github_pat_...)
  - Bearer tokens
  - Authorization headers
  - Generic API keys and secrets

### ~~Missing Input Validation on Webhook Payloads~~ FIXED (P2-061)

**FIXED:** 2026-01-29

**Changes made:**
- [x] Added `MAX_PAYLOAD_SIZE` constant (1 MB limit)
- [x] Added `MAX_JSON_DEPTH` constant (32 levels)
- [x] Added `validatePayloadSize()` method - rejects payloads > 1MB
- [x] Added `parseAndValidateJson()` method - validates JSON with depth limit
- [x] Added `validatePayloadStructure()` method - provider-specific validation
- [x] Added `hasExcessiveArraySize()` method - prevents DoS via large arrays
- [x] Added comprehensive unit tests in `tests/Unit/WebhookPayloadValidationTest.php`

---

## P2 - High Priority

### ~~Missing Table Name on Vendor Model~~ FIXED
**FIXED:** 2026-01-29 (as part of P2-058 migration fix)

- [x] Added explicit `protected $table = 'uptelligence_vendors';` to Vendor model
- [x] All models now have explicit table names with `uptelligence_` prefix

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

### ~~DiffCache Table Name Hardcoded~~ FIXED
**FIXED:** 2026-01-29 (as part of P2-058 migration fix)

- [x] Renamed table to `uptelligence_diff_cache` for consistency
- [x] Updated model `$table` property
- [x] Created migration with correct table name

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

### 2026-01-29 P2 Security & Infrastructure Fixes

- [x] **P2-058: Migration Mismatch** - Created vendor tracking migration (`0001_01_01_000004_create_uptelligence_vendor_tables.php`), added explicit `$table` properties to all models with `uptelligence_` prefix, clarified dual-purpose nature (uptime + vendor tracking)
- [x] **P2-059: Webhook Signature Timing Attack Audit** - Verified all signature verification uses `hash_equals()`, added comprehensive tests in `tests/Unit/WebhookSignatureVerificationTest.php`
- [x] **P2-060: API Key Exposure in Logs** - Added `redactSensitiveData()` method to AIAnalyzerService, IssueGeneratorService, and VendorUpdateCheckerService to redact API keys, tokens, and credentials from log output
- [x] **P2-061: Missing Webhook Payload Validation** - Added payload size limit (1MB), JSON depth limit (32), provider-specific schema validation, array size limits, tests in `tests/Unit/WebhookPayloadValidationTest.php`
- [x] **P3-DiffCache Table Name** - Fixed table name from `diff_cache` to `uptelligence_diff_cache` for consistency

### 2026-01-21 Code Review Wave

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
