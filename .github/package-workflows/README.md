# Package Workflows

These workflow templates are for **library packages** (host-uk/core, host-uk/core-api, etc.), not application projects.

## README Badges

Add these badges to your package README (replace `{package}` with your package name):

```markdown
[![CI](https://github.com/host-uk/{package}/actions/workflows/ci.yml/badge.svg)](https://github.com/host-uk/{package}/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/host-uk/{package}/graph/badge.svg)](https://codecov.io/gh/host-uk/{package})
[![Latest Version](https://img.shields.io/packagist/v/host-uk/{package})](https://packagist.org/packages/host-uk/{package})
[![PHP Version](https://img.shields.io/packagist/php-v/host-uk/{package})](https://packagist.org/packages/host-uk/{package})
[![License](https://img.shields.io/badge/License-EUPL--1.2-blue.svg)](LICENSE)
```

## Usage

Copy the relevant workflows to your library's `.github/workflows/` directory:

```bash
# In your library repo
mkdir -p .github/workflows
cp path/to/core-template/.github/package-workflows/ci.yml .github/workflows/
cp path/to/core-template/.github/package-workflows/release.yml .github/workflows/
```

## Workflows

### ci.yml
- Runs on push/PR to main
- Tests against PHP 8.2, 8.3, 8.4
- Tests against Laravel 11 and 12
- Runs Pint linting
- Runs Pest tests

### release.yml
- Triggers on version tags (v*)
- Generates changelog using git-cliff
- Creates GitHub release

## Requirements

For these workflows to work, your package needs:

1. **cliff.toml** - Copy from core-template root
2. **Pest configured** - `composer require pestphp/pest --dev`
3. **Pint configured** - `composer require laravel/pint --dev`
4. **CODECOV_TOKEN** - Add to repo secrets for coverage uploads
5. **FUNDING.yml** - Copy `.github/FUNDING.yml` for sponsor button

## Recommended composer.json scripts

```json
{
  "scripts": {
    "lint": "pint",
    "test": "pest",
    "test:coverage": "pest --coverage"
  }
}
```
