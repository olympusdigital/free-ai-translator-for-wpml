<?php
/**
 * Settings: API key, model choice, per-model token pricing, and optional
 * site context for the translation prompt.
 *
 * Pricing is stored as editable numbers rather than hardcoded, on purpose —
 * AI provider pricing changes often, and a cost preview built on stale
 * hardcoded prices would quietly become wrong. Defaults here are a starting
 * point, not a promise; check them against Google's current pricing page
 * periodically.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Settings {

	const OPTION = 'fatw_settings';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Default pricing, USD per 1,000,000 tokens, as of when this was written.
	 * VERIFY AGAINST https://ai.google.dev/gemini-api/docs/pricing BEFORE
	 * relying on a cost estimate for a real quote — this list will go stale.
	 *
	 * @return array<string, array{label:string, input:float, output:float}>
	 */
	public static function model_price_table(): array {
		return array(
			'gemini-3.1-flash-lite' => array( 'label' => 'Gemini 3.1 Flash-Lite (cheapest)', 'input' => 0.10, 'output' => 0.40 ),
			'gemini-2.5-flash'      => array( 'label' => 'Gemini 2.5 Flash', 'input' => 0.30, 'output' => 2.50 ),
			'gemini-3.5-flash'      => array( 'label' => 'Gemini 3.5 Flash', 'input' => 1.50, 'output' => 9.00 ),
			'gemini-2.5-pro'        => array( 'label' => 'Gemini 2.5 Pro (highest quality)', 'input' => 1.25, 'output' => 10.00 ),
			'custom'                => array( 'label' => 'Custom model / price', 'input' => 0.0, 'output' => 0.0 ),
		);
	}

	/**
	 * @return array Current settings, merged over defaults so a fresh install
	 *               has sane values without an activation-time writer.
	 */
	public static function get(): array {
		$defaults = array(
			'api_key'         => '',
			'model'           => 'gemini-2.5-flash',
			'custom_model_id' => '',
			'custom_input'    => 0.0,
			'custom_output'   => 0.0,
			'site_context'    => '',
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Optional free-text description of the site, injected into the
	 * translation prompt so the model matches tone and domain terminology
	 * (e.g. "a personal injury law firm's marketing site — formal tone").
	 *
	 * @return string
	 */
	public static function site_context(): string {
		$s = self::get();
		return trim( (string) $s['site_context'] );
	}

	/**
	 * Resolve the effective model ID and per-1M-token prices to use right now.
	 *
	 * @return array{model_id:string, input:float, output:float}
	 */
	public static function resolve_model(): array {
		$s     = self::get();
		$table = self::model_price_table();

		if ( 'custom' === $s['model'] ) {
			return array(
				'model_id' => $s['custom_model_id'] ?: 'gemini-2.5-flash',
				'input'    => (float) $s['custom_input'],
				'output'   => (float) $s['custom_output'],
			);
		}

		$row = $table[ $s['model'] ] ?? $table['gemini-2.5-flash'];
		return array(
			'model_id' => $s['model'],
			'input'    => (float) $row['input'],
			'output'   => (float) $row['output'],
		);
	}

	/**
	 * API key resolution order: a wp-config.php constant first (recommended —
	 * keeps the key out of the database entirely), the stored option second.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( defined( 'FATW_GEMINI_API_KEY' ) && FATW_GEMINI_API_KEY ) {
			return FATW_GEMINI_API_KEY;
		}
		$s = self::get();
		return (string) $s['api_key'];
	}

	public static function register(): void {
		register_setting( 'fatw', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * @param array $input Raw POSTed settings.
	 * @return array Sanitised settings.
	 */
	public static function sanitize( $input ): array {
		$table = self::model_price_table();
		$model = isset( $input['model'] ) && isset( $table[ $input['model'] ] ) ? $input['model'] : 'gemini-2.5-flash';

		return array(
			'api_key'         => isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '',
			'model'           => $model,
			'custom_model_id' => isset( $input['custom_model_id'] ) ? sanitize_text_field( $input['custom_model_id'] ) : '',
			'custom_input'    => isset( $input['custom_input'] ) ? (float) $input['custom_input'] : 0.0,
			'custom_output'   => isset( $input['custom_output'] ) ? (float) $input['custom_output'] : 0.0,
			'site_context'    => isset( $input['site_context'] ) ? sanitize_textarea_field( (string) $input['site_context'] ) : '',
		);
	}
}
