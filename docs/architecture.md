---
title: Architecture
description: Technical architecture of core-uptelligence
updated: 2026-01-29
---

# Architecture

The `core-uptelligence` package provides upstream vendor tracking and dependency intelligence for the Host UK platform. It monitors software vendors, analyses version differences, generates porting tasks, and manages notification digests.

## Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           External Systems                                   │
├─────────────┬─────────────┬─────────────┬─────────────┬────────────────────┤
│   GitHub    │   GitLab    │     npm     │  Packagist  │   AI Providers     │
│  Releases   │  Releases   │   Publish   │   Updates   │ (Anthropic/OpenAI) │
└──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴─────────┬──────────┘
       │             │             │             │                 │
       └─────────────┴─────────────┴─────────────┘                 │
                           │                                       │
                    ┌──────▼──────┐                                │
                    │   Webhook   │                                │
                    │  Controller │                                │
                    └──────┬──────┘                                │
                           │                                       │
                    ┌──────▼──────┐                         ┌──────▼──────┐
                    │   Webhook   │                         │     AI      │
                    │  Receiver   │                         │  Analyzer   │
                    │   Service   │                         │   Service   │
                    └──────┬──────┘                         └──────┬──────┘
                           │                                       │
       ┌───────────────────┼───────────────────────────────────────┤
       │                   │                                       │
┌──────▼──────┐     ┌──────▼──────┐     ┌──────────────┐    ┌──────▼──────┐
│   Vendor    │     │   Version   │     │     Diff     │    │   Upstream  │
│   Model     │◄────┤   Release   │────►│   Analyzer   │───►│    Todo     │
└─────────────┘     └─────────────┘     │   Service    │    └─────────────┘
                                        └──────────────┘           │
                                                                   │
                    ┌──────────────┐                        ┌──────▼──────┐
                    │    Issue     │◄───────────────────────┤    Issue    │
                    │   (GitHub/   │                        │  Generator  │
                    │    Gitea)    │                        │   Service   │
                    └──────────────┘                        └─────────────┘
```

## Module Registration

The package registers as a Laravel service provider via `Boot.php` and uses the event-driven module loading pattern from `core-php`.

### Event Listeners

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

### Registered Services

All services are registered as singletons:

| Service | Purpose |
|---------|---------|
| `VendorStorageService` | Local and S3 file storage for vendor archives |
| `VendorUpdateCheckerService` | Polls registries (GitHub, Packagist, npm) for updates |
| `DiffAnalyzerService` | Generates file diffs between versions |
| `AIAnalyzerService` | Uses AI to analyse diffs and categorise changes |
| `IssueGeneratorService` | Creates GitHub/Gitea issues from todos |
| `UptelligenceDigestService` | Compiles and sends digest notifications |
| `WebhookReceiverService` | Processes incoming vendor release webhooks |
| `AssetTrackerService` | Tracks package dependencies (Composer, npm) |

## Data Model

### Core Entities

```
┌─────────────┐     ┌─────────────────┐     ┌─────────────┐
│   Vendor    │────►│ VersionRelease  │────►│  DiffCache  │
└─────────────┘     └─────────────────┘     └─────────────┘
      │                     │
      │                     │
      ▼                     ▼
┌─────────────┐     ┌─────────────────┐
│UpstreamTodo │     │  AnalysisLog    │
└─────────────┘     └─────────────────┘
```

### Vendor

Represents an upstream software source (licensed, OSS, or plugin).

**Key attributes:**
- `slug` - Unique identifier
- `source_type` - `licensed`, `oss`, or `plugin`
- `git_repo_url` - Repository URL for OSS vendors
- `path_mapping` - Maps upstream paths to target paths
- `ignored_paths` - Patterns to skip during analysis
- `priority_paths` - High-importance file patterns
- `target_repo` - GitHub/Gitea repo for issue creation

### VersionRelease

Tracks a specific version of a vendor's software.

**Key attributes:**
- `version` / `previous_version` - Version comparison
- `storage_disk` - `local` or `s3`
- `s3_key` - Archive location in cold storage
- `file_hash` - SHA-256 for integrity verification
- `summary` - AI-generated release summary

### UpstreamTodo

A porting task generated from version analysis.

**Key attributes:**
- `type` - `feature`, `bugfix`, `security`, `ui`, `block`, `api`, `refactor`, `dependency`
- `status` - `pending`, `in_progress`, `ported`, `skipped`, `wont_port`
- `priority` - 1-10 scale
- `effort` - `low`, `medium`, `high`
- `ai_analysis` - Raw AI analysis data
- `github_issue_number` - Linked issue

### DiffCache

Stores individual file changes for a version release.

**Key attributes:**
- `change_type` - `added`, `modified`, `removed`
- `category` - Auto-detected: `controller`, `model`, `view`, `security`, etc.
- `diff_content` - Unified diff for modified files

## Webhook System

### Architecture

```
                          ┌─────────────────┐
  GitHub/GitLab/npm ─────►│WebhookController│
                          └────────┬────────┘
                                   │
                          ┌────────▼────────┐
                          │    Validate     │
                          │   Signature     │
                          └────────┬────────┘
                                   │
                          ┌────────▼────────┐
                          │    Create       │
                          │   Delivery      │
                          └────────┬────────┘
                                   │
                          ┌────────▼────────┐
                          │   Dispatch      │
                          │     Job         │
                          └────────┬────────┘
                                   │
                          ┌────────▼────────┐
                          │   Process       │
                          │   Webhook       │
                          │     Job         │
                          └────────┬────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    │              │              │
             ┌──────▼───┐   ┌──────▼───┐   ┌──────▼───┐
             │  Parse   │   │  Create  │   │  Notify  │
             │ Payload  │   │ Release  │   │  Users   │
             └──────────┘   └──────────┘   └──────────┘
