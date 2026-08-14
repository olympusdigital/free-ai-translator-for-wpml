<?php
/**
 * Cost estimation, shown to the user BEFORE any API call is made.
 *
 * Token counts are approximated from character count (roughly 4 characters
 * per token for Latin-script languages — Gemini doesn't expose a free,
 * no-auth tokenizer endpoint, so this is the same rule-of-thumb every
 * provider's own docs quote for ballpark estimates). It will not match
 * Gemini's billed token count exactly; treat it as an estimate for quoting,
 * not an invoice.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Estimator {

	/** Rule-of-thumb characters per token for Latin-script source text. */
	const CHARS_PER_TOKEN = 4;

	/**
	 * @param string $text Plain or HTML text.
	 * @return int Approximate token count.
	 */
	public static function estimate_tokens( string $text ): int {
		$len = strlen( $text ); // Byte length is a closer proxy than char count for multi-byte scripts.
		return (int) ceil( $len / self::CHARS_PER_TOKEN );
	}

	/**
	 * Estimate the cost of translating a set of strings.
	 *
	 * Assumes output length is roughly equal to input length — reasonable
	 * for translation (unlike summarisation or expansion tasks), and errs
	 * slightly high rather than low since many target languages run
	 * ~15-20% longer than English for the same meaning.
	 *
	 * @param array{value:string}[] $strings   Objects/arrays each carrying a 'value' string.
	 * @param string                $model_id  For display only.
	 * @param float                 $price_in  USD per 1,000,000 input tokens.
	 * @param float                 $price_out USD per 1,000,000 output tokens.
	 * @return array{
	 *   string_count:int, word_count:int, input_tokens:int, output_tokens:int,
	 *   cost_usd:float, model_id:string
	 * }
	 */
	public static function estimate( array $strings, string $model_id, float $price_in, float $price_out ): array {
		$word_count   = 0;
		$input_tokens = 0;

		foreach ( $strings as $s ) {
			$value         = is_object( $s ) ? $s->value : (string) $s['value'];
			$word_count   += str_word_count( wp_strip_all_tags( $value ) );
			$input_tokens += self::estimate_tokens( $value );
		}

		// Output ~= input length for a translation task, plus the prompt/
		// instruction overhead per call is negligible at batch scale.
		$output_tokens_est = (int) round( $input_tokens * 1.2 );

		$cost = ( $input_tokens / 1_000_000 * $price_in ) + ( $output_tokens_est / 1_000_000 * $price_out );

		return array(
			'string_count'  => count( $strings ),
			'word_count'    => $word_count,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens_est,
			'cost_usd'      => round( $cost, 4 ),
			'model_id'      => $model_id,
		);
	}
}
