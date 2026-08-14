<?php
/**
 * Plugin Name: Free AI Translator for WPML
 * Plugin URI: https://github.com/olympusdigital/free-ai-translator-for-wpml
 * Description: Supplements WPML by translating your Gutenberg (block editor) and classic content into ANY of your active WPML languages — Spanish, French, German, Arabic, Japanese and more — with AI (Google Gemini), using your own API key at Google's raw API price — no translation credits. Creates real, WPML-linked drafts a human reviews and publishes, with a cost preview before every run.
 * Version: 1.0.0
 * Author: Olympus Digital
 * Author URI: https://ko-fi.com/olympusdigital
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: free-ai-translator-for-wpml
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * WHY THIS EXISTS
 * WPML's own Automatic Translation is metered in WPML credits, marked up
 * over the underlying engine's real API cost. This plugin calls Gemini
 * directly (bring-your-own key), so translation costs whatever Google
 * actually charges — no markup, no credit purchases.
 *
 * HOW IT WORKS
 * For each source-language post, the plugin extracts every piece of
 * translatable copy (plain-block innerHTML plus custom-block attributes per
 * the active theme's and active plugins' wpml-config.xml files), translates
 * it in one chunked Gemini run, and creates a target-language DRAFT linked
 * into the same WPML translation group as the source. Review happens in the
 * normal WordPress editor on the real draft; publishing is WordPress's own
 * Publish button.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FATW_VERSION', '1.0.0' );
define( 'FATW_FILE', __FILE__ );
define( 'FATW_DIR', plugin_dir_path( __FILE__ ) );
define( 'FATW_URL', plugin_dir_url( __FILE__ ) );

/**
 * Without WPML there is no translation group to link drafts into.
 * Check up front and say so, rather than failing confusingly mid-run.
 */
function fatw_wpml_missing(): bool {
	return ! defined( 'ICL_SITEPRESS_VERSION' );
}

add_action(
	'admin_notices',
	function () {
		if ( fatw_wpml_missing() && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p><strong>Free AI Translator for WPML</strong> requires the WPML plugin (sitepress-multilingual-cms) to be active — it links every translation into WPML\'s language groups.</p></div>';
		}
	}
);

require_once FATW_DIR . 'includes/class-fatw-settings.php';
require_once FATW_DIR . 'includes/class-fatw-estimator.php';
require_once FATW_DIR . 'includes/class-fatw-gemini.php';
require_once FATW_DIR . 'includes/class-fatw-log.php';
require_once FATW_DIR . 'includes/class-fatw-translator.php';
require_once FATW_DIR . 'includes/class-fatw-admin-page.php';

register_activation_hook(
	__FILE__,
	function () {
		FATW_Log::create_table();
	}
);

// Re-run table creation when the plugin version changes, not only on
// activation: activation hooks don't re-fire on a plain file update, so an
// existing install would otherwise never pick up schema changes. dbDelta()
// is a no-op when nothing changed.
add_action(
	'admin_init',
	function () {
		if ( get_option( 'fatw_schema_version' ) !== FATW_VERSION ) {
			FATW_Log::create_table();
			update_option( 'fatw_schema_version', FATW_VERSION );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( fatw_wpml_missing() ) {
			return;
		}
		FATW_Settings::init();
		FATW_Admin_Page::init();
	}
);
