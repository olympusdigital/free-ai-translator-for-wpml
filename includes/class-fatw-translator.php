<?php
/**
 * The plugin's single translation mechanism: translate a source-language
 * post's title, excerpt, and entire block content in one run, and create a
 * real, WPML-linked target-language DRAFT of it.
 *
 * TWO KINDS OF TRANSLATABLE CONTENT, both handled here:
 *  - Plain core blocks (paragraph, heading, list...) carry their text in
 *    innerHTML.
 *  - Dynamic/render-callback custom blocks: innerHTML is empty and copy
 *    lives in JSON attributes. Which attribute keys are real copy versus
 *    layout/media config isn't guessable from the value alone —
 *    wpml-config.xml already curates exactly that list per block type, so
 *    this class parses every wpml-config.xml it can find (child theme,
 *    parent theme, active plugins) and walks attrs by it.
 *
 * SAFETY MODEL
 * The translated post is always created as a draft. Nothing goes live until
 * a human opens the draft in the editor and clicks Publish. If a
 * non-trashed translation already exists for the chosen language,
 * translate_post() refuses to run rather than silently duplicating it.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FATW_Translator {

	/**
	 * Max strings per Gemini call. Long pages are chunked so no single
	 * response risks output-token truncation, which manifests as a
	 * hard-to-diagnose count mismatch.
	 */
	const CHUNK_SIZE = 40;

	// ------------------------------------------------------------ languages

	/**
	 * @return string WPML default (source) language code.
	 */
	public static function source_lang(): string {
		return (string) apply_filters( 'wpml_default_language', 'en' );
	}

	/**
	 * Repair double-encoded UTF-8 ("Español" displayed as "EspaÃ±ol") that
	 * some WPML installs return for native language names, depending on the
	 * database collation history. Detects the telltale Ã/Â byte pairs and
	 * reverses the double encoding; clean strings pass through untouched.
	 *
	 * @param string $s Possibly mojibake string.
	 * @return string
	 */
	private static function fix_encoding( string $s ): string {
		if ( function_exists( 'mb_convert_encoding' ) && preg_match( '/Ã[\x80-\xBF]|Â[\x80-\xBF]/', $s ) ) {
			$fixed = mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
			if ( is_string( $fixed ) && '' !== $fixed && mb_check_encoding( $fixed, 'UTF-8' ) ) {
				return $fixed;
			}
		}
		return $s;
	}

	/**
	 * All active WPML languages EXCEPT the source — the set of valid
	 * translation targets for this site.
	 *
	 * @return array<string, array{code:string, name:string, native:string}>
	 *         Keyed by WPML language code.
	 */
	public static function target_languages(): array {
		$source = self::source_lang();
		$langs  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		$out = array();
		foreach ( (array) $langs as $code => $lang ) {
			if ( $code === $source ) {
				continue;
			}
			$out[ $code ] = array(
				'code'   => (string) $code,
				'name'   => self::fix_encoding( (string) ( $lang['translated_name'] ?? $code ) ),
				'native' => self::fix_encoding( (string) ( $lang['native_name'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * @param string $code WPML language code.
	 * @return bool True if $code is an active non-source language.
	 */
	public static function is_valid_target( string $code ): bool {
		return '' !== $code && array_key_exists( $code, self::target_languages() );
	}

	/**
	 * English name of a language, for the Gemini prompt ("translate to
	 * Spanish", not "translate to es"). WPML's own languages table is the
	 * source of truth; the raw code is the last-resort fallback — models
	 * handle ISO codes fine, just less explicitly.
	 *
	 * @param string $code WPML language code.
	 * @return string
	 */
	public static function language_english_name( string $code ): string {
		global $sitepress;
		if ( $sitepress && method_exists( $sitepress, 'get_language_details' ) ) {
			$details = $sitepress->get_language_details( $code );
			if ( is_array( $details ) && ! empty( $details['english_name'] ) ) {
				return (string) $details['english_name'];
			}
		}
		$targets = self::target_languages();
		return $targets[ $code ]['name'] ?? $code;
	}

	/**
	 * Display name (in the admin's current language) for UI labels.
	 *
	 * @param string $code WPML language code.
	 * @return string
	 */
	public static function language_display_name( string $code ): string {
		$targets = self::target_languages();
		return $targets[ $code ]['name'] ?? $code;
	}

	// ----------------------------------------------------------- post types

	/**
	 * Post types offered for translation: every public, WPML-translatable
	 * post type (attachments excluded — media is copied, not translated).
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		$out = array();
		foreach ( $types as $type ) {
			if ( apply_filters( 'wpml_is_translated_post_type', null, $type ) ) {
				$out[] = $type;
			}
		}
		if ( empty( $out ) ) {
			$out = array( 'page', 'post' );
		}
		return apply_filters( 'fatw_post_types', array_values( $out ) );
	}

	// ------------------------------------------------------------- listings

	/**
	 * Every published source-language post of one type, with its current
	 * target-language status resolved in the same query. Drives the
	 * Translate tab.
	 *
	 * status semantics for the UI:
	 *   'none'  — no translation (or only a trashed one): translatable.
	 *   'draft' — a draft exists awaiting review: link to edit it.
	 *   'live'  — a published translation exists: link to view it.
	 *
	 * @param string $post_type   One of post_types().
	 * @param string $target_lang WPML language code to check status against.
	 * @return array<int, object{ID:int, post_title:string, tr_id:int, tr_status:string}>
	 */
	public static function get_items( string $post_type, string $target_lang ): array {
		global $wpdb;

		// pb.meta_id detects Elementor in the same query — a page carrying
		// _elementor_edit_mode='builder' stores its content as JSON in post
		// meta, not in post_content, so this plugin can't translate it yet.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, tr_p.ID AS tr_post_id, tr_p.post_status AS tr_post_status,
				        pb.meta_id AS builder_meta_id
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}icl_translations t
				   ON t.element_id = p.ID AND t.element_type = %s
				 LEFT JOIN {$wpdb->prefix}icl_translations tr
				   ON tr.trid = t.trid AND tr.language_code = %s AND tr.element_id <> p.ID
				 LEFT JOIN {$wpdb->posts} tr_p ON tr_p.ID = tr.element_id
				 LEFT JOIN {$wpdb->postmeta} pb
				   ON pb.post_id = p.ID AND pb.meta_key = '_elementor_edit_mode' AND pb.meta_value = 'builder'
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( t.language_code = %s OR t.language_code IS NULL )
				 ORDER BY p.post_title",
				'post_' . $post_type,
				$target_lang,
				$post_type,
				self::source_lang()
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$tr_id  = (int) $row->tr_post_id;
			$status = 'none';
			if ( $tr_id && 'trash' !== $row->tr_post_status ) {
				$status = ( 'publish' === $row->tr_post_status ) ? 'live' : 'draft';
			}
			$out[] = (object) array(
				'ID'         => (int) $row->ID,
				'post_title' => (string) $row->post_title,
				'tr_id'      => ( 'none' === $status ) ? 0 : $tr_id,
				'tr_status'  => $status,
				'is_builder' => ! empty( $row->builder_meta_id ) || self::is_builder_page( (int) $row->ID ),
			);
		}
		return $out;
	}

	/**
	 * True if this post's real content lives in a page builder's own
	 * storage rather than in post_content blocks — Divi/WPBakery shortcode
	 * stacks in post_content, or Beaver Builder meta. (Elementor is caught
	 * cheaply in get_items()' SQL join; this covers the rest, and acts as
	 * the authoritative pre-translate check for all of them.)
	 *
	 * Translating these would produce an empty or layout-broken draft, so
	 * they're excluded until builder support ships.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_builder_page( int $post_id ): bool {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		if ( '1' === (string) get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
			return true;
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		// Divi and WPBakery mark their pages with signature shortcodes.
		if ( false !== strpos( $content, '[et_pb_' ) || false !== strpos( $content, '[vc_row' ) ) {
			return true;
		}
		return (bool) apply_filters( 'fatw_is_builder_page', false, $post_id );
	}

	/**
	 * Front-end preview URL for a translated draft — the full rendered
	 * page, no editor needed.
	 *
	 * Built by hand rather than via get_preview_post_link(): that helper
	 * leans on get_permalink(), which for drafts (and CPT drafts
	 * especially, with WPML's permalink filtering on top) can collapse to
	 * the bare home URL. The explicit ?p=ID form is what WP core's own
	 * editor Preview button uses and resolves regardless of permalink
	 * structure; the wpml_permalink filter then adds the language prefix so
	 * the theme renders the preview in target-language site context.
	 *
	 * @param int    $post_id     The DRAFT (translated) post ID.
	 * @param string $target_lang WPML language code of the draft.
	 * @return string
	 */
	public static function preview_url( int $post_id, string $target_lang ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$args = array( 'preview' => 'true' );
		if ( 'page' === $post->post_type ) {
			$args['page_id'] = $post_id;
		} else {
			$args['p'] = $post_id;
			if ( 'post' !== $post->post_type ) {
				$args['post_type'] = $post->post_type;
			}
		}

		$url = add_query_arg( $args, home_url( '/' ) );
		return (string) apply_filters( 'wpml_permalink', $url, $target_lang );
	}

	/**
	 * Existing ACTIVE translation post ID for a source post in a given
	 * language — 0 if none.
	 *
	 * A trashed translation doesn't count: wp_trash_post() only changes
	 * post_status, it doesn't remove the icl_translations row, so WPML's
	 * wpml_object_id keeps returning a trashed post's ID forever. Treating
	 * trash as "no translation" is what allows a redo after trashing a bad
	 * translation.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang WPML language code.
	 * @return int
	 */
	public static function existing_translation_id( int $post_id, string $target_lang ): int {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}
		$id = apply_filters( 'wpml_object_id', $post_id, $post->post_type, false, $target_lang );
		if ( ! $id || (int) $id === $post_id ) {
			return 0;
		}
		return 'trash' === get_post_status( $id ) ? 0 : (int) $id;
	}

	// ------------------------------------------------ text-vs-config filter

	/**
	 * Attribute keys wpml-config.xml may mark translatable but whose values
	 * are internal render-logic enums, not copy (e.g. a listType key
	 * holding "ul"/"plain" that render code matches against). Translating
	 * them breaks rendering. Filterable so theme authors can extend it.
	 *
	 * @return string[]
	 */
	public static function non_text_keys(): array {
		return apply_filters( 'fatw_non_text_keys', array( 'listType' ) );
	}

	/**
	 * True if a value is real translatable copy, not metadata that happens
	 * to live in a text field (bare URLs, tel:/mailto: links, phone
	 * numbers, pure currency/percent figures).
	 *
	 * @param string $value Raw value (may contain HTML).
	 * @return bool
	 */
	public static function is_translatable_value( string $value ): bool {
		$plain = trim( wp_strip_all_tags( $value ) );
		if ( '' === $plain ) {
			return false;
		}
		// Bare URL, path, tel:/mailto:, or anchor. Delimiter is ~ because #
		// is one of the literal alternatives — using # as both delimiter
		// and content silently truncates the pattern.
		if ( preg_match( '~^(https?://|/|tel:|mailto:|#)~i', $plain ) ) {
			return false;
		}
		// Phone number (loose — separators and optional country code).
		if ( preg_match( '/^\+?[0-9()\-.\s]{7,20}$/', $plain ) ) {
			return false;
		}
		// Pure currency/number, e.g. "$15,000,000" or "29%" or "90+".
		if ( preg_match( '/^[$0-9.,%+\-\s]+$/', $plain ) ) {
			return false;
		}
		return true;
	}

	// ------------------------------------------- wpml-config.xml key trees

	/**
	 * Every wpml-config.xml this site could be honoring, in priority order
	 * (lowest first — later files override earlier ones for the same block
	 * type): active plugins, then parent theme, then child theme. This
	 * mirrors WPML's own discovery so the plugin translates exactly the
	 * attribute keys WPML itself considers translatable, whatever theme or
	 * page-builder blocks the site uses.
	 *
	 * @return string[] Existing file paths.
	 */
	private static function config_paths(): array {
		$paths = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$dir = dirname( $plugin );
			if ( '.' !== $dir ) {
				$paths[] = WP_PLUGIN_DIR . '/' . $dir . '/wpml-config.xml';
			}
		}

		$paths[] = get_template_directory() . '/wpml-config.xml';
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$paths[] = get_stylesheet_directory() . '/wpml-config.xml';
		}

		return array_values( array_unique( array_filter( $paths, 'file_exists' ) ) );
	}

	/**
	 * Per-block-type map of translatable attribute keys, merged from every
	 * discovered wpml-config.xml (child theme wins over parent theme wins
	 * over plugins, per config_paths() order).
	 *
	 * Tree node semantics:
	 *  - null: leaf — attrs[key] is the translatable value (string, or a
	 *    plain list of strings).
	 *  - array with a '*' entry: attrs[key] is a numeric list; every item is
	 *    processed against the '*' subtree.
	 *  - array of named entries: attrs[key] is a nested object, recursed.
	 *
	 * @return array<string, array|null>
	 */
	private static function key_trees(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();

		foreach ( self::config_paths() as $path ) {
			libxml_use_internal_errors( true );
			$xml = simplexml_load_file( $path );
			libxml_use_internal_errors( false );

			if ( ! $xml || ! isset( $xml->{'gutenberg-blocks'}->{'gutenberg-block'} ) ) {
				continue;
			}

			foreach ( $xml->{'gutenberg-blocks'}->{'gutenberg-block'} as $block ) {
				$type = (string) $block['type'];
				if ( '' !== $type ) {
					$cache[ $type ] = self::parse_key_node( $block );
				}
			}
		}
		return $cache;
	}

	/**
	 * @param SimpleXMLElement $node A <gutenberg-block> or <key> element.
	 * @return array<string, array|null>
	 */
	private static function parse_key_node( SimpleXMLElement $node ): array {
		$tree = array();
		foreach ( $node->key as $key ) {
			$name = (string) $key['name'];
			if ( '' !== $name ) {
				$tree[ $name ] = count( $key->key ) > 0 ? self::parse_key_node( $key ) : null;
			}
		}
		return $tree;
	}

	/**
	 * Walk one block's attrs against its key tree, collecting every real
	 * text value in document order plus a key path for writing the
	 * translation back into the same slot.
	 *
	 * @param array $attrs Block attrs.
	 * @param array $tree  Key tree for this block type.
	 * @param array $texts Appended to.
	 * @param array $paths Appended to, 1:1 with $texts.
	 * @param array $path  Recursion state.
	 */
	private static function collect_attrs_texts( array $attrs, array $tree, array &$texts, array &$paths, array $path = array() ): void {
		foreach ( $tree as $key => $children ) {
			if ( ! array_key_exists( $key, $attrs ) || in_array( $key, self::non_text_keys(), true ) ) {
				continue;
			}
			$value = $attrs[ $key ];
			$here  = array_merge( $path, array( $key ) );

			if ( is_array( $children ) && array_key_exists( '*', $children ) ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$item_tree = $children['*'];
				foreach ( $value as $i => $item ) {
					$item_path = array_merge( $here, array( $i ) );
					if ( is_array( $item_tree ) && ! empty( $item_tree ) ) {
						if ( is_array( $item ) ) {
							self::collect_attrs_texts( $item, $item_tree, $texts, $paths, $item_path );
						}
					} elseif ( is_string( $item ) && self::is_translatable_value( $item ) ) {
						$texts[] = $item;
						$paths[] = $item_path;
					}
				}
				continue;
			}

			if ( is_array( $children ) ) {
				if ( is_array( $value ) ) {
					self::collect_attrs_texts( $value, $children, $texts, $paths, $here );
				}
				continue;
			}

			if ( is_string( $value ) ) {
				if ( self::is_translatable_value( $value ) ) {
					$texts[] = $value;
					$paths[] = $here;
				}
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $i => $item ) {
					if ( is_string( $item ) && self::is_translatable_value( $item ) ) {
						$texts[] = $item;
						$paths[] = array_merge( $here, array( $i ) );
					}
				}
			}
		}
	}

	/**
	 * Write a translated value back into a nested attrs array at a key path.
	 *
	 * @param array  $attrs Modified in place.
	 * @param array  $path  Path as recorded by collect_attrs_texts().
	 * @param string $value Translated value.
	 */
	private static function write_attr_path( array &$attrs, array $path, string $value ): void {
		$ref  = &$attrs;
		$last = count( $path ) - 1;
		foreach ( $path as $depth => $key ) {
			if ( $depth === $last ) {
				$ref[ $key ] = $value;
			} else {
				if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
					$ref[ $key ] = array();
				}
				$ref = &$ref[ $key ];
			}
		}
		unset( $ref );
	}

	// ------------------------------------------------------ block tree walk

	/**
	 * block.json default values for a block type's attributes — the
	 * translatable copy Gutenberg does NOT serialize into post_content.
	 *
	 * This matters more than it sounds: an attribute left at its default is
	 * omitted from the block comment entirely, and many themes ship
	 * boilerplate copy as defaults (hero headings, stat labels, CTA text).
	 * A translator that walks stored attrs alone silently skips every one
	 * of those, and the translated page then renders the source-language
	 * defaults. Merging defaults in (stored values winning) lets them be
	 * translated and written back as EXPLICIT attrs on the translated post,
	 * which override the defaults at render time.
	 *
	 * @param string $block_type Block type name.
	 * @return array<string, mixed> attribute name => default value.
	 */
	private static function block_defaults( string $block_type ): array {
		static $cache = array();
		if ( isset( $cache[ $block_type ] ) ) {
			return $cache[ $block_type ];
		}

		$defaults = array();
		$reg      = WP_Block_Type_Registry::get_instance()->get_registered( $block_type );
		if ( $reg ) {
			foreach ( (array) ( $reg->attributes ?? array() ) as $name => $schema ) {
				if ( isset( $schema['default'] ) ) {
					$defaults[ $name ] = $schema['default'];
				}
			}
		}

		$cache[ $block_type ] = $defaults;
		return $defaults;
	}

	/**
	 * Pass 1: collect every translatable text in the post's blocks, in
	 * document order — attrs-based copy for configured custom blocks,
	 * innerHTML for plain leaf blocks — plus refs for writing back.
	 *
	 * @param array $blocks    parse_blocks() output.
	 * @param array $texts     Appended to.
	 * @param array $refs      Appended to, 1:1 with $texts.
	 * @param array $key_trees key_trees() result.
	 * @param array $path      Recursion state (block index path).
	 */
	private static function collect_texts( array $blocks, array &$texts, array &$refs, array $key_trees, array $path = array() ): void {
		foreach ( $blocks as $i => $block ) {
			$here = array_merge( $path, array( $i ) );
			$type = $block['blockName'] ?? '';

			if ( $type && isset( $key_trees[ $type ] ) ) {
				// Stored attrs win over block.json defaults; defaults fill
				// in the translatable copy Gutenberg didn't serialize.
				$attrs = array_merge( self::block_defaults( $type ), is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );

				$attr_texts = array();
				$attr_paths = array();
				self::collect_attrs_texts( $attrs, $key_trees[ $type ], $attr_texts, $attr_paths );
				foreach ( $attr_texts as $n => $t ) {
					$texts[] = $t;
					$refs[]  = array(
						'kind'       => 'attrs',
						'block_path' => $here,
						'attr_path'  => $attr_paths[ $n ],
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_texts( $block['innerBlocks'], $texts, $refs, $key_trees, $here );
				continue;
			}

			$html = (string) ( $block['innerHTML'] ?? '' );
			if ( '' !== trim( $html ) && self::is_translatable_value( $html ) ) {
				$texts[] = $html;
				$refs[]  = array(
					'kind'       => 'innerHTML',
					'block_path' => $here,
				);
			}
		}
	}

	/**
	 * Pass 2: write translated text back at the recorded refs, then
	 * reserialize. Attr re-encoding goes through serialize_blocks() itself,
	 * so this never hand-writes block-comment JSON.
	 *
	 * @param array    $blocks       Same tree collect_texts() walked.
	 * @param array    $refs         Refs from collect_texts().
	 * @param string[] $translations 1:1 with $refs.
	 * @return string Reserialized post_content.
	 */
	private static function apply_translations( array $blocks, array $refs, array $translations ): string {
		foreach ( $refs as $n => $ref ) {
			$blk  = &$blocks;
			$path = $ref['block_path'];
			$last = count( $path ) - 1;
			foreach ( $path as $depth => $idx ) {
				if ( $depth === $last ) {
					if ( 'innerHTML' === $ref['kind'] ) {
						$blk[ $idx ]['innerHTML']    = $translations[ $n ];
						$blk[ $idx ]['innerContent'] = array( $translations[ $n ] );
					} else {
						if ( ! isset( $blk[ $idx ]['attrs'] ) || ! is_array( $blk[ $idx ]['attrs'] ) ) {
							$blk[ $idx ]['attrs'] = array();
						}
						// If this text came from a block.json DEFAULT that the
						// stored attrs never carried, seed the FULL default
						// value first. Writing only the translated leaf into
						// an absent array attribute would create a partial
						// structure (e.g. locations with names but no
						// addresses) that overrides the complete default at
						// render time.
						$top = $ref['attr_path'][0];
						if ( ! array_key_exists( $top, $blk[ $idx ]['attrs'] ) ) {
							$defs = self::block_defaults( (string) ( $blk[ $idx ]['blockName'] ?? '' ) );
							if ( array_key_exists( $top, $defs ) ) {
								$blk[ $idx ]['attrs'][ $top ] = $defs[ $top ];
							}
						}
						self::write_attr_path( $blk[ $idx ]['attrs'], $ref['attr_path'], $translations[ $n ] );
					}
				} else {
					$blk = &$blk[ $idx ]['innerBlocks'];
				}
			}
			unset( $blk );
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * Build the exact batch a run will send — shared by estimate_post() and
	 * translate_post() so the cost preview can never drift from reality.
	 *
	 * @param WP_Post $post Source post.
	 * @return array{blocks:array, refs:array, batch:string[], has_excerpt:bool}
	 */
	private static function build_batch( WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );

		$texts = array();
		$refs  = array();
		self::collect_texts( $blocks, $texts, $refs, self::key_trees() );

		$has_excerpt = '' !== trim( $post->post_excerpt );
		$prefix      = $has_excerpt ? array( $post->post_title, $post->post_excerpt ) : array( $post->post_title );

		return array(
			'blocks'      => $blocks,
			'refs'        => $refs,
			'batch'       => array_merge( $prefix, $texts ),
			'has_excerpt' => $has_excerpt,
		);
	}

	// ----------------------------------------------------------- public API

	/**
	 * Pre-call cost estimate for one post, from the same batch a real run
	 * would send.
	 *
	 * @param int $post_id Source post ID.
	 * @return array{string_count:int, word_count:int, input_tokens:int, output_tokens:int, cost_usd:float, model_id:string}
	 */
	public static function estimate_post( int $post_id ): array {
		$post  = get_post( $post_id );
		$model = FATW_Settings::resolve_model();

		if ( ! $post ) {
			return FATW_Estimator::estimate( array(), $model['model_id'], $model['input'], $model['output'] );
		}

		$b    = self::build_batch( $post );
		$rows = array_map( static fn( $v ) => (object) array( 'value' => $v ), $b['batch'] );

		return FATW_Estimator::estimate( $rows, $model['model_id'], $model['input'], $model['output'] );
	}

	/**
	 * Translate one post end to end and create a linked target-language
	 * draft.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang WPML language code to translate into.
	 * @return array{new_post_id:int, string_count:int, cost_usd:float, edit_url:string, preview_url:string}|array{skipped:true, message:string}|WP_Error
	 */
	public static function translate_post( int $post_id, string $target_lang ) {
		if ( ! self::is_valid_target( $target_lang ) ) {
			return new WP_Error( 'fatw_bad_lang', __( 'Not an active WPML target language on this site.', 'free-ai-translator-for-wpml' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || 'publish' !== $post->post_status ) {
			return new WP_Error( 'fatw_bad_post', __( 'Not a published, translatable post.', 'free-ai-translator-for-wpml' ) );
		}

		// Builder pages (Elementor, Divi, WPBakery, Beaver Builder) keep
		// their real content outside post_content blocks — translating them
		// would create an empty or broken draft AND spend API money doing
		// it. Refuse before anything is sent.
		if ( self::is_builder_page( $post_id ) ) {
			return new WP_Error(
				'fatw_builder_page',
				__( 'This page is built with a page builder (e.g. Elementor). Only block editor (Gutenberg) and classic content is supported for now — builder support is on the roadmap.', 'free-ai-translator-for-wpml' )
			);
		}

		// Only source-language content may be translated. Without this, a
		// post WPML already tags as another language could be run
		// same-language, creating a junk duplicate in its own translation
		// group.
		$post_lang = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => 'post_' . $post->post_type,
			)
		);
		if ( $post_lang && $post_lang !== self::source_lang() ) {
			return new WP_Error(
				'fatw_wrong_lang',
				sprintf(
					/* translators: 1: WPML language code of the post, 2: site default language code */
					__( 'This post is registered as "%1$s" content, not the site\'s default language ("%2$s") — refusing to translate it.', 'free-ai-translator-for-wpml' ),
					$post_lang,
					self::source_lang()
				)
			);
		}

		if ( self::existing_translation_id( $post_id, $target_lang ) ) {
			return array(
				'skipped' => true,
				'message' => sprintf(
					/* translators: %s: target language name */
					__( 'A %s version already exists — trash it first if you want to redo it.', 'free-ai-translator-for-wpml' ),
					self::language_display_name( $target_lang )
				),
			);
		}

		$b = self::build_batch( $post );
		if ( empty( $b['batch'] ) ) {
			return array(
				'skipped' => true,
				'message' => __( 'Nothing translatable found in this post.', 'free-ai-translator-for-wpml' ),
			);
		}

		$model       = FATW_Settings::resolve_model();
		$source_name = self::language_english_name( self::source_lang() );
		$target_name = self::language_english_name( $target_lang );

		// Chunked translation: one long page never rides on a single huge
		// response. Any chunk failing aborts the WHOLE run before anything
		// is written — a half-translated draft is worse than no draft.
		$translated    = array();
		$input_tokens  = 0;
		$output_tokens = 0;

		foreach ( array_chunk( $b['batch'], self::CHUNK_SIZE ) as $chunk ) {
			$result = FATW_Gemini::translate_batch( $chunk, $source_name, $target_name, $model['model_id'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$translated     = array_merge( $translated, $result['translations'] );
			$input_tokens  += $result['usage']['input_tokens'];
			$output_tokens += $result['usage']['output_tokens'];
		}

		$new_title   = array_shift( $translated );
		$new_excerpt = $b['has_excerpt'] ? array_shift( $translated ) : '';
		$new_content = self::apply_translations( $b['blocks'], $b['refs'], $translated );

		// Map the parent into the target-language tree if a translated
		// parent exists, so translated pages keep their hierarchy (and
		// their language-prefixed URLs nest correctly) instead of all
		// landing at the top level.
		$parent_tr = 0;
		if ( $post->post_parent ) {
			$mapped = apply_filters( 'wpml_object_id', $post->post_parent, $post->post_type, false, $target_lang );
			if ( $mapped && 'trash' !== get_post_status( $mapped ) ) {
				$parent_tr = (int) $mapped;
			}
		}

		// wp_insert_post() unslashes its input assuming the caller slashed
		// it (mirroring form submissions) — without wp_slash() a literal
		// backslash in translated text gets eaten.
		$postarr = wp_slash(
			array(
				'post_title'     => $new_title,
				'post_excerpt'   => $new_excerpt,
				'post_content'   => $new_content,
				'post_status'    => 'draft',
				'post_type'      => $post->post_type,
				'post_author'    => $post->post_author,
				'post_parent'    => $parent_tr,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
			)
		);

		$new_post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Meta the rendered page depends on. Template assignment and the
		// featured image aren't copied by wp_insert_post(), and WPML's own
		// copy rules don't fire for posts created through the raw API.
		foreach ( array( '_wp_page_template', '_thumbnail_id' ) as $meta_key ) {
			$meta_value = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $meta_value && false !== $meta_value ) {
				update_post_meta( $new_post_id, $meta_key, $meta_value );
			}
		}

		// Link into the SOURCE post's translation group. Passing the trid
		// (not false) is what makes WPML treat this as "the {language}
		// version of that page" rather than a new unrelated post.
		global $sitepress;
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post->post_type );
		$sitepress->set_element_language_details(
			$new_post_id,
			'post_' . $post->post_type,
			$trid ?: false,
			$target_lang,
			self::source_lang()
		);

		$cost = ( $input_tokens / 1_000_000 * $model['input'] ) + ( $output_tokens / 1_000_000 * $model['output'] );

		FATW_Log::record( $post_id, $new_post_id, $target_lang, count( $b['batch'] ), $input_tokens, $output_tokens, $cost, $model['model_id'] );

		return array(
			'new_post_id'  => (int) $new_post_id,
			'string_count' => count( $b['batch'] ),
			'cost_usd'     => $cost,
			'edit_url'     => (string) get_edit_post_link( $new_post_id, 'raw' ),
			'preview_url'  => self::preview_url( $new_post_id, $target_lang ),
		);
	}
}