```

### Supported Providers

| Provider | Signature Method | Event Types |
|----------|-----------------|-------------|
| GitHub | HMAC-SHA256 (`X-Hub-Signature-256`) | `release.published`, `release.created` |
| GitLab | Token (`X-Gitlab-Token`) | `release.create`, `tag_push` |
| npm | HMAC-SHA256 | `package:publish` |
| Packagist | HMAC-SHA1 | `package.update` |
| Custom | HMAC-SHA256 | Flexible |

### Secret Rotation

Webhooks support secret rotation with a configurable grace period (default 24 hours) where both old and new secrets are accepted.

### Circuit Breaker

After 10 consecutive failures, a webhook endpoint is automatically disabled to prevent continued processing failures.

## Storage Architecture

### Dual Storage Mode

The package supports both local and S3 storage for vendor archives.

```
┌─────────────────────────────────────────────────────────┐
│                    Storage Flow                          │
├─────────────────────────────────────────────────────────┤
│                                                          │
│   Upload ─► Local Storage ─► Create Archive ─► S3       │
│                                    │                     │
│                                    ▼                     │
│                            Delete Local                  │
│                          (optional, based                │
│                           on retention)                  │
│                                                          │
│   Analysis ─► Check Local ─► Not Found ─► Download S3   │
│                   │                            │         │
│                   ▼                            ▼         │
│              Use Local                    Extract        │
│                                              │           │
│                                              ▼           │
│                                         Use Local        │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Retention Policy

- Keep N most recent versions locally (configurable, default 2)
- Never delete current or previous version locally
- Archive older versions to S3 automatically
- Verify file integrity on S3 download via SHA-256 hash

### Path Structure

**Local:**
```
storage/app/vendors/{vendor-slug}/{version}/
```

**S3:**
```
{prefix}{vendor-slug}/{version}.tar.gz
```

## AI Analysis Pipeline

### Flow

```
VersionRelease
     │
     ▼
┌────────────┐
│   Group    │ ─► Related files grouped together
│   Diffs    │    (controller + view + route)
└─────┬──────┘
      │
      ▼
┌────────────┐
│   Build    │ ─► Construct context with file changes
│  Context   │
└─────┬──────┘
      │
      ▼
┌────────────┐
│   Call     │ ─► Rate-limited AI API call
│    AI      │    (10/minute default)
└─────┬──────┘
      │
      ▼
┌────────────┐
│   Parse    │ ─► Extract structured JSON
│ Response   │
└─────┬──────┘
      │
      ▼
┌────────────┐
│  Create    │ ─► Generate UpstreamTodo records
│   Todos    │
└────────────┘
```

### AI Providers

Supports:
- **Anthropic** (default) - Claude models
- **OpenAI** - GPT models

Configuration via `upstream.ai.provider` and `upstream.ai.model`.

### Rate Limiting

- AI API calls: 10/minute (configurable)
- Registry checks: 30/minute
- Issue creation: 10/minute
- Webhook ingestion: 60/minute per endpoint

## Console Commands

| Command | Purpose |
|---------|---------|
| `upstream:check` | Check vendors for updates, display status table |
| `upstream:analyze` | Analyse version diffs and generate todos |
| `upstream:issues` | Create GitHub/Gitea issues from pending todos |
| `upstream:check-updates` | Poll external registries for new versions |
| `upstream:send-digests` | Send scheduled digest emails |

## Admin UI Components

All components are Livewire-based and registered under the `uptelligence.admin.*` namespace:

| Component | Purpose |
|-----------|---------|
| `Dashboard` | Overview of vendors, todos, recent activity |
| `VendorManager` | CRUD for vendor configurations |
| `TodoList` | View and manage porting todos |
| `DiffViewer` | Browse file changes between versions |
| `AssetManager` | Track package dependencies |
| `DigestPreferences` | User notification settings |
| `WebhookManager` | Configure and monitor webhook endpoints |

## Configuration

See `/Users/snider/Code/host-uk/core-uptelligence/config.php` for all configuration options.

Key configuration sections:
- `storage` - Local and S3 storage settings
- `source_types` - Vendor type definitions
- `detection_patterns` - File categorisation patterns
- `ai` - AI provider and model settings
- `github` / `gitea` - Issue tracker integration
- `update_checker` - Auto-checking behaviour
- `notifications` - Slack, Discord, email settings
- `default_vendors` - Pre-configured vendor seeds

## Dependencies

### Required
- `host-uk/core` - Foundation framework

### External Services
- Anthropic API or OpenAI API (for AI analysis)
- GitHub API (for releases and issues)
- Gitea API (for internal git server)
- Packagist API (for Composer package checks)
- npm Registry (for npm package checks)
- S3-compatible storage (for archives)
