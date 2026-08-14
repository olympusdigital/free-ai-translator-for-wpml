# Free AI Translator for WPML

Translate WPML content with AI at **raw Google Gemini API prices** — your own key, no translation credits, no markup.

WPML's Automatic Translation is metered in credits, marked up over the real engine cost — and WPML doesn't let you plug in your own API key. This plugin does: it calls Gemini directly with **your** key, so translation costs exactly what Google charges. Typical pages cost fractions of a cent.

## Features
- 💰 **Cost preview before you spend** — see estimated segments, words, and dollar cost before a single token is sent
- 🧾 **Real cost accounting** — the History tab logs Gemini's *actual reported token usage* per run, not estimates
- ✍️ **Human review by design** — every translation is a WPML-linked draft; publishing is WordPress's own Publish button
- 🧱 **Built for the block editor (Gutenberg)** — plain blocks, plus custom/dynamic block attributes via your theme's and plugins' `wpml-config.xml`, including copy stored only as `block.json` defaults
- 🌍 **Any WPML language pair** — source is your default language, target is any active WPML language
- 🔒 **Private** — content goes from your server straight to Google with your key; nothing touches any third party

## Requirements

- WordPress 6.2+, PHP 7.4+
- [WPML](https://wpml.org/) (Multilingual CMS) with at least one secondary language
- A free [Google AI Studio](https://aistudio.google.com/apikey) API key — Google's free tier means many small sites pay nothing

## Installation

1. Download the latest release zip and install via Plugins → Add New → Upload
2. Go to **AI Translator → Settings**, paste your Gemini key, pick a model
3. On the **Translate** tab: choose a target language, select content, **Preview Cost**, translate
4. Review each draft in the editor and publish it yourself

## Page builders (Elementor, Divi, etc.)

Not supported yet — builder pages are auto-detected and clearly labeled so you never spend an API call on one. **Elementor support is on the roadmap — watch/star this repo to get notified.**

## Support this project

Free and GPL, always. If it saves you real credit money: [buy me a coffee](https://ko-fi.com/olympusdigital) ☕

## License

GPLv2 or later