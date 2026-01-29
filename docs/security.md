---
title: Security
description: Security considerations and audit notes for core-uptelligence
updated: 2026-01-29
---

# Security Considerations

This document outlines security considerations, implemented protections, and areas requiring attention for the `core-uptelligence` package.

## Authentication and Authorisation

### Webhook Authentication

Webhooks are authenticated via HMAC signature verification, not API tokens or session auth.

**Implementation:** `Models/UptelligenceWebhook.php`

| Provider | Method | Algorithm |
|----------|--------|-----------|
| GitHub | `X-Hub-Signature-256` header | HMAC-SHA256 |
| GitLab | `X-Gitlab-Token` header | Direct comparison |
| npm | `X-Npm-Signature` header | HMAC-SHA256 |
| Packagist | `X-Hub-Signature` header | HMAC-SHA1 |
| Custom | `X-Signature` header | HMAC-SHA256 |

**Strengths:**
- Uses `hash_equals()` for timing-safe comparison
- Supports secret rotation with grace period
- Previous secret accepted during 24-hour grace period

**Weaknesses:**
- GitLab uses simple token comparison (not HMAC)
- No IP allowlist for webhook sources
- No request signing for outbound API calls

### Admin Access

Admin routes are protected by middleware defined at the application level. The package itself does not enforce authorisation.

**Risk:** If admin middleware is misconfigured, admin endpoints could be exposed.

**Recommendation:** Add explicit authorisation checks in Livewire components.

### API Token Storage

External API tokens (GitHub, Gitea, AI providers) are stored in environment variables, not in the database.

**Configuration:** `config.php`
```php
'github' => [
    'token' => env('GITHUB_TOKEN'),
],
'gitea' => [
    'token' => env('GITEA_TOKEN'),
],
```

**Strength:** Tokens not exposed in database backups.

## Input Validation

### Webhook Payloads

**Current state:**
- JSON parsing with error handling
- Signature verification before processing
- Event type extraction from headers/payload

**Missing:**
- Maximum payload size validation
- Schema validation for expected structure
- Protection against deeply nested JSON
- Rate limiting per source IP (partially implemented)

### File Path Validation

**Implementation:** `Services/DiffAnalyzerService.php:232-274`

Path traversal protection is implemented:
```php
protected function validatePath(string $path, string $basePath): string
{
    // Check for path traversal attempts
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        throw new InvalidArgumentException('Invalid path: path traversal not allowed');
    }
    // ... realpath validation
}
```

**Strengths:**
- Blocks `..` sequences
- Blocks null bytes
- Validates against real filesystem path

### Shell Command Execution

**Risk areas:**

1. `DiffAnalyzerService::generateDiff()` - Uses array syntax (safe)
   ```php
   Process::run(['diff', '-u', $prevPath, $currPath]);
   ```

2. `VendorStorageService::createArchive()` - Uses array syntax (safe)
   ```php
   new Process(['tar', '-czf', $archivePath, '-C', $sourcePath, '.']);
   ```

3. `AssetTrackerService` - Uses array syntax with validation (FIXED)
   ```php
   // Package names are validated against allowlist patterns before use
   $packageName = $this->validatePackageName($asset->package_name, Asset::TYPE_COMPOSER);
   Process::run(['composer', 'show', $packageName, '--format=json']);
   ```

**Mitigations applied:**
- Array-based Process invocation prevents shell metacharacter interpretation
- Package name validation rejects names containing shell injection characters
- Invalid package names are logged and result in safe error responses

## Data Protection

### Sensitive Data Handling

**Encrypted fields:**
- `UptelligenceWebhook.secret` - Laravel encrypted cast
- `UptelligenceWebhook.previous_secret` - Laravel encrypted cast

**Hidden from serialisation:**
- Both secret fields are in `$hidden` array

### Logging Considerations

**Risk:** API responses and payloads may be logged on error.

**Current logging:**
```php
Log::error('Uptelligence: GitHub issue creation failed', [
    'todo_id' => $todo->id,
    'status' => $response->status(),
    'body' => substr($response->body(), 0, 500),  // Truncated but may contain sensitive data
]);
```

**Recommendation:**
- Redact or exclude response bodies
- Never log full webhook payloads
- Use structured logging with PII filtering

### File Storage Security

