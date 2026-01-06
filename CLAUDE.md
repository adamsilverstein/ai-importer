# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AI Importer is a WordPress plugin that enables users to migrate content from social media platforms (Twitter/X, Instagram, Medium, Blogger, etc.) into WordPress. It uses AI-powered analysis via the WordPress AI Experiments plugin to intelligently map and transform content.

## Development Commands

```bash
# Install dependencies
composer install
npm install

# Build assets
npm run build

# Development with watch mode
npm run start

# Run PHP linting (WordPress Coding Standards)
composer run lint

# Run PHP tests
composer run test

# Run a single PHPUnit test
./vendor/bin/phpunit --filter TestClassName

# Run JavaScript/E2E tests
npm run test
npm run test:e2e
```

## Architecture

### Core Components

- **Source Adapters** (`/includes/adapters/`): Platform-specific modules implementing a common interface:
  - `authenticate()` - Connect via OAuth, API key, or file upload
  - `fetch_manifest()` - Get inventory of available content
  - `fetch_item()` - Retrieve individual content items
  - `get_settings_schema()` - Define adapter-specific options

- **Content Normalizer** (`/includes/normalizer/`): Transforms platform-specific formats into a universal intermediate schema

- **Schema Analyzer** (`/includes/schema/`): Introspects destination WordPress site structure (post types, taxonomies, custom fields)

- **AI Service** (`/includes/ai/`): Wrapper around WP_AI_Client for migration-specific AI tasks (content analysis, mapping suggestions, enhancements)

- **Import Processor** (`/includes/processor/`): Handles WordPress content creation via Action Scheduler for background processing

### Admin UI

React-based admin interface built with `@wordpress/scripts`:
- Located in `/src/` directory
- Uses `@wordpress/components` and `@wordpress/data`
- Entry points compile to `/build/`

### Data Flow

1. User connects source (OAuth/file upload) → stored in `wp_options`
2. Adapter parses source → ContentManifest created and cached
3. AI analyzes sample + site schema → MappingSuggestions generated
4. Import queued via Action Scheduler → items fetched, normalized, enhanced, created as posts
5. Media sideloaded to Media Library with optimization

### Database

Uses WordPress core tables with custom post meta:
- `_ai_importer_source` - Source adapter ID
- `_ai_importer_source_id` - Original platform ID
- `_ai_importer_batch_id` - Import batch UUID
- `_ai_importer_original_url` - Link to original content

Options keys:
- `ai_importer_adapter_{id}` - Connection data
- `ai_importer_batch_{uuid}` - Batch progress
- `ai_importer_mappings_{adapter}` - Saved mappings

## Key Dependencies

- WordPress 6.4+, PHP 8.1+
- AI Experiments plugin (`ai`) - provides WP_AI_Client
- Action Scheduler for background jobs
- `@wordpress/scripts` for asset building

## MVP Platforms

Twitter/X, Medium, Instagram, Blogger & Tumblr (all via file upload, scraping or OAuth)

## Work Flow
* Check the PRD document regularly to ensure alignment with product goals. Update as necessary.
* Follow the established coding standards and best practices for WordPress development.
* Write clear, concise commit messages.
* Commit regularly as you work, adding changes incrementally.
* Open a pull request when your feature is complete for review.
