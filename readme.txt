=== AI Importer ===
Contributors: adamsilverstein
Tags: import, migration, social media, twitter, instagram, medium, ai
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import content from social media platforms into WordPress using AI-powered analysis and mapping.

== Description ==

AI Importer enables you to migrate your content from any major social media platform or content repository into WordPress. Using AI-powered analysis and mapping, the plugin intelligently transforms platform-specific content into well-structured WordPress posts while preserving metadata, relationships, and media.

**Supported Platforms:**

* Twitter/X (archive upload)
* Instagram (data download upload)
* Medium (export file upload)
* Blogger (OAuth or XML upload)

**Key Features:**

* **Universal Import** - One tool for all your content sources
* **AI-Powered Mapping** - Intelligent suggestions for content organization
* **Content Enhancement** - Generate alt text, stitch threads, optimize for SEO
* **Background Processing** - Handle large imports without timeouts
* **Rollback Support** - Undo imports if needed

**Requirements:**

* WordPress 6.4 or higher
* PHP 8.1 or higher
* AI Experiments plugin (for AI features)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-importer`, or install through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Install and configure the AI Experiments plugin with your preferred AI provider.
4. Navigate to AI Importer in the admin menu to start importing content.

== Frequently Asked Questions ==

= What AI providers are supported? =

AI Importer uses the AI Experiments plugin for AI functionality. Supported providers include Anthropic (Claude), OpenAI (GPT-4), and Google (Gemini).

= Do I need an AI API key? =

Yes, you need an API key from a supported AI provider (Anthropic, OpenAI, or Google) configured in the AI Experiments plugin.

= Can I import without using AI features? =

Yes, you can perform basic imports without AI. The AI features (smart mapping, alt text generation, etc.) are optional enhancements.

= How do I get my data from Twitter/Instagram/etc? =

Each platform provides a way to download your data archive:
* Twitter: Settings > Your Account > Download an archive of your data
* Instagram: Settings > Privacy and Security > Download Data
* Medium: Settings > Security and apps > Download your information

== Screenshots ==

1. Dashboard overview
2. Source selection screen
3. Content preview and mapping
4. Import progress

== Changelog ==

= 0.1.0 =
* Initial alpha release
* Plugin scaffolding and project setup

== Upgrade Notice ==

= 0.1.0 =
Initial release.
