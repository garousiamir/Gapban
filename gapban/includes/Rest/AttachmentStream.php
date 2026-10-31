<?php
/**
 * Streams a protected attachment with safe headers. Authorization is the caller's job.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Security\AttachmentStorage;

defined( 'ABSPATH' ) || exit;

final class AttachmentStream {

	private const INLINE = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/ogg', 'audio/webm', 'audio/mpeg', 'audio/mp4', 'video/webm' );

	/**
	 * @param array<string, mixed> $att
	 */
	public static function send( array $att ): void {
		$path = AttachmentStorage::path( (string) $att['storage_name'] );
		if ( ! $path ) {
			status_header( 404 );
			exit;
		}
		$mime     = (string) $att['mime'];
		$inline   = in_array( $mime, self::INLINE, true );
		$name     = (string) $att['original_name'];
		$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( 'Cache-Control: private, no-store' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