**S3 Configuration:**
- Uses private bucket by default
- Dual endpoint support for Hetzner Object Store
- Files stored with `application/gzip` content type

**Local Storage:**
- Files stored under `storage/app/vendors/`
- Not publicly accessible by default
- Temp files cleaned up after 24 hours

## Rate Limiting

### Implemented Limiters

| Limiter | Rate | Scope |
|---------|------|-------|
| `upstream-ai-api` | 10/minute | Global |
| `upstream-registry` | 30/minute | Global |
| `upstream-issues` | 10/minute | Global |
| `uptelligence-webhooks` | 60/minute | Per webhook UUID or IP |

### Webhook Rate Limiting

```php
RateLimiter::for('uptelligence-webhooks', function (Request $request) {
    $webhook = $request->route('webhook');
    return $webhook
        ? Limit::perMinute(60)->by('uptelligence-webhook:'.$webhook)
        : Limit::perMinute(30)->by('uptelligence-webhook-ip:'.$request->ip());
});
```

**Note:** IP-based fallback uses `$request->ip()` which may be spoofed without proper proxy configuration.

## External Service Integration

### GitHub API

**Scopes required:**
- `repo` - For reading releases and creating issues
- `read:org` - If targeting organisation repos

**Token handling:**
- Sent via `Authorization: Bearer` header
- Token not logged in error scenarios

### Gitea API

**Token handling:**
- Sent via `Authorization: token` header
- Similar security profile to GitHub

### AI Providers (Anthropic/OpenAI)

**Data sent to AI:**
- Code diffs (potentially containing sensitive patterns)
- File paths
- Vendor names

**Recommendation:**
- Consider PII scanning before AI submission
- Document data processing in privacy policy
- Offer opt-out for AI analysis

## Known Vulnerabilities

### Critical

1. **Missing database migrations** - Core models reference non-existent tables
   - Impact: Application will fail on first use
   - Status: Documented in TODO.md P1

2. ~~**Shell injection in AssetTrackerService**~~ - FIXED
   - Impact: Was arbitrary command execution if package names were malicious
   - Status: RESOLVED - Now uses array-based Process invocation with package name validation

### High

1. **No authorisation in Livewire components**
   - Impact: Relies entirely on route middleware
   - Mitigation: Admin routes should be protected at application level

2. **Missing File facade import in VendorStorageService**
   - Impact: Runtime errors in extractMetadata/getDirectorySize
   - Status: Documented in TODO.md P2

### Medium

1. **Inconsistent workspace scoping**
   - Impact: Vendor data may leak between workspaces
   - Status: Design decision needed

2. **Webhook payloads stored unencrypted**
   - Impact: Payload data visible in database
   - Mitigation: Payloads are not typically sensitive

## Security Checklist for Production

Before deploying to production:

- [ ] All API tokens set via environment variables
- [ ] S3 bucket configured as private
- [ ] Webhook secrets generated with sufficient entropy (64+ characters)
- [ ] Rate limiters tested and tuned
- [ ] Admin routes protected by authentication middleware
- [ ] Logging configured to exclude sensitive data
- [ ] Database migrations created and run
- [ ] AssetTrackerService shell injection fixed
- [ ] Trusted proxy configuration set (for accurate IP detection)

## Incident Response

### Compromised Webhook Secret

1. Rotate secret immediately via `webhook->rotateSecret()`
2. Grace period allows valid senders to update
3. Monitor for invalid signature attempts
4. After grace period, old secret is invalidated

### Compromised API Token

1. Revoke token at provider (GitHub/Gitea)
2. Generate new token
3. Update environment variable
4. Restart application
5. Review logs for unauthorised actions

### Suspected Data Breach

1. Check webhook delivery logs for unusual patterns
2. Review AI API call logs for data exfiltration
3. Audit S3 access logs
4. Check for path traversal attempts in logs

## Compliance Notes

### GDPR Considerations

- Webhook payloads may contain contributor names/emails
- AI analysis may process code comments with PII
- Digest notifications sent to user email addresses

**Recommendations:**
- Document data flows in privacy policy
- Implement data retention policies
- Provide data export/deletion capabilities

### EUPL-1.2 License

The package is licensed under EUPL-1.2 (copyleft).

- Modifications to `Core\` namespace must be shared
- Does not apply to vendor code being analysed
- External API usage subject to provider terms
