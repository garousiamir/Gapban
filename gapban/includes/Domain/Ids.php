<?php
/**
 * Random identifiers. Public display ids are never used as secrets.
 *
 * @package Gapban
 */

namespace Gapban\Domain;

defined( 'ABSPATH' ) || exit;

final class Ids {

	private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * 26-char Crockford base32 identifier: 48-bit time prefix + 80 random bits.
	 */
	public static function public_id(): string {
		$ms   = (int) floor( microtime( true ) * 1000 );
		$time = '';
		for ( $i = 9; $i >= 0; $i-- ) {
			$time = self::CROCKFORD[ $ms % 32 ] . $time;
			$ms   = intdiv( $ms, 32 );
		}
		return $time . self::random_base32( 16 );
	}

	/**
	 * Short human-readable conversation reference such as "GB-7K2QX9".
	 */
	public static function reference(): string {
		return 'GB-' . self::random_base32( 6 );
	}

	public static function random_base32( int $length ): string {
		$out   = '';
		$bytes = random_bytes( $length );
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::CROCKFORD[ ord( $bytes[ $i ] ) & 31 ];
		}
		return $out;
	}

	/**
	 * URL-safe base64 secret of $bytes random bytes.
	 */
	public static function secret( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	public static function is_public_id( string $value ): bool {
		return 1 === preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $value );
	}
}
