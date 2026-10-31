<?php
/**
 * WordPress admin menu and the React admin app bootstrap.
 *
 * Assets load only on Gapban screens; the front-end widget is never enqueued in admin.
 *
 * @package Gapban
 */

namespace Gapban\Admin;

use Gapban\Core\Strings;
use Gapban\Security\Capabilities as Caps;
use Gapban\Widget\Frontend;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	/**
	 * @return array<string, array{title:string, cap:string}>
	 */
	public static function pages(): array {
		return array(
			'gapban'            => array( 'title' => __( 'Conversations inbox', 'gapban' ), 'cap' => Caps::VIEW_ASSIGNED ),
			'gapban-setup'      => array( 'title' => __( 'Setup & connection', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-operators'  => array( 'title' => __( 'Operators & departments', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-appearance' => array( 'title' => __( 'Widget appearance', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-rules'      => array( 'title' => __( 'Display rules & automation', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-hours'      => array( 'title' => __( 'Business hours & holidays', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-knowledge'  => array( 'title' => __( 'Canned responses & help', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-reports'    => array( 'title' => __( 'Reports & satisfaction', 'gapban' ), 'cap' => Caps::REPORT ),
			'gapban-privacy'    => array( 'title' => __( 'Privacy & data retention', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
			'gapban-queue'      => array( 'title' => __( 'Delivery queue & diagnostics', 'gapban' ), 'cap' => Caps::DIAGNOSTICS ),
			'gapban-tools'      => array( 'title' => __( 'Import / export', 'gapban' ), 'cap' => Caps::MANAGE_SETTINGS ),
		);
	}

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GAPBAN_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * The inbox is visible to anyone who can see assigned or all conversations.
	 */
	private static function inbox_cap(): string {
		return current_user_can( Caps::VIEW_ASSIGNED ) ? Caps::VIEW_ASSIGNED : Caps::VIEW_ALL;
	}

	public static function menu(): void {
		$pages = self::pages();
		$badge = self::unread_badge();
		add_menu_page(
			__( 'Gapban', 'gapban' ),
			__( 'Gapban', 'gapban' ) . $badge,
			self::inbox_cap(),
			'gapban',
			array( self::class, 'render' ),
			'dashicons-format-chat',
			26
		);
		foreach ( $pages as $slug => $page ) {
			add_submenu_page( 'gapban', $page['title'], $page['title'], 'gapban' === $slug ? self::inbox_cap() : $page['cap'], $slug, array( self::class, 'render' ) );
		}
	}

	private static function unread_badge(): string {
		if ( ! Caps::any() ) {
			return '';
		}
		$n = (int) \Gapban\Persistence\Db::value( 'SELECT COUNT(*) FROM ' . \Gapban\Persistence\Db::t( 'conversations' ) . " WHERE status = 'queued' AND assigned_operator_id IS NULL" );
		return $n ? ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $n ) ) . '</span></span>' : '';
	}

	public static function render(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'gapban'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__( 'Gapban', 'gapban' ) . '</h1>';
		echo '<div id="gapban-admin" data-page="' . esc_attr( $page ) . '"><p>' . esc_html__( 'Loading…', 'gapban' ) . '</p>';
		echo '<noscript>' . esc_html__( 'The Gapban dashboard needs JavaScript.', 'gapban' ) . '</noscript></div></div>';
	}

	public static function enqueue( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( ! array_key_exists( $page, self::pages() ) ) {
			return;
		}
		$manifest = Frontend::manifest();
		$ver      = $manifest['admin.js']['hash'] ?? GAPBAN_VERSION;
		wp_enqueue_style( 'gapban-admin', GAPBAN_URL . 'assets/dist/admin.css', array(), $ver );
		wp_register_script( 'gapban-admin', GAPBAN_URL . 'assets/dist/admin.js', array(), $ver, true );
		wp_add_inline_script( 'gapban-admin', 'window.gapbanAdmin=' . wp_json_encode( self::config( $page, $manifest ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		wp_enqueue_script( 'gapban-admin' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function config( string $page, array $manifest ): array {
		$locale = determine_locale();
		$fa     = str_starts_with( $locale, 'fa' );
		$caps   = array();
		foreach ( Caps::ALL as $cap ) {
			$caps[ $cap ] = current_user_can( $cap );
		}
		$pages = array();
		foreach ( self::pages() as $slug => $p ) {
			$cap = 'gapban' === $slug ? self::inbox_cap() : $p['cap'];
			if ( current_user_can( $cap ) ) {
				$pages[] = array(
					'slug'  => $slug,
					'title' => $p['title'],
					'url'   => admin_url( 'admin.php?page=' . $slug ),
				);
			}
		}
		$settings = \Gapban\Core\Settings::all();
		return array(
			'rest'          => esc_url_raw( rest_url( 'gapban/v1/' ) ),
			'restRoot'      => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'page'          => $page,
			'pages'         => $pages,
			'lang'          => $fa ? 'fa' : 'en',
			'dir'           => ( $fa || is_rtl() ) ? 'rtl' : 'ltr',
			'tz'            => \Gapban\Core\Locale::timezone()->getName(),
			'persianDigits' => $fa && (bool) $settings['persian_digits'],
			'jalali'        => $fa && (bool) $settings['jalali_dates'],
			'caps'          => $caps,
			'user'          => array(
				'id'          => get_current_user_id(),
				'name'        => wp_get_current_user()->display_name,
				'operator_id' => Caps::current_operator_id(),
			),
			'strings'       => self::translated( AdminStrings::all() ),
			'widgetStrings' => array(
				'fa' => Strings::in_locale( 'fa_IR', array( Strings::class, 'widget' ) ),
				'en' => Strings::in_locale( 'en_US', array( Strings::class, 'widget' ) ),
			),
			'pluginUrl'     => GAPBAN_URL,
			'siteUrl'       => untrailingslashit( home_url() ),
			'loaderUrl'     => GAPBAN_URL . 'assets/dist/widget-loader.js?ver=' . rawurlencode( $manifest['widget-loader.js']['hash'] ?? GAPBAN_VERSION ),
			'chatUrl'       => GAPBAN_URL . 'assets/dist/widget-chat.js?ver=' . rawurlencode( $manifest['widget-chat.js']['hash'] ?? GAPBAN_VERSION ),
			'fontUrl'       => GAPBAN_URL . 'assets/fonts/Estedad-VariableFont_wght.woff2',
			'version'       => GAPBAN_VERSION,
		);
	}

	/**
	 * Send only entries that differ from the English source (smaller payload).
	 *
	 * @param array<string, string> $all
	 * @return array<string, string>
	 */
	private static function translated( array $all ): array {
		return array_filter( $all, static fn( $v, $k ) => $v !== $k, ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( Caps::MANAGE_SETTINGS ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=gapban-setup' ) ) . '">' . esc_html__( 'Setup', 'gapban' ) . '</a>' );
		}
		return $links;
	}
}
