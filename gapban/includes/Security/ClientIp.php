<?php
/**
 * Client IP resolution that trusts forwarding headers only from configured proxies.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	public static function get(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';

		$proxies = (array) Settings::get( 'trusted_proxies' );
		if ( ! $proxies || ! self::matches_any( $remote, $proxies ) ) {
			return $remote;
		}

		$header = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
		if ( '' === $header ) {
			return $remote;
		}
		// Walk right-to-left, skipping trusted hops; the first untrusted address is the client.
		$hops = array_reverse( array_map( 'trim', explode( ',', $header ) ) );
		foreach ( $hops as $hop ) {
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $remote;
			}
			if ( ! self::matches_any( $hop, $proxies ) ) {
				return $hop;
			}
		}
		return $remote;
	}

	public static function hash( ?string $ip = null ): string {
		return Crypto::hash( $ip ?? self::get(), 'ip' );
	}

	/**
	 * @param string[] $cidrs
	 */
	public static function matches_any( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		[ $subnet, $bits ] = explode( '/', $cidr, 2 );
		$ip_bin            = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin           = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	public static function is_valid_cidr( string $value ): bool {
		if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( ! preg_match( '#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $value, $m ) || ! filter_var( $m[1], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$max = str_contains( $m[1], ':' ) ? 128 : 32;
		return (int) $m[2] <= $max;
	}
}
