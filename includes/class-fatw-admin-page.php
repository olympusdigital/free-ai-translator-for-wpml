<?php
/**
 * Admin UI. Three tabs:
 *
 *  Translate — pick a target language, see one table of source-language
 *              content (filterable by post type) with each row's status in
 *              that language inline: Not translated / Draft awaiting
 *              review / Live. Check rows, preview the cost, run. Progress
 *              streams per row; finished rows flip to a "Review draft"
 *              link in place.
 *  History   — lifetime totals and recent runs, with real (not estimated)
 *              per-run cost from Gemini's reported usage.
 *  Settings  — API key, model, editable pricing, optional site context.
 *
 * There is deliberately no review queue here: review happens in the normal
 * WordPress editor, on the actual draft, where the reviewer sees the real
 * page — and publishing is WordPress's own Publish button.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Admin_Page {

	const NONCE_ACTION = 'fatw_action';
	const SLUG         = 'free-ai-translator-for-wpml';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_fatw_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_fatw_translate', array( __CLASS__, 'ajax_translate' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'AI Translator', 'free-ai-translator-for-wpml' ),
			__( 'AI Translator', 'free-ai-translator-for-wpml' ) . self::menu_badge(),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-translation',
			58
		);
	}

	/**
	 * Menu badge: drafts in any non-default language awaiting review.
	 * That's the number that represents work sitting on a human's desk.
	 *
	 * @return string
	 */
	private static function menu_badge(): string {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}icl_translations t
				 JOIN {$wpdb->posts} p ON p.ID = t.element_id
				 WHERE t.language_code <> %s AND t.element_type LIKE 'post_%%' AND p.post_status = 'draft'",
				FATW_Translator::source_lang()
			)
		);
		return $count > 0 ? " <span class=\"awaiting-mod\"><span class=\"pending-count\">{$count}</span></span>" : '';
	}

	/**
	 * Resolve and validate the currently selected target language from the
	 * URL, defaulting to the first active non-source language.
	 *
	 * @return string WPML language code, or '' if the site has no targets.
	 */
	private static function current_target(): string {
		$targets = FATW_Translator::target_languages();
		if ( empty( $targets ) ) {
			return '';
		}
		$requested = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		return isset( $targets[ $requested ] ) ? $requested : (string) array_key_first( $targets );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		$target = self::current_target();
		wp_enqueue_script( 'fatw-admin', FATW_URL . 'assets/admin.js', array( 'jquery' ), FATW_VERSION, true );
		wp_enqueue_style( 'fatw-admin', FATW_URL . 'assets/admin.css', array(), FATW_VERSION );
		wp_localize_script(
			'fatw-admin',
			'fatwData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'lang'      => $target,
				'langName'  => $target ? FATW_Translator::language_display_name( $target ) : '',
				'donateUrl' => 'https://ko-fi.com/olympusdigital',
			)
		);
	}

	// ---------------------------------------------------------------- render

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'translate';
		$target = self::current_target();
		?>
		<div class="wrap fatw">
			<h1 class="fatw-title">
				<img class="fatw-logo" src="<?php echo esc_url( FATW_URL . 'assets/logo.svg' ); ?>" alt="" width="36" height="36" />
				<?php esc_html_e( 'Free AI Translator for WPML', 'free-ai-translator-for-wpml' ); ?>
				<?php if ( $target ) : ?>
					<span class="fatw-subtitle"><?php echo esc_html( FATW_Translator::language_english_name( FATW_Translator::source_lang() ) . ' → ' . FATW_Translator::language_display_name( $target ) ); ?></span>
				<?php endif; ?>
			</h1>
			<p class="fatw-intro"><?php esc_html_e( 'Translates your block editor (Gutenberg) and classic content into any language configured in WPML — Spanish, French, German, Arabic, Japanese, and every other active language on your site — as WPML-linked drafts, using your own Gemini API key at Google\'s raw API price. Nothing goes live until you review the draft in the editor and publish it yourself.', 'free-ai-translator-for-wpml' ); ?></p>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="nav-tab <?php echo 'translate' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Translate', 'free-ai-translator-for-wpml' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=history' ) ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'History & Costs', 'free-ai-translator-for-wpml' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'free-ai-translator-for-wpml' ); ?></a>
			</h2>

			<?php
			switch ( $tab ) {
				case 'history':
					self::render_history_tab();
					break;
				case 'settings':
					self::render_settings_tab();
					break;
				default:
					self::render_translate_tab( $target );
			}
			?>
		</div>
		<?php
	}

	// ------------------------------------------------------- translate tab

	/**
	 * @param string $target Current target language code ('' if none).
	 */
	private static function render_translate_tab( string $target ): void {
		$targets = FATW_Translator::target_languages();

		if ( '' === $target ) {
			?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'WPML has no secondary languages configured yet. Add at least one language in WPML → Languages first — this plugin translates into your active WPML languages.', 'free-ai-translator-for-wpml' ); ?>
			</p></div>
			<?php
			return;
		}

		$target_label = FATW_Translator::language_display_name( $target );

		$types        = FATW_Translator::post_types();
		$current_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( ! in_array( $current_type, $types, true ) ) {
			$current_type = in_array( 'page', $types, true ) ? 'page' : (string) reset( $types );
		}

		$items = FATW_Translator::get_items( $current_type, $target );
		?>
		<div class="fatw-panel">

			<?php if ( '' === FATW_Settings::api_key() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings tab link */
						esc_html__( 'No Gemini API key configured yet. Add one on the %s tab before translating.', 'free-ai-translator-for-wpml' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'free-ai-translator-for-wpml' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="fatw-lang-picker">
				<label for="fatw-lang-select"><strong><?php esc_html_e( 'Translate to:', 'free-ai-translator-for-wpml' ); ?></strong></label>
				<select id="fatw-lang-select">
					<?php foreach ( $targets as $code => $lang ) : ?>
						<option value="<?php echo esc_attr( admin_url( 'admin.php?page=' . self::SLUG . '&type=' . $current_type . '&lang=' . $code ) ); ?>" <?php selected( $code, $target ); ?>>
							<?php echo esc_html( $lang['name'] . ( $lang['native'] && $lang['native'] !== $lang['name'] ? ' (' . $lang['native'] . ')' : '' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<ul class="subsubsub fatw-type-filter">
				<?php foreach ( $types as $i => $type ) : ?>
					<?php
					$type_obj  = get_post_type_object( $type );
					$label     = $type_obj ? $type_obj->labels->name : $type;
					$type_rows = ( $type === $current_type ) ? $items : FATW_Translator::get_items( $type, $target );
					$untr      = count( array_filter( $type_rows, static fn( $r ) => 'none' === $r->tr_status && empty( $r->is_builder ) ) );
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&type=' . $type . '&lang=' . $target ) ); ?>" class="<?php echo $type === $current_type ? 'current' : ''; ?>">
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo esc_html( $untr ); ?> <?php esc_html_e( 'untranslated', 'free-ai-translator-for-wpml' ); ?>)</span>
						</a><?php echo $i < count( $types ) - 1 ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="fatw-toolbar">
				<span>
					<a href="#" id="fatw-select-all"><?php esc_html_e( 'Select all untranslated', 'free-ai-translator-for-wpml' ); ?></a> ·
					<a href="#" id="fatw-select-none"><?php esc_html_e( 'Clear', 'free-ai-translator-for-wpml' ); ?></a>
				</span>
				<input type="search" id="fatw-filter" placeholder="<?php esc_attr_e( 'Filter by title…', 'free-ai-translator-for-wpml' ); ?>" />
				<span class="fatw-toolbar-actions">
					<span id="fatw-selected-count">0 <?php esc_html_e( 'selected', 'free-ai-translator-for-wpml' ); ?></span>
					<button type="button" id="fatw-preview-btn" class="button"><?php esc_html_e( 'Preview Cost', 'free-ai-translator-for-wpml' ); ?></button>
					<button type="button" id="fatw-translate-btn" class="button button-primary" disabled><?php esc_html_e( 'Translate Selected', 'free-ai-translator-for-wpml' ); ?></button>
				</span>
			</div>

			<div id="fatw-preview-result" style="display:none;">
				<table class="widefat striped fatw-preview-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Content', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Segments', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Words', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Est. cost (USD)', 'free-ai-translator-for-wpml' ); ?></th>
						</tr>
					</thead>
					<tbody id="fatw-preview-rows"></tbody>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Total', 'free-ai-translator-for-wpml' ); ?></th>
							<th id="fatw-total-segments"></th>
							<th id="fatw-total-words"></th>
							<th id="fatw-total-cost"></th>
						</tr>
					</tfoot>
				</table>
				<p class="description"><?php esc_html_e( 'Estimate only — actual Gemini billing is recorded per run in History & Costs. Nothing is sent until you click Translate Selected.', 'free-ai-translator-for-wpml' ); ?></p>
			</div>

			<div id="fatw-progress" style="display:none;">
				<div class="fatw-progress-bar"><div id="fatw-progress-fill"></div></div>
				<ul id="fatw-progress-log"></ul>
			</div>

			<table class="widefat striped" id="fatw-table">
				<thead>
					<tr>
						<th class="fatw-col-check"><input type="checkbox" id="fatw-check-all" /></th>
						<th><?php esc_html_e( 'Title', 'free-ai-translator-for-wpml' ); ?></th>
						<th class="fatw-col-status">
							<?php
							/* translators: %s: target language name */
							printf( esc_html__( '%s version', 'free-ai-translator-for-wpml' ), esc_html( $target_label ) );
							?>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No published content of this type found.', 'free-ai-translator-for-wpml' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $item ) : ?>
						<tr data-post-id="<?php echo esc_attr( $item->ID ); ?>" data-title="<?php echo esc_attr( mb_strtolower( $item->post_title ) ); ?>">
							<td class="fatw-col-check">
								<input type="checkbox" class="fatw-check" value="<?php echo esc_attr( $item->ID ); ?>" <?php disabled( 'none' !== $item->tr_status || $item->is_builder ); ?> />
							</td>
							<td>
								<strong><?php echo esc_html( $item->post_title ); ?></strong>
								<div class="row-actions">
									<a href="<?php echo esc_url( get_permalink( $item->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View original', 'free-ai-translator-for-wpml' ); ?></a>
								</div>
							</td>
							<td class="fatw-col-status">
								<?php self::render_status_cell( $item, $target ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="fatw-donate">
				<h3><?php esc_html_e( 'Is this plugin saving you money?', 'free-ai-translator-for-wpml' ); ?></h3>
				<p><?php esc_html_e( 'Free AI Translator for WPML is free and always will be — you pay only your own Gemini API bill, with no markup. If it has saved you real translation-credit money, a small donation keeps development going.', 'free-ai-translator-for-wpml' ); ?></p>
				<a class="button" href="https://ko-fi.com/olympusdigital" target="_blank" rel="noopener"><?php esc_html_e( 'Buy me a coffee', 'free-ai-translator-for-wpml' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * @param object $item   Row from FATW_Translator::get_items().
	 * @param string $target Target language code.
	 */
	private static function render_status_cell( $item, string $target ): void {
		if ( ! empty( $item->is_builder ) && 'none' === $item->tr_status ) {
			?>
			<span class="fatw-badge is-builder" title="<?php esc_attr_e( 'This page\'s content is stored by a page builder (e.g. Elementor), outside the block editor. Builder support is on the roadmap.', 'free-ai-translator-for-wpml' ); ?>"><?php esc_html_e( 'Page builder — coming soon', 'free-ai-translator-for-wpml' ); ?></span>
			<?php
			return;
		}
		if ( 'live' === $item->tr_status ) {
			?>
			<span class="fatw-badge is-live"><?php esc_html_e( 'Live', 'free-ai-translator-for-wpml' ); ?></span>
			<a href="<?php echo esc_url( get_permalink( $item->tr_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'free-ai-translator-for-wpml' ); ?></a>
			<?php
		} elseif ( 'draft' === $item->tr_status ) {
			?>
			<span class="fatw-badge is-draft"><?php esc_html_e( 'Draft awaiting review', 'free-ai-translator-for-wpml' ); ?></span>
			<a href="<?php echo esc_url( FATW_Translator::preview_url( $item->tr_id, $target ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'free-ai-translator-for-wpml' ); ?></a> ·
			<a href="<?php echo esc_url( (string) get_edit_post_link( $item->tr_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit', 'free-ai-translator-for-wpml' ); ?></a>
			<?php
		} else {
			?>
			<span class="fatw-badge is-none"><?php esc_html_e( 'Not translated', 'free-ai-translator-for-wpml' ); ?></span>
			<?php
		}
	}

	// --------------------------------------------------------- history tab

	private static function render_history_tab(): void {
		$totals = FATW_Log::totals();
		$recent = FATW_Log::recent( 25 );
		?>
		<div class="fatw-panel">
			<div class="fatw-history-stats">
				<div class="fatw-stat"><span class="fatw-stat-num"><?php echo esc_html( number_format_i18n( $totals['pages'] ) ); ?></span><span class="fatw-stat-label"><?php esc_html_e( 'pages translated', 'free-ai-translator-for-wpml' ); ?></span></div>
				<div class="fatw-stat"><span class="fatw-stat-num"><?php echo esc_html( number_format_i18n( $totals['strings'] ) ); ?></span><span class="fatw-stat-label"><?php esc_html_e( 'text segments', 'free-ai-translator-for-wpml' ); ?></span></div>
				<div class="fatw-stat"><span class="fatw-stat-num">$<?php echo esc_html( number_format( $totals['cost'], 4 ) ); ?></span><span class="fatw-stat-label"><?php esc_html_e( 'total cost incurred', 'free-ai-translator-for-wpml' ); ?></span></div>
				<div class="fatw-stat"><span class="fatw-stat-num"><?php echo esc_html( number_format_i18n( $totals['runs'] ) ); ?></span><span class="fatw-stat-label"><?php esc_html_e( 'translation runs', 'free-ai-translator-for-wpml' ); ?></span></div>
			</div>

			<?php if ( empty( $recent ) ) : ?>
				<p><?php esc_html_e( 'Nothing translated yet.', 'free-ai-translator-for-wpml' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Language', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Segments', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Model', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'When', 'free-ai-translator-for-wpml' ); ?></th>
							<th><?php esc_html_e( 'Translation', 'free-ai-translator-for-wpml' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php
							$source = get_post( $row->post_id );
							$t_post = ! empty( $row->target_post_id ) ? get_post( (int) $row->target_post_id ) : null;
							?>
							<tr>
								<td><?php echo esc_html( $source ? $source->post_title : sprintf( '#%d', $row->post_id ) ); ?></td>
								<td><?php echo esc_html( strtoupper( (string) $row->target_lang ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->string_count ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $row->cost_usd, 4 ) ); ?></td>
								<td><?php echo esc_html( $row->model ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $row->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'free-ai-translator-for-wpml' ) ); ?></td>
								<td>
									<?php if ( $t_post && 'publish' === $t_post->post_status ) : ?>
										<a href="<?php echo esc_url( get_permalink( $t_post ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View live', 'free-ai-translator-for-wpml' ); ?></a>
									<?php elseif ( $t_post && 'trash' !== $t_post->post_status ) : ?>
										<a href="<?php echo esc_url( FATW_Translator::preview_url( $t_post->ID, (string) $row->target_lang ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'free-ai-translator-for-wpml' ); ?></a> ·
										<a href="<?php echo esc_url( (string) get_edit_post_link( $t_post->ID, 'raw' ) ); ?>"><?php esc_html_e( 'Edit', 'free-ai-translator-for-wpml' ); ?></a>
									<?php elseif ( $t_post ) : ?>
										<?php esc_html_e( 'Trashed', 'free-ai-translator-for-wpml' ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------- settings tab

	private static function render_settings_tab(): void {
		$s     = FATW_Settings::get();
		$table = FATW_Settings::model_price_table();
		?>
		<div class="fatw-panel">
			<form method="post" action="options.php">
				<?php settings_fields( 'fatw' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="fatw_api_key"><?php esc_html_e( 'Gemini API key', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td>
							<input type="password" id="fatw_api_key" name="fatw_settings[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<div class="fatw-key-guide">
								<strong><?php esc_html_e( 'How to get a free Gemini API key (about 3 minutes):', 'free-ai-translator-for-wpml' ); ?></strong>
								<ol>
									<li>
										<?php
										printf(
											/* translators: %s: link to Google AI Studio */
											esc_html__( 'Open %s and sign in with any Google account.', 'free-ai-translator-for-wpml' ),
											'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>'
										);
										?>
									</li>
									<li><?php esc_html_e( 'Click "Create API key" and copy the key it shows you.', 'free-ai-translator-for-wpml' ); ?></li>
									<li><?php esc_html_e( 'Paste it into the field above and click Save Settings.', 'free-ai-translator-for-wpml' ); ?></li>
								</ol>
								<p><?php esc_html_e( 'Google\'s free tier includes a daily allowance — many small sites never pay anything at all. Beyond that, you pay Google directly at their published API prices; this plugin adds no markup and never sees your key or your content.', 'free-ai-translator-for-wpml' ); ?></p>
							</div>
							<p class="description">
								<?php esc_html_e( 'For production, prefer defining FATW_GEMINI_API_KEY in wp-config.php — that keeps the key out of the database entirely.', 'free-ai-translator-for-wpml' ); ?>
								<?php if ( defined( 'FATW_GEMINI_API_KEY' ) && FATW_GEMINI_API_KEY ) : ?>
									<br /><strong><?php esc_html_e( 'A wp-config.php constant is currently set and takes priority over this field.', 'free-ai-translator-for-wpml' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="fatw_model"><?php esc_html_e( 'Model', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td>
							<select id="fatw_model" name="fatw_settings[model]">
								<?php foreach ( $table as $id => $row ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $row['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr class="fatw-custom-row">
						<th><label for="fatw_custom_model_id"><?php esc_html_e( 'Custom model ID', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td><input type="text" id="fatw_custom_model_id" name="fatw_settings[custom_model_id]" value="<?php echo esc_attr( $s['custom_model_id'] ); ?>" class="regular-text" placeholder="gemini-..." /></td>
					</tr>
					<tr class="fatw-custom-row">
						<th><label for="fatw_custom_input"><?php esc_html_e( 'Custom price — input ($ / 1M tokens)', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td><input type="number" step="0.01" id="fatw_custom_input" name="fatw_settings[custom_input]" value="<?php echo esc_attr( $s['custom_input'] ); ?>" class="small-text" /></td>
					</tr>
					<tr class="fatw-custom-row">
						<th><label for="fatw_custom_output"><?php esc_html_e( 'Custom price — output ($ / 1M tokens)', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td><input type="number" step="0.01" id="fatw_custom_output" name="fatw_settings[custom_output]" value="<?php echo esc_attr( $s['custom_output'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="fatw_site_context"><?php esc_html_e( 'Site context (optional)', 'free-ai-translator-for-wpml' ); ?></label></th>
						<td>
							<textarea id="fatw_site_context" name="fatw_settings[site_context]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. A personal injury law firm\'s marketing site — formal tone, legal terminology.', 'free-ai-translator-for-wpml' ); ?>"><?php echo esc_textarea( $s['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One or two sentences describing your site and preferred tone. Included in every translation prompt so the AI matches your domain terminology.', 'free-ai-translator-for-wpml' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Built-in model prices are a snapshot taken when this plugin was written and will drift. Check ai.google.dev/gemini-api/docs/pricing before relying on an estimate for a client quote.', 'free-ai-translator-for-wpml' ); ?></p>
				<?php submit_button( __( 'Save Settings', 'free-ai-translator-for-wpml' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ----------------------------------------------------------------- ajax

	/**
	 * @return array{post_id:int, lang:string}
	 */
	private static function check_ajax(): array {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'free-ai-translator-for-wpml' ) ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'free-ai-translator-for-wpml' ) ) );
		}
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( ! FATW_Translator::is_valid_target( $lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid target language.', 'free-ai-translator-for-wpml' ) ) );
		}
		return array(
			'post_id' => $post_id,
			'lang'    => $lang,
		);
	}

	public static function ajax_preview(): void {
		$req = self::check_ajax();

		if ( FATW_Translator::is_builder_page( $req['post_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Page builder content — not supported yet.', 'free-ai-translator-for-wpml' ) ) );
		}

		if ( FATW_Translator::existing_translation_id( $req['post_id'], $req['lang'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: target language name */
						__( 'A %s version already exists.', 'free-ai-translator-for-wpml' ),
						FATW_Translator::language_display_name( $req['lang'] )
					),
				)
			);
		}

		wp_send_json_success( FATW_Translator::estimate_post( $req['post_id'] ) );
	}

	public static function ajax_translate(): void {
		$req = self::check_ajax();

		$result = FATW_Translator::translate_post( $req['post_id'], $req['lang'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( ! empty( $result['skipped'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'message'     => sprintf(
					/* translators: 1: segment count, 2: cost in USD */
					__( '%1$d segments translated ($%2$s) — draft created.', 'free-ai-translator-for-wpml' ),
					(int) $result['string_count'],
					number_format( $result['cost_usd'], 4 )
				),
				'edit_url'    => $result['edit_url'],
				'preview_url' => $result['preview_url'],
				'lifetime'    => FATW_Log::totals()['runs'],
			)
		);
	}

}
