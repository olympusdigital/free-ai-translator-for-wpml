<?php
/**
 * Translation run history — one row per translated post.
 *
 * Permanent, append-only record of what was sent to Gemini and what it
 * actually cost (from Gemini's own reported token usage, not the pre-call
 * estimate). The answer to "how much have we spent, and on what" survives
 * regardless of what later happens to any post.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Log {

	/**
	 * @return string Table name, with the site's actual prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fatw_log';
	}

	/**
	 * Create the log table. Idempotent — dbDelta() diffs against what's
	 * already there, so re-running on plugin update is safe.
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			target_post_id BIGINT UNSIGNED NULL DEFAULT NULL,
			target_lang VARCHAR(10) NOT NULL,
			string_count INT UNSIGNED NOT NULL DEFAULT 0,
			input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			cost_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
			model VARCHAR(80) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Record one completed translation run.
	 *
	 * @param int    $post_id        Source post.
	 * @param int    $target_post_id Newly created translated post.
	 * @param string $target_lang    WPML language code.
	 * @param int    $string_count   Text segments sent in this run.
	 * @param int    $input_tokens   From Gemini's usageMetadata.
	 * @param int    $output_tokens  Same.
	 * @param float  $cost_usd       Computed cost for this run.
	 * @param string $model          Model ID used.
	 * @return int Log row ID.
	 */
	public static function record( int $post_id, int $target_post_id, string $target_lang, int $string_count, int $input_tokens, int $output_tokens, float $cost_usd, string $model ): int {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'post_id'        => $post_id,
				'target_post_id' => $target_post_id,
				'target_lang'    => $target_lang,
				'string_count'   => $string_count,
				'input_tokens'   => $input_tokens,
				'output_tokens'  => $output_tokens,
				'cost_usd'       => $cost_usd,
				'model'          => $model,
				'created_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{pages:int, strings:int, cost:float, runs:int} Lifetime totals.
	 */
	public static function totals(): array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			"SELECT COUNT(DISTINCT post_id) AS pages, COUNT(*) AS runs, COALESCE(SUM(string_count),0) AS strings, COALESCE(SUM(cost_usd),0) AS cost
			 FROM {$table}"
		);

		return array(
			'pages'   => (int) ( $row->pages ?? 0 ),
			'runs'    => (int) ( $row->runs ?? 0 ),
			'strings' => (int) ( $row->strings ?? 0 ),
			'cost'    => (float) ( $row->cost ?? 0 ),
		);
	}

	/**
	 * Most recent runs, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public static function recent( int $limit = 25 ): array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);
	}
}
