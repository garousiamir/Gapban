<?php
/**
 * Front-end integration: enqueues the small widget loader with a cache-safe config.
 *
 * Cache safety: the inline config contains only site-wide settings and the current
 * URL's page context (post id/type/terms). It never contains visitor data, nonces or
 * session secrets, so full-page caches and CDNs may serve it to everyone. Visitor
 * state is fetched from the no-store /bootstrap endpoint.
 *
 * @package Gapban
 */

namespace Gapban\Widget;

use Gapban\Core\Settings;
use Gapban\Core\Strings;
use Gapban\Persistence\Db;
use Gapban\Rules\RuleEvents;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	public static function bump_config_version(): void {
		update_option( 'gapban_config_version', time(), true );
	}

	public static function should_render(): bool {
		if ( ! Settings::get( 'widget_enabled' ) || is_admin() || is_feed() || is_embed() || wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}
		/**
		 * Filter whether the widget renders on the current request.
		 *
		 * @param bool $render
		 */
		return (bool) apply_filters( 'gapban_render_widget', true );
	}

	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}
		$manifest = self::manifest();
		$ver      = $manifest['widget-loader.js']['hash'] ?? GAPBAN_VERSION;
		wp_register_script(
			'gapban-widget',
			GAPBAN_URL . 'assets/dist/widget-loader.js',
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'gapban-widget', 'window.gapbanConfig=' . wp_json_encode( self::config( $manifest ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		wp_enqueue_script( 'gapban-widget' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function manifest(): array {
		static $manifest = null;
		if ( null === $manifest ) {
			$file     = GAPBAN_DIR . 'assets/dist/manifest.json';
			$manifest = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $manifest;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function config( array $manifest = array() ): array {
		$s        = Settings::all();
		$lang     = self::language();
		$strings  = Strings::in_locale( 'fa' === $lang ? 'fa_IR' : 'en_US', array( Strings::class, 'widget' ) );
		$chat_ver = $manifest['widget-chat.js']['hash'] ?? GAPBAN_VERSION;

		return array(
			'v'             => (string) get_option( 'gapban_config_version', '1' ),
			'rest'          => esc_url_raw( rest_url( 'gapban/v1/' ) ),
			'assets'        => array(
				'chat' => GAPBAN_URL . 'assets/dist/widget-chat.js?ver=' . rawurlencode( $chat_ver ),
				'font' => 'fa' === $lang ? GAPBAN_URL . 'assets/fonts/Estedad-VariableFont_wght.woff2' : '',
			),
			'lang'          => $lang,
			'dir'           => 'fa' === $lang ? 'rtl' : 'ltr',
			'tz'            => \Gapban\Core\Locale::timezone()->getName(),
			'persianDigits' => (bool) $s['persian_digits'],
			'jalali'        => (bool) $s['jalali_dates'],
			'brand'         => array(
				'name'      => $s['brand_name'],
				'color'     => $s['brand_color'],
				'colorDark' => $s['brand_color_dark'],
				'ink'       => $s['ink_color'],
				'avatar'    => $s['avatar_url'],
			),
			'launcher'      => array(
				'variant'       => $s['launcher_variant'],
				'label'         => $s['launcher_label'],
				'position'      => $s['launcher_position'],
				'offsetX'       => (int) $s['launcher_offset_x'],
				'offsetY'       => (int) $s['launcher_offset_y'],
				'offsetXMobile' => (int) $s['launcher_offset_x_mobile'],
				'offsetYMobile' => (int) $s['launcher_offset_y_mobile'],
				'size'          => (int) $s['launcher_size'],
				'sizeMobile'    => (int) $s['launcher_size_mobile'],
				'icon'          => $s['launcher_icon'],
				'customSvg'     => $s['launcher_custom_svg'],
				'animation'     => $s['launcher_animation'],
				'attention'     => (bool) $s['launcher_attention'],
				'teaser'        => $s['launcher_teaser'],
				'avoid'         => $s['launcher_avoid_selectors'],
				'hidden'        => self::hidden_on_this_page(),
				'zIndex'        => (int) $s['z_index'],
			),
			'texts'         => array(
				'welcome_title'    => $s['welcome_title'],
				'welcome_subtitle' => $s['welcome_subtitle'],
				'offline_message'  => $s['offline_message'],
				'away_message'     => $s['away_message'],
				'queue_message'    => $s['queue_message'],
				'consent_text'     => $s['consent_text'],
			),
			'prechat'       => array(
				'enabled'         => (bool) $s['prechat_enabled'],
				'fields'          => $s['prechat_fields'],
				'custom'          => $s['custom_fields'],
				'consentRequired' => (bool) $s['consent_required'],
			),
			'departments'   => array_map(
				static fn( $t ) => array( 'id' => (int) $t['id'], 'name' => $t['name'] ),
				Db::rows( 'SELECT id, name FROM ' . Db::t( 'teams' ) . ' WHERE active = 1 AND public = 1 ORDER BY sort_order, id' )
			),
			'features'      => array(
				'attachments'  => (bool) $s['allow_attachments'],
				'voice'        => (bool) $s['allow_voice'],
				'csat'         => (bool) $s['csat_enabled'],
				'transcript'   => (bool) $s['transcript_enabled'],
				'maxUploadMb'  => (int) $s['max_upload_mb'],
				'allowedTypes' => array_values( (array) $s['allowed_upload_types'] ),
				'honeypot'     => (bool) $s['honeypot'],
				'sound'        => (bool) $s['sound_default'],
				'reopen'       => (bool) $s['allow_reopen'],
				'maxLength'    => (int) $s['max_message_length'],
			),
			'faq'           => array_map(
				static fn( $k ) => array( 'title' => $k['title'], 'body' => $k['body'], 'url' => $k['url'] ),
				Db::rows( 'SELECT title, body, url FROM ' . Db::t( 'knowledge' ) . " WHERE type = 'faq' AND active = 1 ORDER BY sort_order, id LIMIT 12" )
			),
			'suggestions'   => array_values( (array) $s['suggested_questions'] ),
			'rules'         => RuleEvents::active_rules(),
			'page'          => self::page_context(),
			'sensitive'     => array_values( (array) $s['sensitive_query_keys'] ),
			'hours'         => array(
				'enabled'  => (bool) $s['business_hours_enabled'],
				'schedule' => $s['business_hours'],
				'holidays' => array_map( static fn( $h ) => array( 'date' => $h['date'] ), (array) $s['holidays'] ),
			),
			'strings'       => $strings,
		);
	}

	public static function language(): string {
		$lang = (string) Settings::get( 'widget_language' );
		if ( 'site' === $lang ) {
			return str_starts_with( determine_locale(), 'fa' ) ? 'fa' : 'en';
		}
		return 'en' === $lang ? 'en' : 'fa';
	}

	private static function hidden_on_this_page(): bool {
		if ( Settings::get( 'launcher_hide_on_checkout' ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		return false;
	}

	/**
	 * Context of the current URL (identical for every visitor, so cache-safe).
	 *
	 * @return array<string, mixed>
	 */
	public static function page_context(): array {
		$obj   = get_queried_object();
		$kinds = array();
		foreach ( array( 'front' => 'is_front_page', 'home' => 'is_home', 'archive' => 'is_archive', 'search' => 'is_search', '404' => 'is_404', 'singular' => 'is_singular', 'page' => 'is_page', 'post' => 'is_single' ) as $kind => $fn ) {
			if ( $fn() ) {
				$kinds[] = (string) $kind;
			}
		}
		$terms = array();
		$id    = 0;
		$type  = '';
		$tpl   = '';
		if ( $obj instanceof \WP_Post ) {
			$id   = (int) $obj->ID;
			$type = $obj->post_type;
			$tpl  = (string) ( get_page_template_slug( $obj ) ?: 'default' );
			foreach ( get_object_taxonomies( $obj->post_type ) as $tax ) {
				$ids = wp_get_post_terms( $obj->ID, $tax, array( 'fields' => 'ids' ) );
				if ( is_array( $ids ) && $ids ) {
					$terms[ $tax ] = array_map( 'intval', $ids );
				}
			}
		} elseif ( $obj instanceof \WP_Term ) {
			$terms[ $obj->taxonomy ] = array( (int) $obj->term_id );
		} elseif ( $obj instanceof \WP_Post_Type ) {
			$type = $obj->name;
		}

		$wc = array(
			'pages'        => array(),
			'product_cats' => array(),
		);
		if ( function_exists( 'is_woocommerce' ) ) {
			foreach ( array( 'product' => 'is_product', 'product_category' => 'is_product_category', 'shop' => 'is_shop', 'cart' => 'is_cart', 'checkout' => 'is_checkout', 'account' => 'is_account_page' ) as $page => $fn ) {
				if ( function_exists( $fn ) && $fn() ) {
					$wc['pages'][] = $page;
				}
			}
			$wc['product_cats'] = $terms['product_cat'] ?? array();
		}
		return array(
			'id'        => $id,
			'post_type' => $type,
			'template'  => $tpl,
			'kinds'     => $kinds,
			'terms'     => (object) $terms,
			'wc'        => $wc,
		);
	}
}
