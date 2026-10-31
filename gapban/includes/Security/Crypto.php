<?php
/**
 * Authenticated encryption for recoverable credentials (bot token).
 *
 * Key strategy:
 *  - If GAPBAN_ENCRYPTION_KEY is defined in wp-config.php (32+ chars), it is the key material.
 *  - Otherwise key material is derived from wp_salt( 'auth' ) (AUTH_KEY + AUTH_SALT) via HKDF.
 *  - Ciphertexts carry a key id ("k1"/"c1") so that rotation can re-encrypt
 *    with `wp gapban rotate-key` while old values remain decryptable.
 *
 * @package Gapban
 */

namespace Gapban\Security;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const PREFIX = 'gb1';

	/**
	 * @return array<string, string> key id => 32-byte key
	 */
	private static function keys(): array {
		$keys = array();
		if ( defined( 'GAPBAN_ENCRYPTION_KEY' ) && strlen( (string) GAPBAN_ENCRYPTION_KEY ) >= 32 ) {
			$keys['c1'] = hash_hkdf( 'sha256', (string) GAPBAN_ENCRYPTION_KEY, 32, 'gapban-secretbox' );
		}
		if ( defined( 'GAPBAN_PREVIOUS_ENCRYPTION_KEY' ) && strlen( (string) GAPBAN_PREVIOUS_ENCRYPTION_KEY ) >= 32 ) {
			$keys['c0'] = hash_hkdf( 'sha256', (string) GAPBAN_PREVIOUS_ENCRYPTION_KEY, 32, 'gapban-secretbox' );
		}
		$keys['k1'] = hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, 'gapban-secretbox' );
		return $keys;
	}

	public static function current_key_id(): string {
		return array_key_first( self::keys() );
	}

	public static function encrypt( string $plaintext ): string {
		$keys  = self::keys();
		$kid   = array_key_first( $keys );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plaintext, $nonce, $keys[ $kid ] );
		return self::PREFIX . ':' . $kid . ':' . base64_encode( $nonce . $box );
	}

	/**
	 * @return string|null null when the value cannot be authenticated with any known key.
	 */
	public static function decrypt( string $ciphertext ): ?string {
		$parts = explode( ':', $ciphertext, 3 );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}
		$keys = self::keys();
		if ( ! isset( $keys[ $parts[1] ] ) ) {
			return null;
		}
		$raw = base64_decode( $parts[2], true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, $keys[ $parts[1] ] );
		return false === $plain ? null : $plain;
	}

	public static function key_id_of( string $ciphertext ): string {
		$parts = explode( ':', $ciphertext, 3 );
		return $parts[1] ?? '';
	}

	/**
	 * Keyed hash used for bearer secrets and one-time codes (never reversible).
	 */
	public static function hash( string $value, string $purpose ): string {
		$key = hash_hkdf( 'sha256', wp_salt( 'nonce' ), 32, 'gapban-hash-' . $purpose );
		return hash_hmac( 'sha256', $value, $key );
	}
}
