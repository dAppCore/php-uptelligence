<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Vendor Storage Configuration
    |--------------------------------------------------------------------------
    | Supports local and S3 cold storage. When using S3, versions are archived
    | after import and downloaded on-demand for analysis.
    */
    'storage' => [
        // Primary storage disk: 'local' or 's3'
        'disk' => env('UPSTREAM_STORAGE_DISK', 'local'),

        // Local paths (always used for active/temp files)
        'base_path' => storage_path('app/vendors'),
        'licensed' => storage_path('app/vendors/licensed'),
        'oss' => storage_path('app/vendors/oss'),
        'plugins' => storage_path('app/vendors/plugins'),
        'temp_path' => storage_path('app/temp/upstream'),

        // S3 cold storage settings (Hetzner Object Store compatible)
        's3' => [
            // Private bucket for vendor archives (not publicly accessible)
            'bucket' => env('UPSTREAM_S3_BUCKET', 'hostuk'),
            'prefix' => env('UPSTREAM_S3_PREFIX', 'upstream/vendors/'),
            'region' => env('UPSTREAM_S3_REGION', env('AWS_DEFAULT_REGION', 'eu-west-2')),

            // Dual endpoint support for Hetzner Object Store
            // Private: Internal access only (hostuk)
            // Public: CDN/public access (host-uk) - NOT used for vendor archives
            'private_endpoint' => env('S3_PRIVATE_ENDPOINT', env('AWS_ENDPOINT')),
            'public_endpoint' => env('S3_PUBLIC_ENDPOINT'),

            // Disk name in config/filesystems.php
            // Defaults to private storage for vendor archives
            'disk' => env('UPSTREAM_S3_DISK', 's3-private'),
        ],

        // Archive behavior
        'archive' => [
            // Auto-archive to S3 after import (if disk is 's3')
            'auto_archive' => env('UPSTREAM_AUTO_ARCHIVE', true),
            // Delete local files after successful S3 upload
            'delete_local_after_archive' => env('UPSTREAM_DELETE_LOCAL', true),
            // Keep local copies for N most recent versions per vendor
            'keep_local_versions' => env('UPSTREAM_KEEP_LOCAL', 2),
            // Cleanup temp files older than N hours
            'cleanup_after_hours' => env('UPSTREAM_CLEANUP_HOURS', 24),
        ],

        // Download behavior
        'download' => [
            // Max concurrent downloads
            'max_concurrent' => 3,
            // Download timeout in seconds
            'timeout' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor Source Types
    |--------------------------------------------------------------------------
    | - licensed: Paid software (manual upload/extract)
    | - oss: Open source (git submodule capable)
    | - plugin: Plugin packages (Altum, WordPress, etc.)
    */
    'source_types' => [
        'licensed' => [
            'label' => 'Licensed Software',
            'description' => 'Paid/proprietary software requiring manual version uploads',
            'can_git_sync' => false,
            'requires_upload' => true,
        ],
        'oss' => [
            'label' => 'Open Source',
            'description' => 'Open source projects that can be git submoduled',
            'can_git_sync' => true,
            'requires_upload' => false,
        ],
        'plugin' => [
            'label' => 'Plugin/Extension',
            'description' => 'Plugins for various platforms (Altum, WordPress, etc.)',
            'can_git_sync' => false,
            'requires_upload' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Platforms
    |--------------------------------------------------------------------------
    */
    'plugin_platforms' => [
        'altum' => 'Altum/phpBioLinks',
        'wordpress' => 'WordPress',
        'laravel' => 'Laravel Package',
        'other' => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Detection Patterns
    |--------------------------------------------------------------------------
    | File patterns to auto-detect change categories
    */
    'detection_patterns' => [
        'security' => [
            '*/security/*',
            '*/auth/*',
            '*password*',
            '*permission*',
            '*/middleware/*',
            '*csrf*',
            '*xss*',
        ],
        'controller' => [
            '*/controllers/*',
            '*Controller.php',
        ],
        'model' => [
            '*/models/*',
            '*Model.php',
            '*/Entities/*',
        ],
        'view' => [
            '*/views/*',
            '*/themes/*',
            '*.blade.php',
            '*/templates/*',
        ],
        'migration' => [
            '*/migrations/*',
            '*/database/*',
            '*schema*',
        ],
        'api' => [
            '*/api/*',
            '*api.php',
            '*/Api/*',
        ],
        'block' => [
            '*/blocks/*',
            '*biolink*',
            '*Block.php',
        ],
        'plugin' => [
            '*/plugins/*',
            '*Plugin.php',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Analysis Settings
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'provider' => env('UPSTREAM_AI_PROVIDER', 'anthropic'),
        'model' => env('UPSTREAM_AI_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => 4096,
        'temperature' => 0.3,

        // Rate limiting: max AI API calls per minute
        'rate_limit' => env('UPSTREAM_AI_RATE_LIMIT', 10),

        // Prompt templates
        'prompts' => [
            'categorize' => 'Analyse this code diff and categorise the change type (feature, bugfix, security, ui, refactor, etc). Also estimate the effort level (low, medium, high) and priority (1-10) for porting.',
            'summarize' => 'Summarise the key changes in this version update in bullet points. Focus on user-facing features, security updates, and breaking changes.',
            'dependencies' => 'Identify any dependencies this change has on other files or features that would need to be ported first.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Integration
    |--------------------------------------------------------------------------
    */
    'github' => [
        'enabled' => env('UPSTREAM_GITHUB_ENABLED', true),
        'token' => env('GITHUB_TOKEN'),
        'default_labels' => ['upstream', 'auto-generated'],
        'assignees' => explode(',', env('UPSTREAM_GITHUB_ASSIGNEES', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gitea Integration (Internal)
    |--------------------------------------------------------------------------
    */
    'gitea' => [
        'enabled' => env('UPSTREAM_GITEA_ENABLED', true),
        'url' => env('GITEA_URL', 'https://forge.lthn.ai'),
        'token' => env('GITEA_TOKEN', env('FORGE_TOKEN')),
        'org' => env('GITEA_ORG', 'core'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Checker Settings
    |--------------------------------------------------------------------------
    */
    'update_checker' => [
        // Auto-create todos when updates are detected
        'create_todos' => env('UPSTREAM_CREATE_TODOS', true),

        // Default priority for auto-created update todos (1-10)
        'default_priority' => 5,

        // Skip checking vendors that haven't been updated in N days
        // Set to 0 to always check all vendors
        'skip_recently_checked_days' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'slack_webhook' => env('UPSTREAM_SLACK_WEBHOOK'),
        'discord_webhook' => env('UPSTREAM_DISCORD_WEBHOOK'),
        'email_recipients' => explode(',', env('UPSTREAM_EMAIL_RECIPIENTS', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Vendor Configurations
    |--------------------------------------------------------------------------
    | Pre-configured vendors to seed the database with
    */
    'default_vendors' => [
        [
            'slug' => '66biolinks',
            'name' => '66biolinks',
            'vendor_name' => 'AltumCode',
            'source_type' => 'licensed',
            'path_mapping' => [
                'app/' => 'product/app/',
                'themes/' => 'product/themes/',
                'plugins/' => 'product/plugins/',
            ],
            'ignored_paths' => [
                'vendor/*',
                'node_modules/*',
                'storage/*',
                '.git/*',
                '*.log',
            ],
            'priority_paths' => [
                'app/controllers/*',
                'app/models/*',
                'plugins/*/init.php',
                'themes/altum/views/l/*',
            ],
            'target_repo' => 'host-uk/bio.host.uk.com',
        ],
        [
            'slug' => 'mixpost-pro',
            'name' => 'Mixpost Pro',
            'vendor_name' => 'Inovector',
            'source_type' => 'licensed',
            'path_mapping' => [
                'src/' => 'packages/mixpost-pro/src/',
            ],
            'ignored_paths' => [
                'vendor/*',
                'node_modules/*',
                'tests/*',
            ],
            'priority_paths' => [
                'src/Http/Controllers/*',
                'src/Models/*',
                'src/Services/*',
            ],
            'target_repo' => 'host-uk/host.uk.com',
        ],
        [
            'slug' => 'mixpost-enterprise',
            'name' => 'Mixpost Enterprise',
            'vendor_name' => 'Inovector',
            'source_type' => 'licensed',
            'path_mapping' => [
                'src/' => 'packages/mixpost-enterprise/src/',
            ],
            'ignored_paths' => [
                'vendor/*',
                'node_modules/*',
                'tests/*',
            ],
            'priority_paths' => [
                'src/Billing/*',
                'src/Features/*',
            ],
            'target_repo' => 'host-uk/host.uk.com',
        ],
    ],
];
