<?php
/**
 * Gemini API client.
 *
 * Sends a batch of strings in ONE request rather than one call per string —
 * fewer round trips, and the model keeps terminology consistent across the
 * batch (the same target-language phrasing for a recurring term
 * throughout). Uses Gemini's structured-output mode (responseSchema) so the
 * reply is a JSON array WP can parse directly.
 *
 * Robustness: transient failures (network errors, HTTP 429/5xx, malformed
 * or count-mismatched responses) get ONE automatic retry before the error
 * surfaces to the caller. Permanent failures (bad API key, invalid model)
 * fail immediately. Callers chunk long batches (see
 * FATW_Translator::CHUNK_SIZE) so no single response risks output-token
 * truncation.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Gemini {

	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/** Seconds. Long batches can take a while to generate. */
	const TIMEOUT = 120;

	/**
	 * Translate a batch of strings in one API call (with one retry on
	 * transient failure).
	 *
	 * @param string[] $texts       Source strings, in order.
	 * @param string   $source_name Human-readable source language name (e.g. 'English').
	 * @param string   $target_name Human-readable target language name (e.g. 'Spanish').
	 * @param string   $model_id    Gemini model ID, e.g. 'gemini-2.5-flash'.
	 * @return array|WP_Error On success: array{
	 *   translations: string[]  (same order as $texts),
	 *   usage: array{input_tokens:int, output_tokens:int}  (Gemini's actual
	 *          reported usage, not an estimate)
	 * }. WP_Error on failure.
	 */
	public static function translate_batch( array $texts, string $source_name, string $target_name, string $model_id ) {
		if ( '' === FATW_Settings::api_key() ) {
			return new WP_Error( 'fatw_no_key', __( 'No Gemini API key configured.', 'free-ai-translator-for-wpml' ) );
		}
		if ( empty( $texts ) ) {
			return array(
				'translations' => array(),
				'usage'        => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
			);
		}

		$last_error = null;
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$result = self::attempt( $texts, $source_name, $target_name, $model_id );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
			// Only transient errors earn a retry; a bad key won't get better.
			if ( ! in_array( $result->get_error_code(), array( 'fatw_transient', 'fatw_bad_response', 'fatw_count_mismatch' ), true ) ) {
				return $result;
			}
			if ( 1 === $attempt ) {
				sleep( 2 );
			}
		}
		return $last_error;
	}

	/**
	 * One request/parse cycle. Separated from translate_batch() so the
	 * retry loop stays readable.
	 *
	 * @param string[] $texts       Source strings.
	 * @param string   $source_name Human-readable source language name.
	 * @param string   $target_name Human-readable target language name.
	 * @param string   $model_id    Gemini model ID.
	 * @return array|WP_Error Same success shape as translate_batch().
	 */
	private static function attempt( array $texts, string $source_name, string $target_name, string $model_id ) {
		$body = array(
			'contents'         => array(
				array( 'parts' => array( array( 'text' => self::build_prompt( $texts, $source_name, $target_name ) ) ) ),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'STRING' ),
				),
				'temperature'      => 0.2, // Low: consistent terminology over creative variation.
			),
		);

		$url = self::API_BASE . rawurlencode( $model_id ) . ':generateContent?key=' . rawurlencode( FATW_Settings::api_key() );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => self::TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fatw_transient', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( $raw, true );
			$message = $decoded['error']['message'] ?? $raw;
			$err_id  = ( 429 === $code || $code >= 500 ) ? 'fatw_transient' : 'fatw_api_error';
			return new WP_Error( $err_id, sprintf( 'Gemini API error (%d): %s', $code, $message ) );
		}

		$decoded = json_decode( $raw, true );
		$text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( null === $text ) {
			return new WP_Error( 'fatw_bad_response', __( 'Gemini returned an unexpected response shape.', 'free-ai-translator-for-wpml' ) );
		}

		$translated = json_decode( $text, true );

		if ( ! is_array( $translated ) || count( $translated ) !== count( $texts ) ) {
			return new WP_Error(
				'fatw_count_mismatch',
				sprintf(
					/* translators: 1: expected count, 2: received count */
					__( 'Gemini returned %2$d translated strings, expected %1$d — refusing to guess which is which.', 'free-ai-translator-for-wpml' ),
					count( $texts ),
					is_array( $translated ) ? count( $translated ) : 0
				)
			);
		}

		// usageMetadata is Gemini's own accounting of what this call cost,
		// in tokens. Falls back to the estimator if a future API response
		// ever omits it, rather than logging a hard zero.
		$usage         = $decoded['usageMetadata'] ?? array();
		$input_tokens  = isset( $usage['promptTokenCount'] ) ? (int) $usage['promptTokenCount'] : null;
		$output_tokens = isset( $usage['candidatesTokenCount'] ) ? (int) $usage['candidatesTokenCount'] : null;

		if ( null === $input_tokens || null === $output_tokens ) {
			$fallback      = FATW_Estimator::estimate(
				array_map( static fn( $v ) => (object) array( 'value' => $v ), $texts ),
				$model_id,
				0,
				0
			);
			$input_tokens  = $input_tokens ?? $fallback['input_tokens'];
			$output_tokens = $output_tokens ?? $fallback['output_tokens'];
		}

		return array(
			'translations' => array_map( 'strval', $translated ),
			'usage'        => array(
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
			),
		);
	}

	/**
	 * @param string[] $texts       Source strings.
	 * @param string   $source_name Human-readable source language name.
	 * @param string   $target_name Human-readable target language name.
	 * @return string
	 */
	private static function build_prompt( array $texts, string $source_name, string $target_name ): string {
		$context = FATW_Settings::site_context();
		$intro   = "You are translating website copy from {$source_name} to {$target_name}.";
		if ( '' !== $context ) {
			$intro .= "\nSite context (match its tone and domain terminology): {$context}";
		}

		return $intro . "\n\n"
			. "Rules:\n"
			. "- Translate naturally, in a register appropriate for a professional public website.\n"
			. "- Preserve all HTML tags exactly (e.g. <a href=\"...\">, <strong>, <br>) — translate only the text content between tags, never the tags or attribute values.\n"
			. "- Preserve numbers, currency amounts, percentages, and phone numbers exactly as written — do not convert or reformat them.\n"
			. "- Do not invent, add, or omit any factual claim. If something is ambiguous, translate it as literally as reasonable rather than guessing at intent.\n"
			. "- Return ONLY a JSON array of strings, in the exact same order as the input, with exactly one translated string per input string. Do not merge, split, or reorder items.\n\n"
			. 'Input (JSON array, ' . count( $texts ) . " items):\n"
			. wp_json_encode( array_values( $texts ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
