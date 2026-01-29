---
title: Webhooks
description: Webhook configuration and integration guide
updated: 2026-01-29
---

# Webhooks

The Uptelligence module can receive webhooks from vendor release systems to automatically track new versions.

## Supported Providers

| Provider | Event Types | Signature Method |
|----------|-------------|------------------|
| GitHub | Release published/created | HMAC-SHA256 |
| GitLab | Release created, tag push | Token header |
| npm | Package published | HMAC-SHA256 |
| Packagist | Package updated | HMAC-SHA1 |
| Custom | Flexible | HMAC-SHA256 |

## Endpoint URL

Each webhook has a unique endpoint:

```
POST /api/uptelligence/webhook/{uuid}
```

The UUID is generated when the webhook is created and serves as the identifier.

## Configuring Webhooks

### GitHub

1. Go to repository Settings > Webhooks > Add webhook
2. Set Payload URL to: `https://your-domain.com/api/uptelligence/webhook/{uuid}`
3. Set Content type to: `application/json`
4. Set Secret to the webhook's secret (visible in admin panel)
5. Select "Let me select individual events"
6. Check "Releases" only
7. Save webhook

**Expected headers:**
- `X-Hub-Signature-256: sha256={signature}`
- `X-GitHub-Event: release`

### GitLab

1. Go to project Settings > Webhooks
2. Set URL to: `https://your-domain.com/api/uptelligence/webhook/{uuid}`
3. Set Secret token to the webhook's secret
4. Check "Releases events" and optionally "Tag push events"
5. Save webhook

**Expected headers:**
- `X-Gitlab-Token: {secret}`
- `X-Gitlab-Event: Release Hook` or `Tag Push Hook`

### npm

npm webhooks are configured per package via the npm CLI or website.

```bash
npm hook add @scope/package https://your-domain.com/api/uptelligence/webhook/{uuid} {secret}
```

**Expected headers:**
- `X-Npm-Signature: {signature}`

### Packagist

Packagist webhooks are configured in the package settings on packagist.org.

1. Go to package page > Edit
2. Add webhook URL: `https://your-domain.com/api/uptelligence/webhook/{uuid}`
3. Set secret if required

**Expected headers:**
- `X-Hub-Signature: sha1={signature}`

### Custom

For custom integrations, send a POST request with:

**Headers:**
- `Content-Type: application/json`
- `X-Signature: sha256={hmac_sha256_of_body}` (optional)

**Body:**
```json
{
  "version": "1.2.3",
  "tag_name": "v1.2.3",
  "name": "Release Name",
  "body": "Release notes...",
  "prerelease": false,
  "published_at": "2026-01-29T12:00:00Z"
}
```

## Signature Verification

### HMAC-SHA256 (GitHub, npm, Custom)

```php
$expectedSignature = hash_hmac('sha256', $payload, $secret);
$valid = hash_equals($expectedSignature, $providedSignature);
```

The signature header may have a `sha256=` prefix which is stripped before comparison.

### Token Comparison (GitLab)

```php
$valid = hash_equals($secret, $providedToken);
```

Direct constant-time comparison of the token.

### HMAC-SHA1 (Packagist)

```php
$expectedSignature = hash_hmac('sha1', $payload, $secret);
$valid = hash_equals($expectedSignature, $providedSignature);
```

## Secret Management

### Creating a Webhook

When a webhook is created, a 64-character random secret is automatically generated.

### Rotating Secrets

Secrets can be rotated with a grace period:

```php
$webhook->rotateSecret();
```

This:
1. Moves current secret to `previous_secret`
2. Generates new 64-character secret
3. Sets `secret_rotated_at` to current time

During the grace period (default 24 hours), both old and new secrets are accepted.

### Regenerating Without Grace

To immediately invalidate the old secret:

```php
$webhook->regenerateSecret();
```

This:
1. Generates new secret
2. Clears `previous_secret`
3. Clears `secret_rotated_at`

## Delivery Processing

### Flow

1. **Receive** - Webhook received at endpoint
2. **Validate** - Check webhook is active, verify signature
3. **Log** - Create `UptelligenceWebhookDelivery` record
4. **Queue** - Dispatch `ProcessUptelligenceWebhook` job
5. **Parse** - Extract version and metadata from payload
6. **Process** - Create/update version release record
7. **Notify** - Send notifications to subscribed users

### Delivery Statuses

| Status | Description |
|--------|-------------|
| `pending` | Queued for processing |
| `processing` | Currently being processed |
| `completed` | Successfully processed |
| `failed` | Processing failed |
| `skipped` | Not a release event or unable to parse |

### Retry Logic

Failed deliveries are retried with exponential backoff:

- Attempt 1: Immediate
- Attempt 2: After 30 seconds
- Attempt 3: After 60 seconds (2^2 * 30)
- Attempt 4: After 120 seconds (2^3 * 30)

Maximum 3 retries by default.

## Circuit Breaker

To prevent continuous processing of failing webhooks:

- After 10 consecutive failures, the webhook is automatically disabled
- Status changes to "Circuit Open"
- Manual intervention required to re-enable

Reset the circuit breaker:

```php
$webhook->update(['is_active' => true, 'failure_count' => 0]);
```

## Monitoring

### Admin Dashboard

The Webhook Manager component shows:
- Webhook status (Active, Disabled, Circuit Open)
- Last received timestamp
- Failure count
- Recent deliveries with status

### Deliveries Log

Each delivery records:
- Event type
- Provider
- Version extracted
- Payload (full JSON)
- Parsed data (normalised)
- Source IP
- Signature status
- Processing time
- Error message (if failed)

## Rate Limiting

Webhooks are rate-limited to prevent abuse:

- **60 requests/minute per webhook UUID**
- **30 requests/minute per IP** (fallback for unknown webhooks)

Rate limit exceeded returns `429 Too Many Requests`.

## Testing Webhooks

### Test Endpoint

```
POST /api/uptelligence/webhook/{uuid}/test
```

Returns webhook configuration status without processing:

```json
{
  "status": "ok",
  "webhook_id": "uuid-here",
  "vendor_id": 1,
  "provider": "github",
  "is_active": true,
  "signature_status": "valid",
  "has_secret": true
}
```

### Manual Testing

Use curl to test a webhook:

```bash
# Generate signature
SECRET="your-webhook-secret"
PAYLOAD='{"tag_name":"v1.0.0","name":"Test Release"}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

# Send request
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Hub-Signature-256: sha256=$SIGNATURE" \
  -d "$PAYLOAD" \
  https://your-domain.com/api/uptelligence/webhook/{uuid}
```

## Troubleshooting

### "Invalid signature" Response

1. Verify the secret matches between provider and webhook config
2. Check that the payload is sent as raw JSON (not form-encoded)
3. Ensure the signature algorithm matches the provider
4. Check for encoding issues (UTF-8 BOM, etc.)

### "Webhook disabled" Response

1. Check if circuit breaker has tripped (failure_count >= 10)
2. Verify `is_active` is true in database
3. Re-enable via admin panel or API

### Deliveries Not Processing

1. Check queue worker is running: `php artisan queue:work --queue=uptelligence-webhooks`
2. Check for failed jobs: `php artisan queue:failed`
3. Review delivery status in admin panel

### Missing Release Record

1. Verify the event type is supported (release/publish, not push)
2. Check parsed_data in delivery record
3. Ensure version extraction succeeded
4. Check if version already exists (duplicate webhooks are de-duplicated)
