=== Free AI Translator for WPML ===
Contributors: olympusdigital
Donate link: https://ko-fi.com/olympusdigital
Tags: wpml, translation, ai, multilingual, gemini
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WPML block editor (Gutenberg) content with AI at raw Gemini API prices — your own key, no credits, cost preview, human review of every draft.

== Description ==

WPML's Automatic Translation is metered in WPML credits, marked up over the underlying engine's real API cost — and WPML does not let you plug in your own API key.

**Free AI Translator for WPML** supplements WPML by calling Google Gemini directly with *your own* API key. Translation costs exactly what Google charges — typically a fraction of a cent per page — with no markup and no credit purchases.

= How it works =

1. Pick a target language (any of your active WPML languages).
2. Select the pages, posts, or custom post type items you want translated.
3. Click **Preview Cost** — see estimated segments, words, and dollar cost *before* anything is sent.
4. Click **Translate Selected**. Each item becomes a real, WPML-linked **draft** in the target language.
5. Review each draft in the normal WordPress editor and press Publish yourself. Nothing goes live automatically.

= What makes it different =

* **Cost preview before you spend.** Know the estimated API cost of a batch before a single token is sent.
* **Real cost accounting.** The History tab logs Gemini's *actual reported token usage* per run — not an estimate — so you always know what you've spent, and on what.
* **Human-in-the-loop by design.** Every translation is a draft. Publishing is WordPress's own Publish button.
* **Built for the block editor (Gutenberg) — and thorough about it.** Plain blocks are translated via innerHTML. Custom/dynamic blocks (whose copy lives in JSON attributes) are handled by parsing the wpml-config.xml files of your active theme and plugins — the same files WPML itself honors — including copy stored only as block.json defaults that other tools silently skip. Classic editor content is supported too. Page builder pages (Elementor, Divi, WPBakery, Beaver Builder) are automatically detected and clearly marked as not yet supported, so you never spend an API call on one.
* **Consistent terminology.** Whole pages are translated in batched calls so the model keeps the same phrasing for recurring terms.
* **Safe by default.** Refuses to duplicate an existing translation, refuses to translate non-source-language content, aborts the whole run (writing nothing) if any chunk fails, and keeps HTML tags, numbers, and phone numbers intact.

= Requirements =

* Content built with the WordPress block editor (Gutenberg) or the classic editor. Page builder pages (Elementor, Divi, etc.) are not yet supported — see the FAQ.
* WPML (Multilingual CMS) active, with at least one secondary language configured.
* A free Google AI Studio API key (Gemini). For production, define `FATW_GEMINI_API_KEY` in wp-config.php to keep the key out of the database.

= Is it really free? =

Yes. The plugin is free and GPL. You pay only your own Google Gemini API usage, at Google's published prices. If it saves you real money on translation credits, consider a small donation to support development.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via Plugins → Add New.
2. Activate it. WPML must already be active.
3. Go to **AI Translator → Settings**, paste your Gemini API key, and pick a model.
4. Go to the **Translate** tab, choose a target language, select content, preview the cost, and translate.

== Frequently Asked Questions ==

= Does this replace WPML? =

No. It supplements WPML. WPML still manages languages, URLs, hreflang, and the language switcher. This plugin only replaces the *automatic translation* step, creating drafts linked into WPML's own translation groups.

= Which languages are supported? =

Any language pair your WPML installation has configured. The source is your WPML default language; the target is any active secondary language.

= Will it publish translations automatically? =

Never. Every translation is created as a draft. A human reviews it in the editor and publishes it.

= Does it work with page builders like Elementor? =

Not yet — this version supports block editor (Gutenberg) and classic editor content. That includes third-party block libraries (Kadence, Spectra, GenerateBlocks, custom theme blocks and so on), with translatable attributes read from each plugin's or theme's wpml-config.xml. Pages built with Elementor, Divi, WPBakery, or Beaver Builder store their content outside post_content, so the plugin detects them, labels them "Page builder — coming soon" in the list, and will not let you spend an API call on them. Builder support (starting with Elementor) is on the roadmap — follow the GitHub repository to get notified.

= What does translation actually cost? =

Whatever Google charges for the model you pick — the Settings tab ships with editable per-model pricing and the Translate tab shows a per-batch estimate before you run. Typical pages cost fractions of a cent on Flash-class models.

= Is my content sent anywhere besides Google? =

No. Content goes directly from your server to the Google Gemini API using your key. There is no middleman service and no telemetry.

== External Services ==

This plugin connects to the Google Gemini API (https://generativelanguage.googleapis.com) to perform translations. This is required for the plugin's core function: when you click "Translate Selected", the text content of the posts you selected (titles, excerpts, and block text) is sent to Google's Gemini API, together with your own API key, and translated text is returned. Nothing is sent until you explicitly start a translation; the "Preview Cost" feature is calculated locally and sends nothing.

No data is ever sent to the plugin author or any other third party. Your API key is stored on your own site (or in wp-config.php) and is transmitted only to Google.

The Gemini API is provided by Google LLC:
* Terms of service: https://ai.google.dev/gemini-api/terms
* Privacy policy: https://policies.google.com/privacy

== Screenshots ==

1. Translate tab — target language picker, per-row translation status, bulk selection.
2. Cost preview before any API call is made.
3. Live per-row progress during a bulk run.
4. History & Costs — lifetime totals and per-run actual token usage.
5. Settings — API key, model, editable pricing, site context.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Page-builder detection: Elementor, Divi, WPBakery, and Beaver Builder pages are identified and excluded with a clear "coming soon" label, so no API cost is ever spent on unsupported content.
* Universal language support: translate from your WPML default language into any active WPML language.
* Auto-detects WPML-translatable public post types.
* Parses wpml-config.xml from the active theme, parent theme, and all active plugins.
* Optional "site context" setting to steer tone and terminology.
* Cost preview, chunked batching, one automatic retry on transient API errors, full-run abort on failure.
* History log records Gemini's actual reported token usage and cost per run.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
