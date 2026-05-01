# AI Importer - Product Requirements Document

**Version:** 1.0
**Last Updated:** January 5, 2026
**Author:** Adam Silverstein
**Repository:** https://github.com/adamsilverstein/ai-importer

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Problem Statement](#problem-statement)
3. [Product Vision](#product-vision)
4. [Target Users](#target-users)
5. [Core Value Propositions](#core-value-propositions)
6. [Feature Requirements](#feature-requirements)
7. [Technical Architecture](#technical-architecture)
8. [User Experience](#user-experience)
9. [Platform Support](#platform-support)
10. [AI Capabilities](#ai-capabilities)
11. [Security & Privacy](#security--privacy)
12. [Performance Requirements](#performance-requirements)
13. [Release Strategy](#release-strategy)
14. [Success Metrics](#success-metrics)
15. [Risks & Mitigations](#risks--mitigations)
16. [Appendices](#appendices)

---

## Executive Summary

**AI Importer** is a WordPress plugin that enables users to migrate their content from any major social media platform or content repository into WordPress. Using AI-powered analysis and mapping, the plugin intelligently transforms platform-specific content into well-structured WordPress posts while preserving metadata, relationships, and media.

Unlike existing import tools that require manual configuration and produce inconsistent results, AI Importer uses large language models to understand content structure, suggest optimal mappings, and enhance imported content with features like alt text generation, thread stitching, and SEO optimization.

The plugin relies entirely on the native WordPress AI capabilities introduced in WordPress 7.0. AI provider configuration — including credentials, model selection, and routing — is owned by WordPress core via the Connectors API. The plugin contains no provider-specific logic and stores no API keys; users configure their preferred connection once in **Settings → Connections** and AI Importer uses it automatically.

---

## Problem Statement

### The Content Fragmentation Problem

Users have accumulated years of content across multiple platforms:
- Twitter/X threads documenting their expertise
- Instagram posts showcasing their work
- Medium articles building their thought leadership
- YouTube videos with valuable descriptions and transcripts
- Blog posts on Blogger, Tumblr, or Substack

This content represents significant intellectual and creative investment, but it remains:
- **Scattered** across platforms with different ownership models
- **Vulnerable** to platform policy changes, shutdowns, or account issues
- **Underutilized** because it's not indexed by search engines effectively
- **Disconnected** from the user's primary web presence

### Current Solutions Fall Short

Existing migration tools suffer from:

| Problem | Impact |
|---------|--------|
| Platform-specific | Users need different tools for each source |
| Manual mapping | Requires technical knowledge to configure |
| Poor content transformation | Tweets become awkward posts, threads are split |
| No media handling | Images/videos lost or broken |
| No intelligence | Can't adapt to destination site structure |
| Abandoned/outdated | Many importers no longer maintained |

### The Opportunity

WordPress powers 43% of the web. A universal, AI-powered import solution would:
- Give users true ownership of their content
- Consolidate years of work into a single, searchable archive
- Improve SEO by bringing content to owned domains
- Reduce platform dependency and risk

---

## Product Vision

### Vision Statement

> **AI Importer makes WordPress the universal home for all your content, using AI to transform scattered social media posts into a cohesive, searchable, professionally-structured archive.**

### Product Principles

1. **Universal by Design**
   Support every major content platform through a consistent interface. One tool to rule them all.

2. **Intelligent, Not Complicated**
   AI handles the complexity of content mapping. Users make high-level decisions, not field-by-field configurations.

3. **Preserve Everything That Matters**
   Metadata, timestamps, relationships, engagement stats, and media should survive the migration intact.

4. **Enhance, Don't Just Move**
   Use the migration as an opportunity to improve content: add alt text, generate SEO metadata, stitch threads into articles.

5. **Respect User Autonomy**
   AI provider choice and credential storage are owned by WordPress core's Connectors API. No vendor lock-in, no API keys handled by the plugin, full user control over provider and data handling.

6. **WordPress-Native**
   Feel like a natural part of WordPress. Use blocks, respect theme structures, integrate with existing plugins.

---

## Target Users

### Primary Personas

#### 1. The Content Creator Consolidator
**Profile:** Blogger, writer, or thought leader with 5+ years of content across platforms
**Goal:** Create a comprehensive archive of their work on their own domain
**Pain Point:** Has thousands of tweets, hundreds of Instagram posts, dozens of Medium articles—all siloed
**Success Criteria:** All content searchable on their WordPress site within a day

#### 2. The Platform Refugee
**Profile:** User leaving a platform due to policy changes, ownership concerns, or feature degradation
**Goal:** Quickly export content before it's lost or becomes inaccessible
**Pain Point:** Needs to act fast, doesn't have time to learn complex tools
**Success Criteria:** Complete migration with minimal content loss in under an hour

#### 3. The Professional Archiver
**Profile:** Agency or professional managing multiple brands/clients
**Goal:** Migrate client content as part of website projects
**Pain Point:** Needs reliable, repeatable process that works across different sites
**Success Criteria:** Consistent results across different WordPress configurations

#### 4. The SEO Optimizer
**Profile:** Marketer who sees untapped SEO value in social content
**Goal:** Transform social proof into searchable, indexable content
**Pain Point:** Social posts don't rank; wants the content to drive organic traffic
**Success Criteria:** Imported content ranks for relevant keywords

### Secondary Personas

- **Digital Estate Planners:** Archiving content for posterity
- **Researchers:** Building searchable archives of their public communications
- **Small Business Owners:** Consolidating years of social media marketing

---

## Core Value Propositions

### 1. Universal Platform Support

Import from any major content source through a single, consistent interface:

| Category | Platforms |
|----------|-----------|
| Social Media | Twitter/X, Instagram, Facebook, TikTok, LinkedIn |
| Blogging | Medium, Blogger, Tumblr, Substack, Ghost |
| Video | YouTube, Twitch, Vimeo |
| Other | Notion, Reddit, Mastodon |

### 2. AI-Powered Intelligence

The AI layer provides:

- **Content Analysis:** Understand what types of content exist and how they should be organized
- **Smart Mapping:** Automatically suggest how source content maps to destination post types, taxonomies, and fields
- **Enhancement Pipeline:** Optionally improve content during import (alt text, SEO, thread stitching)
- **Conflict Resolution:** Intelligently handle duplicates, naming conflicts, and edge cases

### 3. WordPress-Native Integration

- Imports create proper WordPress posts with blocks
- Respects existing site structure (CPTs, taxonomies, ACF fields)
- Media goes through WordPress media library
- Works with any theme and major plugins

### 4. Preservation of Context

- Original publish dates maintained
- Engagement metrics stored as meta
- Original URLs preserved for reference
- Platform-specific metadata retained
- Relationships (threads, series) maintained

### 5. User Control & Transparency

- Preview before import
- Granular selection of what to import
- Rollback capability
- Clear progress and error reporting
- No data sent to third parties (except the AI provider configured in WordPress Connections)

---

## Feature Requirements

### P0 - Must Have (MVP)

#### F1: Source Connection
| ID | Requirement |
|----|-------------|
| F1.1 | Support Twitter/X archive file upload |
| F1.2 | Support Medium export file upload |
| F1.3 | Support Instagram data download upload |
| F1.4 | Support Blogger via OAuth or XML upload |
| F1.5 | Display connection status for each source |
| F1.6 | Allow disconnection/removal of sources |

#### F2: Content Inventory
| ID | Requirement |
|----|-------------|
| F2.1 | Parse and display manifest of available content |
| F2.2 | Show content type breakdown (posts, images, threads, etc.) |
| F2.3 | Display date range of content |
| F2.4 | Show total counts and estimated import size |
| F2.5 | Allow filtering by type, date, engagement |

#### F3: AI Analysis & Mapping
| ID | Requirement |
|----|-------------|
| F3.1 | Analyze content and identify patterns/topics |
| F3.2 | Detect destination site structure (CPTs, taxonomies) |
| F3.3 | Generate mapping suggestions with reasoning |
| F3.4 | Allow user to accept, modify, or reject suggestions |
| F3.5 | Save mapping configurations for reuse |

#### F4: Import Execution
| ID | Requirement |
|----|-------------|
| F4.1 | Background processing for large imports |
| F4.2 | Progress indicator with ETA |
| F4.3 | Graceful handling of failures (skip and continue) |
| F4.4 | Media sideloading with optimization |
| F4.5 | Preserve original publish dates |
| F4.6 | Tag imported content for identification/rollback |

#### F5: Basic Enhancements
| ID | Requirement |
|----|-------------|
| F5.1 | Generate alt text for images without it |
| F5.2 | Stitch tweet threads into single posts |
| F5.3 | Convert hashtags to WordPress tags |
| F5.4 | Clean platform-specific cruft from content |

#### F6: Admin Interface
| ID | Requirement |
|----|-------------|
| F6.1 | Source selection screen |
| F6.2 | Connection/upload wizard |
| F6.3 | Mapping configuration screen |
| F6.4 | Import progress dashboard |
| F6.5 | Post-import summary and review |

### P1 - Should Have (v1.1)

#### F7: Additional Sources
| ID | Requirement |
|----|-------------|
| F7.1 | Tumblr adapter |
| F7.2 | YouTube adapter |
| F7.3 | Substack adapter |
| F7.4 | Ghost adapter |

#### F8: Advanced Enhancements
| ID | Requirement |
|----|-------------|
| F8.1 | Generate SEO meta descriptions |
| F8.2 | Suggest/generate titles for untitled content |
| F8.3 | Content expansion (short posts → articles) |
| F8.4 | Internal linking suggestions |

#### F9: Advanced Mapping
| ID | Requirement |
|----|-------------|
| F9.1 | Custom field mapping (ACF, Meta Box) |
| F9.2 | Author mapping for multi-author sites |
| F9.3 | Custom taxonomy creation during import |
| F9.4 | Post format assignment |

#### F10: Import Management
| ID | Requirement |
|----|-------------|
| F10.1 | Rollback entire import batch |
| F10.2 | Incremental/delta imports (new content only) |
| F10.3 | Scheduled imports for connected sources |
| F10.4 | Import history and audit log |

### P2 - Nice to Have (Future)

#### F11: More Sources
| ID | Requirement |
|----|-------------|
| F11.1 | TikTok adapter |
| F11.2 | Notion adapter |
| F11.3 | LinkedIn adapter |
| F11.4 | Mastodon adapter |
| F11.5 | Reddit adapter |
| F11.6 | Facebook adapter |

#### F12: Advanced Features
| ID | Requirement |
|----|-------------|
| F12.1 | Duplicate detection across sources |
| F12.2 | Content de-duplication with existing site content |
| F12.3 | Transcript extraction from videos |
| F12.4 | Comment import |
| F12.5 | Engagement data visualization |

#### F13: Developer Features
| ID | Requirement |
|----|-------------|
| F13.1 | REST API for programmatic imports |
| F13.2 | WP-CLI commands |
| F13.3 | Webhook notifications |
| F13.4 | Custom adapter SDK |

---

## Technical Architecture

### System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        WordPress Installation                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │            WordPress Native AI (wp_get_ai_client)             │ │
│  │  • Connectors API   • Model Selection   • AI Client            │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                    ▲                                 │
│                                    │                                 │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                      AI Importer Plugin                         │ │
│  │                                                                  │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐ │ │
│  │  │   Admin UI  │  │  REST API   │  │      WP-CLI (future)    │ │ │
│  │  │   (React)   │  │  Endpoints  │  │                         │ │ │
│  │  └──────┬──────┘  └──────┬──────┘  └────────────┬────────────┘ │ │
│  │         │                │                      │               │ │
│  │         └────────────────┼──────────────────────┘               │ │
│  │                          ▼                                       │ │
│  │  ┌──────────────────────────────────────────────────────────┐   │ │
│  │  │                    Core Services                          │   │ │
│  │  │  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐  │   │ │
│  │  │  │   Adapter    │  │   Content    │  │     Schema     │  │   │ │
│  │  │  │   Registry   │  │  Normalizer  │  │    Analyzer    │  │   │ │
│  │  │  └──────────────┘  └──────────────┘  └────────────────┘  │   │ │
│  │  │  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐  │   │ │
│  │  │  │     AI       │  │    Import    │  │     Media      │  │   │ │
│  │  │  │   Service    │  │   Processor  │  │   Sideloader   │  │   │ │
│  │  │  └──────────────┘  └──────────────┘  └────────────────┘  │   │ │
│  │  └──────────────────────────────────────────────────────────┘   │ │
│  │                          │                                       │ │
│  │  ┌──────────────────────────────────────────────────────────┐   │ │
│  │  │                   Source Adapters                         │   │ │
│  │  │  ┌─────────┐ ┌─────────┐ ┌───────────┐ ┌─────────┐       │   │ │
│  │  │  │ Twitter │ │ Medium  │ │ Instagram │ │ Blogger │  ...  │   │ │
│  │  │  └─────────┘ └─────────┘ └───────────┘ └─────────┘       │   │ │
│  │  └──────────────────────────────────────────────────────────┘   │ │
│  │                                                                  │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                     WordPress Core                              │ │
│  │  • Posts/CPTs    • Taxonomies    • Media Library    • Users    │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │  WordPress Connectors API     │
                    │  (Settings → Connections)     │
                    └──────────────┬────────────────┘
                                   ▼
                    ┌───────────────────────────────┐
                    │   Configured AI Provider      │
                    └───────────────────────────────┘
```

### Component Descriptions

#### Source Adapters
Platform-specific modules that handle authentication and content extraction. Each adapter implements a common interface:
- `authenticate()` - Connect to source (OAuth, API key, website scraping or file upload)
- `fetch_manifest()` - Get inventory of available content
- `fetch_item()` - Retrieve individual content items
- `get_settings_schema()` - Define adapter-specific options

#### Content Normalizer
Transforms platform-specific formats into a universal intermediate schema. Handles:
- HTML cleaning and normalization
- Date format conversion
- Media reference extraction
- Metadata standardization

#### Schema Analyzer
Introspects the destination WordPress site to understand:
- Available post types and their capabilities
- Registered taxonomies and existing terms
- Custom fields (ACF, Meta Box, etc.)
- Theme supports and capabilities

#### AI Service
Wrapper around the WordPress native AI client (`wp_get_ai_client()`) that provides migration-specific methods:
- Content analysis and classification
- Mapping suggestion generation
- Enhancement tasks (alt text, thread stitching, etc.)
- Structured output handling via JSON schemas

#### Import Processor
Handles the actual WordPress content creation:
- Background processing via Action Scheduler
- Batched operations with progress tracking
- Media sideloading and optimization
- Rollback tagging and recovery

### Data Flow

```
1. USER CONNECTS SOURCE
   └─► Adapter authenticates (OAuth/file upload/web scraping)
   └─► Connection data stored in wp_options

2. MANIFEST GENERATION
   └─► Adapter parses source data
   └─► ContentManifest created with item metadata
   └─► Manifest cached for session

3. AI ANALYSIS
   └─► Sample of manifest sent to AI
   └─► Site schema analyzed
   └─► MappingSuggestions generated
   └─► User reviews and adjusts

4. IMPORT EXECUTION
   └─► Items queued via Action Scheduler
   └─► Each item: fetch → normalize → enhance → create post
   └─► Media sideloaded to Media Library
   └─► Progress updated in real-time

5. POST-IMPORT
   └─► Summary generated
   └─► Items flagged for review surfaced
   └─► Rollback option available
```

### Database Schema

The plugin primarily uses WordPress core tables but adds metadata:

**Post Meta Keys:**
- `_ai_importer_source` - Source adapter ID
- `_ai_importer_source_id` - Original ID on source platform
- `_ai_importer_batch_id` - Import batch UUID
- `_ai_importer_original_url` - Link to original content
- `_ai_importer_imported_at` - Import timestamp
- `_ai_importer_engagement` - Serialized engagement stats

**Options:**
- `ai_importer_adapter_{id}` - Per-adapter connection data
- `ai_importer_batch_{uuid}` - Batch progress/status
- `ai_importer_mappings_{adapter}` - Saved mapping configurations

### Technology Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.1+ |
| Framework | WordPress 7.0+ |
| Admin UI | React (via @wordpress/scripts) |
| Background Jobs | Action Scheduler |
| AI Client | WordPress native AI (wp_get_ai_client) |
| HTTP | WordPress HTTP API |
| Testing | PHPUnit, Playwright |

### Dependencies

**Required:**
- WordPress 7.0+
- PHP 8.1+

**Composer:**
- `woocommerce/action-scheduler` (or bundled)

**npm (dev):**
- `@wordpress/scripts`
- `@wordpress/components`
- `@wordpress/data`

---

## User Experience

### Information Architecture

```
AI Importer (Top-level menu)
├── Dashboard
│   ├── Connected sources overview
│   ├── Recent imports
│   └── Quick actions
├── Import
│   ├── Step 1: Select Source
│   ├── Step 2: Connect/Upload
│   ├── Step 3: Review Content
│   ├── Step 4: Configure Mapping
│   ├── Step 5: Select Enhancements
│   ├── Step 6: Import
│   └── Step 7: Review Results
├── Sources
│   ├── Connected sources list
│   ├── Add new source
│   └── Source settings
├── History
│   ├── Past imports
│   ├── Import details
│   └── Rollback options
└── Settings
    ├── Default mappings
    ├── Enhancement preferences
    └── Advanced options
```

### User Flow: First Import

```
┌─────────────────────────────────────────────────────────────────┐
│                         WELCOME SCREEN                           │
│                                                                   │
│  "Import your content from anywhere"                             │
│                                                                   │
│  [Twitter/X]  [Instagram]  [Medium]  [Blogger]  [More...]       │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      SOURCE CONNECTION                           │
│                                                                   │
│  Twitter/X Archive                                               │
│  ─────────────────                                               │
│  Upload your Twitter data archive (.zip file)                    │
│                                                                   │
│  [Download instructions]                                         │
│                                                                   │
│  ┌─────────────────────────────────────────┐                    │
│  │  Drop your archive here or click to     │                    │
│  │  browse                                  │                    │
│  └─────────────────────────────────────────┘                    │
│                                                                   │
│  [Cancel]                                    [Continue →]        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      CONTENT PREVIEW                             │
│                                                                   │
│  Found 2,847 items in your archive                              │
│  ═══════════════════════════════════                            │
│                                                                   │
│  📝 Tweets          2,104                                        │
│  🧵 Threads           147    (423 tweets)                        │
│  🖼️ With media        892                                        │
│  💬 Replies           524    [excluded by default]               │
│  🔄 Retweets          312    [excluded by default]               │
│                                                                   │
│  Date range: Mar 2018 - Dec 2025                                │
│                                                                   │
│  [Filter options ▼]                                              │
│                                                                   │
│  [← Back]                                    [Continue →]        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     AI MAPPING SUGGESTIONS                       │
│                                                                   │
│  🤖 Based on your content and site structure, I suggest:        │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ CONTENT MAPPING                                              ││
│  │                                                              ││
│  │ Tweets & Threads  →  Posts                          [Edit]  ││
│  │ Hashtags          →  Tags                           [Edit]  ││
│  │ Media             →  Media Library                  [Edit]  ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ AI REASONING                                                 ││
│  │                                                              ││
│  │ "Your tweets cover diverse topics without a clear category  ││
│  │ structure. I recommend importing as Posts with hashtags as  ││
│  │ tags. You have 47 threads that would work well as longer    ││
│  │ articles - I'll stitch these together."                     ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│  [← Back]                                    [Continue →]        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       ENHANCEMENTS                               │
│                                                                   │
│  Improve your content during import:                            │
│                                                                   │
│  ☑️ Generate alt text for images                                 │
│     892 images need alt text                                     │
│                                                                   │
│  ☑️ Stitch threads into single posts                             │
│     147 threads will become cohesive articles                    │
│                                                                   │
│  ☐ Expand short tweets into fuller posts                        │
│     Uses AI to add context (increases API usage)                │
│                                                                   │
│  ☑️ Generate SEO meta descriptions                               │
│     Helps imported content rank in search                        │
│                                                                   │
│  ☐ Convert hashtags to categories (instead of tags)             │
│                                                                   │
│  ⚠️ Estimated AI API calls: ~1,200                               │
│                                                                   │
│  [← Back]                                    [Start Import →]   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      IMPORT PROGRESS                             │
│                                                                   │
│  Importing your Twitter archive...                              │
│                                                                   │
│  ████████████████████░░░░░░░░░░  67%                            │
│                                                                   │
│  ✓ 1,412 tweets imported                                        │
│  ✓ 98 threads stitched                                          │
│  ⟳ Processing media... (534 of 892)                             │
│  ⟳ Generating alt text...                                       │
│                                                                   │
│  Estimated time remaining: 4 minutes                            │
│                                                                   │
│  [Run in background]                         [Cancel Import]    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      IMPORT COMPLETE                             │
│                                                                   │
│  ✅ Successfully imported 2,251 items                            │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ SUMMARY                                                      ││
│  │                                                              ││
│  │ Posts created:        2,104                                  ││
│  │ Threads stitched:       147                                  ││
│  │ Images imported:        892                                  ││
│  │ Alt text generated:     847                                  ││
│  │ Tags created:           234                                  ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ⚠️ 12 items need review                     [Review items →]   │
│                                                                   │
│  [View imported posts]        [Import more]      [Done]         │
│                                                                   │
│  💡 You can undo this import from Settings → History            │
└─────────────────────────────────────────────────────────────────┘
```

### Design Principles

1. **Progressive Disclosure**
   Show simple defaults first, advanced options on demand

2. **Explain AI Decisions**
   Always show reasoning behind AI suggestions

3. **Non-Destructive by Default**
   Import as drafts, provide rollback, preserve originals

4. **Clear Progress Indication**
   Users should always know what's happening and how long it will take

5. **Graceful Degradation**
   Work without AI (manual mapping) if API unavailable

---

## Platform Support

### MVP Platforms (v1.0)

| Platform | Auth Method | Content Types | Complexity |
|----------|-------------|---------------|------------|
| Twitter/X | File Upload | Tweets, threads, media | High |
| Medium | File Upload | Stories, responses | Medium |
| Instagram | File Upload | Posts, reels, stories | Medium |
| Blogger | OAuth + XML | Posts, pages, comments | Medium |

### Phase 2 Platforms (v1.1)

| Platform | Auth Method | Content Types | Complexity |
|----------|-------------|---------------|------------|
| Tumblr | OAuth | Posts (all types), reblogs | Medium |
| YouTube | OAuth | Videos, playlists, community | Medium |
| Substack | File Upload | Posts, podcasts | Low |
| Ghost | API Key | Posts, pages, tags | Low |

### Future Platforms (v2.0+)

| Platform | Auth Method | Notes |
|----------|-------------|-------|
| TikTok | File Upload | Video metadata only |
| Notion | OAuth | Complex block structure |
| LinkedIn | OAuth | API restrictions |
| Facebook | OAuth | Complex permissions |
| Mastodon | OAuth | Per-instance auth |
| Reddit | OAuth | Posts, comments |
| Pinterest | OAuth | Pins, boards |

### Platform Support Matrix

| Feature | Twitter | Medium | Instagram | Blogger |
|---------|---------|--------|-----------|---------|
| Posts/Articles | ✅ | ✅ | ✅ | ✅ |
| Images | ✅ | ✅ | ✅ | ✅ |
| Videos | ✅ | ❌ | ✅ | ❌ |
| Threads/Series | ✅ | ❌ | ❌ | ❌ |
| Comments | ❌ | ❌ | ❌ | ✅ |
| Drafts | ❌ | ✅ | ❌ | ✅ |
| Engagement Stats | ✅ | ❌ | ❌ | ❌ |
| Original Dates | ✅ | ✅ | ✅ | ✅ |
| Categories/Tags | ✅ (hashtags) | ✅ (tags) | ✅ (hashtags) | ✅ (labels) |

---

## AI Capabilities

### Content Analysis

The AI analyzes imported content to understand:

- **Content Types:** What kinds of content exist (articles, quick thoughts, announcements, etc.)
- **Topics & Themes:** Main subjects covered across the content
- **Writing Style:** Tone, formality, typical length
- **Patterns:** Recurring hashtags, posting habits, content series
- **Quality Signals:** Which content has high engagement or represents best work

**Example Output:**
```json
{
  "content_types": {
    "long_form": 45,
    "quick_thoughts": 892,
    "announcements": 67,
    "conversations": 234
  },
  "top_topics": ["wordpress", "web development", "javascript", "open source"],
  "writing_style": "technical but accessible, often includes code snippets",
  "suggested_categories": ["Development", "WordPress", "Tutorials", "Thoughts"],
  "high_value_content": ["tweet_123", "thread_456", "tweet_789"]
}
```

### Mapping Suggestions

Based on content analysis and destination site structure, AI suggests:

- **Post Type Mapping:** Which WordPress post type for each content type
- **Taxonomy Mapping:** How source tags/categories map to WordPress taxonomies
- **Field Mapping:** Which custom fields to populate (if ACF/etc. present)
- **Content Transformation:** How to handle platform-specific content (threads, carousels, etc.)

**Example Suggestion:**
```
"Your site has a 'Tutorials' custom post type with 'Technology' and 'Difficulty'
taxonomies. I found 23 thread tutorials in your Twitter archive. I recommend:

- Import tutorial threads as 'Tutorials' post type
- Map hashtags like #javascript, #php to 'Technology' taxonomy
- Use engagement metrics to set 'Difficulty' (high engagement = beginner-friendly)
- Other tweets go to standard 'Posts'"
```

### Enhancement Tasks

| Enhancement | Description | API Cost |
|-------------|-------------|----------|
| Alt Text Generation | Describe images for accessibility | 1 call/image |
| Thread Stitching | Combine thread tweets into cohesive article | 1 call/thread |
| Title Generation | Create titles for untitled content | 1 call/item |
| Excerpt Generation | Create summaries for long content | 1 call/item |
| SEO Meta | Generate meta descriptions | 1 call/item |
| Content Expansion | Expand short posts into articles | 1 call/item |
| Hashtag Mapping | Intelligently map hashtags to terms | 1 call/batch |

### AI Provider Support

Provider selection, credentials, and routing are owned by WordPress core via the [Connectors API](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/) (WordPress 7.0+). The plugin calls `wp_get_ai_client()` and uses whichever provider the site administrator has configured under **Settings → Connections** — it never asks for, stores, or manages API keys itself.

### Token Estimation

Before import, display estimated AI token usage so users can size the run against their connector's quota or pricing:

```
Estimated AI Token Usage
────────────────────────
Alt text generation:     892 images × ~150 tokens = ~134k tokens
Thread stitching:        147 threads × ~500 tokens = ~74k tokens
SEO meta generation:     2,104 items × ~100 tokens = ~210k tokens
                                            ─────────────────────
                                            Total: ~418k tokens
```

Dollar costs are intentionally omitted — pricing depends on the connector and model the site administrator has chosen, both of which are managed by core.

---

## Security & Privacy

### Data Handling Principles

1. **Minimal Data Transmission**
   Only send content to AI provider when user explicitly requests enhancements

2. **No Third-Party Storage**
   Plugin does not send data to any servers other than user's chosen AI provider

3. **Local Processing**
   All parsing, normalization, and WordPress operations happen locally

4. **Transparent AI Usage**
   Clear indication of what data is sent to AI and why

5. **No Plugin-Held Credentials**
   AI provider credentials are stored and managed by WordPress core's Connectors API. The plugin never sees, stores, or transmits API keys.

### Security Measures

| Concern | Mitigation |
|---------|------------|
| AI Provider Credentials | Owned by WordPress core's Connectors API; the plugin never handles them |
| File Uploads | Validated, scanned, stored in protected directory |
| OAuth Tokens | Short-lived, stored encrypted, refreshed as needed |
| XSS in Imported Content | All content sanitized via wp_kses on import |
| CSRF | WordPress nonces on all admin actions |
| SQL Injection | Prepared statements for all database operations |
| Capability Checks | `manage_options` required for all operations |

### Privacy Considerations

**Data Sent to AI Provider:**
- Content text (when enhancements enabled)
- Image URLs (for alt text generation)
- Site structure summary (for mapping suggestions)

**Data NOT Sent:**
- User credentials
- Full media files (only URLs)
- Other site content
- Personal information

**User Consent:**
- Clear explanation before any AI processing
- Opt-in for each enhancement type
- Option to skip all AI features

### Compliance

- **GDPR:** No personal data stored externally; clear data handling disclosure
- **CCPA:** No data selling; user controls all data
- **WordPress.org Guidelines:** Compliant with plugin directory requirements

---

## Performance Requirements

### Scalability Targets

| Metric | Target | Notes |
|--------|--------|-------|
| Max items per import | 50,000 | Twitter power users |
| Max file upload size | 500MB | Large archives |
| Import throughput | 100 items/minute | With background processing |
| Media sideload | 10 images/minute | Respecting remote server limits |
| Memory usage | <128MB | Within typical WP limits |
| Background job duration | <30 seconds | Per batch, to avoid timeouts |

### Performance Strategies

1. **Streaming Parsing**
   Parse large JSON/XML files without loading entirely into memory

2. **Lazy Loading**
   Only fetch full content items when needed, not during manifest generation

3. **Batch Processing**
   Process items in batches of 10-50 via Action Scheduler

4. **Progressive Enhancement**
   AI enhancements processed in separate background pass

5. **Caching**
   - Manifest cached for session
   - AI analysis cached by content hash
   - Destination schema cached with invalidation

6. **Optimized Media Handling**
   - Parallel downloads where possible
   - Skip already-existing media (by hash)
   - Generate thumbnails asynchronously

### Timeout Prevention

```php
// Each background job handles a small batch
add_action( 'ai_importer_process_batch', function( $batch_id ) {
    $batch_size = 25; // Items per job
    $time_limit = 25; // Seconds before scheduling next job

    $start = time();
    $processed = 0;

    while ( $processed < $batch_size && ( time() - $start ) < $time_limit ) {
        // Process next item
        $processed++;
    }

    if ( $remaining > 0 ) {
        // Schedule continuation
        as_enqueue_async_action( 'ai_importer_process_batch', [ $batch_id ] );
    }
});
```

---

## Release Strategy

### Version Roadmap

```
v0.1.0 - Alpha (Internal)
├── Plugin foundation
├── Twitter archive adapter
├── Basic import flow
└── Core AI integration

v0.5.0 - Beta (Limited Testing)
├── Medium, Instagram, Blogger adapters
├── Full mapping studio
├── All MVP enhancements
└── Background processing

v1.0.0 - Public Release
├── Polished UI
├── Documentation
├── WordPress.org submission
└── Marketing launch

v1.1.0 - Feature Update
├── Tumblr, YouTube, Substack, Ghost adapters
├── Advanced enhancements
├── Incremental imports
└── Import history/rollback

v2.0.0 - Major Update
├── Additional platforms
├── REST API
├── WP-CLI commands
└── Custom adapter SDK
```

### Release Criteria

**Alpha (v0.1):**
- [ ] Core architecture implemented
- [ ] Twitter adapter functional
- [ ] Basic import completes successfully
- [ ] No critical bugs

**Beta (v0.5):**
- [ ] All MVP adapters functional
- [ ] UI complete and usable
- [ ] AI features working
- [ ] Performance acceptable
- [ ] No blocking bugs

**Public Release (v1.0):**
- [ ] All MVP features complete
- [ ] Documentation complete
- [ ] 90%+ unit test coverage
- [ ] E2E tests passing
- [ ] Security audit passed
- [ ] Performance benchmarks met
- [ ] Accessibility audit passed
- [ ] WordPress.org guidelines met

### Distribution

**Primary:** WordPress.org Plugin Directory
- Free, open-source
- Standard WP update mechanism

**Secondary:** GitHub
- Development releases
- Issue tracking
- Contributor access

---

## Success Metrics

### Key Performance Indicators

| Metric | Target (Year 1) | Measurement |
|--------|-----------------|-------------|
| Active Installs | 10,000 | WordPress.org stats |
| Items Imported | 1,000,000 | Aggregate telemetry (opt-in) |
| Import Success Rate | >95% | Error tracking |
| User Satisfaction | >4.5 stars | WordPress.org reviews |
| Support Response Time | <24 hours | Support forum tracking |

### Usage Metrics (Opt-in Telemetry)

- Adapters used (popularity ranking)
- Average items per import
- Enhancement feature usage
- Error rates by adapter
- Background vs. foreground import ratio

### Quality Metrics

- Bug reports per release
- Time to fix critical bugs
- Test coverage percentage
- Code quality scores (PHPStan level)

### Community Metrics

- GitHub stars
- Contributors
- Third-party adapter submissions
- Documentation contributions

---

## Risks & Mitigations

### Technical Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Platform API changes | High | High | Abstract adapters, monitor changes, quick updates |
| AI API rate limits | Medium | Medium | Batching, caching, user warnings |
| Large import timeouts | Medium | High | Background processing, chunking |
| Memory exhaustion | Medium | High | Streaming parsing, batch limits |
| WordPress compatibility | Low | High | Test against multiple versions |

### Business Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Platform export restrictions | Medium | High | Prioritize file uploads over APIs |
| AI provider pricing changes | Medium | Medium | Provider choice is owned by core's Connectors API; users can switch connectors without plugin changes |
| WordPress.org rejection | Low | High | Follow guidelines strictly, pre-review |
| Competition | Medium | Low | Focus on AI differentiation |

### User Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Data loss during import | Low | Critical | Dry-run mode, rollback, no source deletion |
| API key exposure | Low | High | Plugin never handles AI keys — credentials live in core's Connectors API |
| Unexpected AI usage | Medium | Medium | Token estimate shown before import; cost depends on the connector chosen in core |
| Content quality issues | Medium | Medium | Review queue, easy editing |

---

## Appendices

### Appendix A: Glossary

| Term | Definition |
|------|------------|
| Adapter | Platform-specific module for extracting content |
| Manifest | Inventory of available content from a source |
| Normalization | Converting platform-specific format to universal schema |
| Sideloading | Downloading remote media to WordPress media library |
| Stitching | Combining thread/series into single post |
| Mapping | Configuration of how source content becomes WordPress content |

### Appendix B: Competitive Analysis

| Product | Platforms | AI Features | Pricing | Limitations |
|---------|-----------|-------------|---------|-------------|
| Native WP Importer | Blogger, RSS | None | Free | Basic, manual |
| Social Import (plugin) | Twitter, FB | None | $49 | Outdated, limited |
| Jetvantage | Multiple | None | $99/yr | No AI, complex UI |
| **AI Importer** | Universal | Full AI | Free | Requires WordPress 7.0+ with an AI connector configured |

### Appendix C: User Research Summary

**Research Conducted:** January 2026
**Participants:** 12 WordPress site owners
**Method:** Semi-structured interviews

**Key Findings:**
1. 100% had content on multiple platforms they wanted to consolidate
2. 83% had tried and failed to migrate content before
3. 67% cited "too complicated" as primary barrier
4. 92% interested in AI-assisted migration
5. Top requested sources: Twitter (75%), Instagram (58%), Medium (42%)

**Quote Highlights:**
> "I have 10 years of tweets that represent my professional journey. I'd pay money to have them organized on my blog."

> "The WordPress importer gave me 500 posts with broken images and wrong dates. I gave up."

> "If AI could turn my tweet threads into actual blog posts, that would be amazing."

### Appendix D: Technical Specifications

**Minimum Requirements:**
- WordPress 7.0+
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- 128MB PHP memory limit

**Recommended:**
- WordPress 7.0+
- PHP 8.2+
- 256MB PHP memory limit
- WP-Cron or real cron configured
- SSL certificate (required for OAuth)

**Browser Support:**
- Chrome 90+
- Firefox 90+
- Safari 15+
- Edge 90+

### Appendix E: References

- WordPress Plugin Developer Handbook
- WordPress AI API Documentation
- Twitter Data Archive Documentation
- Instagram Data Download Documentation
- Medium Export Documentation
- Blogger API v3 Documentation
- Action Scheduler Documentation

---

**Document Control**

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-05 | Adam Silverstein | Initial PRD |

---

*This document is a living specification and will be updated as the product evolves.*
