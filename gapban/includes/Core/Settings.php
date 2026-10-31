<?php
/**
 * Plugin settings: typed defaults, sanitization, and encrypted secrets.
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Security\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION         = 'gapban_settings';
	public const SECRETS_OPTION = 'gapban_secrets';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * Default settings. Every key here is user-configurable and validated in sanitize().
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Connection & routing.
			'routing_mode'             => 'reply',
			'default_destination_chat' => '',
			'default_destination_title'=> '',
			'transport'                => 'webhook', // webhook | polling.
			'mirror_admin_replies'     => true,
			'visitor_context_fields'   => array( 'name', 'page' ),
			'uncertain_send_policy'    => 'retry', // retry | hold.
			'max_text_chunk'           => 3500,

			// Widget behaviour.
			'widget_enabled'           => true,
			'widget_language'          => 'fa', // fa | en | site.
			'persian_digits'           => true,
			'jalali_dates'             => true,
			'brand_name'               => 'گپ‌بان',
			'welcome_title'            => 'سلام! چطور می‌توانیم کمکتان کنیم؟',
			'welcome_subtitle'         => 'معمولاً در چند دقیقه پاسخ می‌دهیم.',
			'automated_greeting'       => '',
			'offline_message'          => 'الان آنلاین نیستیم. پیامتان را بگذارید تا در اولین فرصت پاسخ دهیم.',
			'away_message'             => 'همکاران ما موقتاً در دسترس نیستند.',
			'queue_message'            => 'پیامتان رسید؛ به‌زودی یکی از همکاران پاسخ می‌دهد.',
			'prechat_enabled'          => true,
			'prechat_fields'           => array(
				'name'       => 'optional',
				'email'      => 'optional',
				'phone'      => 'hidden',
				'department' => 'hidden',
			),
			'custom_fields'            => array(),
			'consent_required'         => false,
			'consent_text'             => 'با ارسال پیام، با ذخیره و انتقال گفتگو به پشتیبانی (از جمله پیام‌رسان بله) موافقم.',
			'allow_attachments'        => true,
			'allow_voice'              => false,
			'max_upload_mb'            => 5,
			'allowed_upload_types'     => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'text/plain', 'application/zip' ),
			'allow_reopen'             => true,
			'csat_enabled'             => true,
			'transcript_enabled'       => true,
			'suggested_questions'      => array(),
			'sound_default'            => true,
			'honeypot'                 => true,

			// Launcher appearance.
			'launcher_variant'         => 'pill', // pill | circle | avatar | text.
			'launcher_label'           => 'گفتگو با ما',
			'launcher_position'        => 'end', // start | end | left | right.
			'launcher_offset_x'        => 24,
			'launcher_offset_y'        => 24,
			'launcher_offset_x_mobile' => 16,
			'launcher_offset_y_mobile' => 16,
			'launcher_size'            => 48,
			'launcher_size_mobile'     => 52,
			'launcher_icon'            => 'comments',
			'launcher_custom_svg'      => '',
			'launcher_animation'       => 'subtle', // none | subtle.
			'launcher_attention'       => false,
			'launcher_teaser'          => '',
			'launcher_avoid_selectors' => '',
			'launcher_hide_on_checkout'=> true,
			'brand_color'              => '#ef4b4b',
			'brand_color_dark'         => '#d33c3c',
			'ink_color'                => '#111827',
			'z_index'                  => 70,
			'avatar_url'               => '',

			// Hours.
			'timezone'                 => '',
			'business_hours_enabled'   => false,
			'business_hours'           => array(
				'sat' => array( array( '09:00', '17:00' ) ),
				'sun' => array( array( '09:00', '17:00' ) ),
				'mon' => array( array( '09:00', '17:00' ) ),
				'tue' => array( array( '09:00', '17:00' ) ),
				'wed' => array( array( '09:00', '17:00' ) ),
				'thu' => array(),
				'fri' => array(),
			),
			'holidays'                 => array(),
			'temporary_away_until'     => '',

			// Security & limits.
			'trusted_proxies'          => array(),
			'max_message_length'       => 4000,
			'session_ttl_days'         => 30,
			'sessions_per_ip_per_hour' => 20,
			'messages_per_minute'      => 20,
			'captcha_provider'         => 'none',

			// Privacy & retention.
			'retention_days'           => 365,
			'inbound_retention_days'   => 14,
			'attachment_retention_days'=> 180,
			'delete_data_on_uninstall' => false,
			'sensitive_query_keys'     => array( 'token', 'key', 'password', 'pass', 'pwd', 'secret', 'code', 'otp', 'email', 'phone', 'mobile', 'session', 'auth', 'order', 'nonce' ),

			// WooCommerce display.
			'currency_label'           => 'auto', // auto | rial | toman.
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Merge and persist a partial update after sanitization.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> the stored settings
	 */
	public static function update( array $input ): array {
		$merged = array_merge( self::all(), self::sanitize( $input ) );
		update_option( self::OPTION, $merged, true );
		self::$cache = null;
		return self::all();
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Validate untrusted input. Unknown keys are dropped.
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( $in as $key => $value ) {
			if ( ! array_key_exists( $key, $d ) ) {
				continue;
			}
			$default = $d[ $key ];
			switch ( $key ) {
				case 'routing_mode':
					$out[ $key ] = in_array( $value, array( 'reply', 'topics' ), true ) ? $value : 'reply';
					break;
				case 'transport':
					$out[ $key ] = in_array( $value, array( 'webhook', 'polling' ), true ) ? $value : 'webhook';
					break;
				case 'uncertain_send_policy':
					$out[ $key ] = in_array( $value, array( 'retry', 'hold' ), true ) ? $value : 'retry';
					break;
				case 'widget_language':
					$out[ $key ] = in_array( $value, array( 'fa', 'en', 'site' ), true ) ? $value : 'fa';
					break;
				case 'launcher_variant':
					$out[ $key ] = in_array( $value, array( 'pill', 'circle', 'avatar', 'text' ), true ) ? $value : 'pill';
					break;
				case 'launcher_position':
					$out[ $key ] = in_array( $value, array( 'start', 'end', 'left', 'right' ), true ) ? $value : 'end';
					break;
				case 'launcher_animation':
					$out[ $key ] = in_array( $value, array( 'none', 'subtle' ), true ) ? $value : 'subtle';
					break;
				case 'launcher_icon':
					$out[ $key ] = in_array( $value, array( 'comments', 'comment-dots', 'headset', 'custom' ), true ) ? $value : 'comments';
					break;
				case 'captcha_provider':
					$out[ $key ] = 'none';
					break;
				case 'currency_label':
					$out[ $key ] = in_array( $value, array( 'auto', 'rial', 'toman' ), true ) ? $value : 'auto';
					break;
				case 'launcher_custom_svg':
					$out[ $key ] = \Gapban\Security\SvgSanitizer::sanitize( (string) $value ) ?? '';
					break;
				case 'brand_color':
				case 'brand_color_dark':
				case 'ink_color':
					$color       = sanitize_hex_color( (string) $value );
					$out[ $key ] = $color ? $color : $default;
					break;
				case 'avatar_url':
					$out[ $key ] = esc_url_raw( (string) $value, array( 'https', 'http' ) );
					break;
				case 'default_destination_chat':
					$out[ $key ] = self::chat_id( (string) $value );
					break;
				case 'timezone':
					$tz          = (string) $value;
					$out[ $key ] = ( '' === $tz || in_array( $tz, timezone_identifiers_list(), true ) ) ? $tz : '';
					break;
				case 'temporary_away_until':
					$ts          = strtotime( (string) $value );
					$out[ $key ] = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
					break;
				case 'business_hours':
					$out[ $key ] = self::sanitize_hours( is_array( $value ) ? $value : array() );
					break;
				case 'holidays':
					$out[ $key ] = self::sanitize_holidays( is_array( $value ) ? $value : array() );
					break;
				case 'prechat_fields':
					$fields = array();
					foreach ( array( 'name', 'email', 'phone', 'department' ) as $f ) {
						$mode         = is_array( $value ) ? ( $value[ $f ] ?? 'hidden' ) : 'hidden';
						$fields[ $f ] = in_array( $mode, array( 'hidden', 'optional', 'required' ), true ) ? $mode : 'hidden';
					}
					$out[ $key ] = $fields;
					break;
				case 'custom_fields':
					$out[ $key ] = self::sanitize_custom_fields( is_array( $value ) ? $value : array() );
					break;
				case 'visitor_context_fields':
					$allowed     = array( 'name', 'email', 'phone', 'page', 'referrer', 'department', 'custom' );
					$out[ $key ] = array_values( array_intersect( $allowed, array_map( 'strval', is_array( $value ) ? $value : array() ) ) );
					break;
				case 'allowed_upload_types':
					$allowed     = \Gapban\Security\UploadValidator::SAFE_TYPES;
					$out[ $key ] = array_values( array_intersect( array_keys( $allowed ), array_map( 'strval', is_array( $value ) ? $value : array() ) ) );
					break;
				case 'trusted_proxies':
					$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
					$out[ $key ] = array_values(
						array_filter(
							array_map( 'trim', (array) $list ),
							static fn( $ip ) => \Gapban\Security\ClientIp::is_valid_cidr( (string) $ip )
						)
					);
					break;
				case 'sensitive_query_keys':
					$list        = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
					$out[ $key ] = array_values( array_filter( array_map( static fn( $k ) => sanitize_key( (string) $k ), (array) $list ) ) );
					break;
				case 'suggested_questions':
					$list        = is_array( $value ) ? $value : array();
					$out[ $key ] = array_slice( array_values( array_filter( array_map( static fn( $q ) => mb_substr( sanitize_text_field( (string) $q ), 0, 120 ), $list ) ) ), 0, 6 );
					break;
				case 'launcher_avoid_selectors':
					// Only simple selectors: ids, classes, element names and attribute-free combinators.
					$out[ $key ] = implode( ', ', array_filter( array_map( 'trim', explode( ',', preg_replace( '/[^a-zA-Z0-9_\-#.,\s>]/', '', (string) $value ) ) ) ) );
					break;
				default:
					if ( is_bool( $default ) ) {
						$out[ $key ] = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					} elseif ( is_int( $default ) ) {
						$out[ $key ] = self::bounded_int( $key, $value );
					} elseif ( is_string( $default ) ) {
						$multiline   = in_array( $key, array( 'offline_message', 'away_message', 'queue_message', 'automated_greeting', 'consent_text', 'welcome_subtitle', 'launcher_teaser' ), true );
						$text        = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
						$out[ $key ] = mb_substr( $text, 0, $multiline ? 1000 : 190 );
					}
			}
		}
		return $out;
	}

	private static function bounded_int( string $key, $value ): int {
		$bounds = array(
			'max_text_chunk'            => array( 500, 4000 ),
			'max_upload_mb'             => array( 1, 20 ),
			'launcher_offset_x'         => array( 0, 200 ),
			'launcher_offset_y'         => array( 0, 300 ),
			'launcher_offset_x_mobile'  => array( 0, 200 ),
			'launcher_offset_y_mobile'  => array( 0, 300 ),
			'launcher_size'             => array( 40, 80 ),
			'launcher_size_mobile'      => array( 44, 80 ),
			'z_index'                   => array( 1, 2147483000 ),
			'max_message_length'        => array( 100, 10000 ),
			'session_ttl_days'          => array( 1, 365 ),
			'sessions_per_ip_per_hour'  => array( 1, 1000 ),
			'messages_per_minute'       => array( 1, 120 ),
			'retention_days'            => array( 0, 3650 ),
			'inbound_retention_days'    => array( 1, 90 ),
			'attachment_retention_days' => array( 0, 3650 ),
		);
		$int            = (int) \Gapban\Core\Locale::normalize_digits( (string) $value );
		[ $min, $max ]  = $bounds[ $key ] ?? array( 0, PHP_INT_MAX );
		return max( $min, min( $max, $int ) );
	}

	/**
	 * Provider chat ids are signed integers that can exceed 32 bits; keep them as strings.
	 */
	public static function chat_id( string $value ): string {
		$value = trim( \Gapban\Core\Locale::normalize_digits( $value ) );
		return preg_match( '/^-?\d{1,20}$/', $value ) ? $value : '';
	}

	/**
	 * @param array<string, mixed> $hours
	 * @return array<string, array<int, array{0:string,1:string}>>
	 */
	private static function sanitize_hours( array $hours ): array {
		$out = array();
		foreach ( array( 'sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri' ) as $day ) {
			$out[ $day ] = array();
			foreach ( (array) ( $hours[ $day ] ?? array() ) as $range ) {
				if ( ! is_array( $range ) || count( $range ) < 2 ) {
					continue;
				}
				$from = \Gapban\Core\Locale::normalize_digits( (string) $range[0] );
				$to   = \Gapban\Core\Locale::normalize_digits( (string) $range[1] );
				if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $from ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $to ) && $from < $to ) {
					$out[ $day ][] = array( $from, $to );
				}
			}
			$out[ $day ] = array_slice( $out[ $day ], 0, 4 );
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $list
	 * @return array<int, array{date:string,label:string}>
	 */
	private static function sanitize_holidays( array $list ): array {
		$out = array();
		foreach ( $list as $item ) {
			$date = is_array( $item ) ? \Gapban\Core\Locale::normalize_digits( (string) ( $item['date'] ?? '' ) ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) {
				$out[] = array(
					'date'  => $date,
					'label' => mb_substr( sanitize_text_field( (string) ( $item['label'] ?? '' ) ), 0, 80 ),
				);
			}
		}
		return array_slice( $out, 0, 200 );
	}

	/**
	 * @param array<int, mixed> $list
	 * @return array<int, array{key:string,label:string,type:string,required:bool}>
	 */
	private static function sanitize_custom_fields( array $list ): array {
		$out = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $item['key'] ?? '' ) );
			if ( '' === $key || in_array( $key, array( 'name', 'email', 'phone', 'department' ), true ) ) {
				continue;
			}
			$out[] = array(
				'key'      => substr( $key, 0, 32 ),
				'label'    => mb_substr( sanitize_text_field( (string) ( $item['label'] ?? $key ) ), 0, 80 ),
				'type'     => in_array( $item['type'] ?? 'text', array( 'text', 'number', 'url' ), true ) ? $item['type'] : 'text',
				'required' => ! empty( $item['required'] ),
			);
		}
		return array_slice( $out, 0, 8 );
	}

	/* ------------------------------------------------------------------ */
	/* Secrets                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Bot token. GAPBAN_BALE_TOKEN in wp-config.php overrides the stored value.
	 */
	public static function bot_token(): string {
		if ( defined( 'GAPBAN_BALE_TOKEN' ) && '' !== (string) GAPBAN_BALE_TOKEN ) {
			return (string) GAPBAN_BALE_TOKEN;
		}
		$secret = self::secret( 'bot_token' );
		return $secret ?? '';
	}

	public static function token_from_config(): bool {
		return defined( 'GAPBAN_BALE_TOKEN' ) && '' !== (string) GAPBAN_BALE_TOKEN;
	}

	public static function secret( string $name ): ?string {
		$all = get_option( self::SECRETS_OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $name ] ) ) {
			return null;
		}
		return Crypto::decrypt( (string) $all[ $name ] );
	}

	public static function set_secret( string $name, ?string $value ): void {
		$all = get_option( self::SECRETS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $value || '' === $value ) {
			unset( $all[ $name ] );
		} else {
			$all[ $name ] = Crypto::encrypt( $value );
		}
		update_option( self::SECRETS_OPTION, $all, false );
	}

	/**
	 * Re-encrypt every stored secret with the current key.
	 *
	 * @return int number of secrets re-encrypted
	 */
	public static function rotate_secrets(): int {
		$all   = get_option( self::SECRETS_OPTION, array() );
		$count = 0;
		foreach ( is_array( $all ) ? $all : array() as $name => $cipher ) {
			$plain = Crypto::decrypt( (string) $cipher );
			if ( null !== $plain ) {
				$all[ $name ] = Crypto::encrypt( $plain );
				++$count;
			}
		}
		update_option( self::SECRETS_OPTION, $all, false );
		return $count;
	}

	public static function mask( string $token ): string {
		if ( strlen( $token ) <= 8 ) {
			return str_repeat( '•', strlen( $token ) );
		}
		return substr( $token, 0, 4 ) . str_repeat( '•', 8 ) . substr( $token, -3 );
	}

	/**
	 * Keys that must never be exported by default.
	 */
	public static function export_excluded(): array {
		return array( 'default_destination_chat', 'trusted_proxies' );
	}
}
