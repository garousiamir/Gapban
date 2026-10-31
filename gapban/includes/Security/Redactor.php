<?php
/**
 * Redacts credentials from diagnostics, logs and stored provider errors.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	public static function text( string $text ): string {
		$token = Settings::bot_token();
		if ( '' !== $token ) {
			$text = str_replace( $token, '[token]', $text );
		}
		// Token-bearing provider URLs: /bot<digits>:<secret>/ and /file/bot.../.
		$text = preg_replace( '#bot\d+:[A-Za-z0-9_\-]+#', 'bot[token]', $text );
		// Webhook secret path segments.
		$text = preg_replace( '#(/bale/webhook/)[A-Za-z0-9_\-]+#', '$1[secret]', $text );
		return mb_substr( (string) $text, 0, 1000 );
	}

	/**
	 * Remove query parameters whose names look sensitive before a URL is shown to operators.
	 */
	public static function page_url( string $url ): string {
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return '';
		}
		$clean = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$sensitive = (array) Settings::get( 'sensitive_query_keys' );
			$kept      = array();
			foreach ( $query as $key => $value ) {
				$lk = strtolower( (string) $key );
				$is_sensitive = false;
				foreach ( $sensitive as $needle ) {
					if ( '' !== $needle && str_contains( $lk, $needle ) ) {
						$is_sensitive = true;
						break;
					}
				}
				if ( ! $is_sensitive && is_scalar( $value ) ) {
					$kept[ $key ] = $value;
				}
			}
			if ( $kept ) {
				$clean .= '?' . http_build_query( $kept );
			}
		}
		return mb_substr( $clean, 0, 500 );
	}
}
