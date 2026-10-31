<?php
/**
 * Attachment validation: content sniffing, executable blocking, size limits.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class UploadValidator {

	/**
	 * Types that may ever be enabled, with their canonical extension.
	 * SVG, HTML, XML and every executable/script type are deliberately absent.
	 */
	public const SAFE_TYPES = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/webp'      => 'webp',
		'image/gif'       => 'gif',
		'application/pdf' => 'pdf',
		'text/plain'      => 'txt',
		'application/zip' => 'zip',
		'audio/ogg'       => 'ogg',
		'audio/webm'      => 'webm',
		'audio/mpeg'      => 'mp3',
		'audio/mp4'       => 'm4a',
		'video/webm'      => 'webm',
	);

	private const BLOCKED_EXTENSIONS = array(
		'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'pht', 'exe', 'msi', 'bat', 'cmd', 'com', 'sh', 'bash',
		'js', 'mjs', 'cjs', 'jar', 'vbs', 'ps1', 'scr', 'dll', 'so', 'apk', 'app', 'html', 'htm', 'xhtml', 'svg', 'svgz',
		'xml', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'htaccess', 'ini', 'hta', 'lnk', 'reg',
	);

	/**
	 * Validate a local file. Returns [mime, ext] or a WP_Error.
	 *
	 * @param bool $allow_audio voice notes use audio types even when not listed in settings.
	 * @return array{mime:string, ext:string}|\WP_Error
	 */
	public static function validate( string $path, string $original_name, int $size, bool $allow_audio = false ) {
		$max = (int) Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
		if ( $size <= 0 || $size > $max ) {
			return new \WP_Error( 'gapban_upload_size', __( 'The file is too large.', 'gapban' ), array( 'status' => 413 ) );
		}
		// Any blocked extension anywhere after the first dot ("x.php.jpg") is rejected.
		$segments = array_slice( explode( '.', strtolower( $original_name ) ), 1 );
		if ( array_intersect( $segments, self::BLOCKED_EXTENSIONS ) ) {
			return new \WP_Error( 'gapban_upload_type', __( 'This file type is not allowed.', 'gapban' ), array( 'status' => 415 ) );
		}

		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->file( $path );
		if ( 'audio/x-wav' === $mime || 'video/ogg' === $mime ) {
			$mime = 'video/ogg' === $mime ? 'audio/ogg' : $mime;
		}

		$allowed = (array) Settings::get( 'allowed_upload_types' );
		if ( $allow_audio ) {
			$allowed = array_merge( $allowed, array( 'audio/ogg', 'audio/webm', 'video/webm', 'audio/mpeg', 'audio/mp4' ) );
		}
		if ( ! isset( self::SAFE_TYPES[ $mime ] ) || ! in_array( $mime, $allowed, true ) ) {
			return new \WP_Error( 'gapban_upload_type', __( 'This file type is not allowed.', 'gapban' ), array( 'status' => 415 ) );
		}

		// Reject polyglots: no markup or PHP open tags inside images/text.
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( preg_match( '/<\?php|<script|<html|<svg/i', $head ) && 'application/zip' !== $mime && 'application/pdf' !== $mime ) {
			return new \WP_Error( 'gapban_upload_type', __( 'This file type is not allowed.', 'gapban' ), array( 'status' => 415 ) );
		}

		if ( str_starts_with( $mime, 'image/' ) && 'image/gif' !== $mime ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $info ) {
				return new \WP_Error( 'gapban_upload_type', __( 'The image could not be read.', 'gapban' ), array( 'status' => 415 ) );
			}
		}

		return array(
			'mime' => $mime,
			'ext'  => self::SAFE_TYPES[ $mime ],
		);
	}

	public static function safe_display_name( string $name ): string {
		$name = sanitize_file_name( wp_basename( $name ) );
		$name = mb_substr( $name, 0, 150 );
		return '' === $name ? 'file' : $name;
	}
}
