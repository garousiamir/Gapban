<?php
/**
 * Private attachment storage.
 *
 * Files live in GAPBAN_STORAGE_DIR when defined (recommended: outside the web root),
 * otherwise in wp-content/uploads/gapban-private-<random>/ protected by deny rules
 * for Apache/LiteSpeed/IIS, an index guard, random 40-char names and no extension.
 * Nginx ignores .htaccess, so the diagnostics screen probes public reachability and
 * docs/security.md gives the matching nginx rule.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Domain\Ids;

defined( 'ABSPATH' ) || exit;

final class AttachmentStorage {

	private const OPTION = 'gapban_storage_dir';

	public static function dir(): string {
		if ( defined( 'GAPBAN_STORAGE_DIR' ) && '' !== GAPBAN_STORAGE_DIR ) {
			$dir = rtrim( (string) GAPBAN_STORAGE_DIR, '/\\' );
		} else {
			$name = get_option( self::OPTION );
			if ( ! $name ) {
				$name = 'gapban-private-' . strtolower( Ids::random_base32( 12 ) );
				update_option( self::OPTION, $name, false );
			}
			$uploads = wp_upload_dir( null, false );
			$dir     = rtrim( $uploads['basedir'], '/\\' ) . '/' . $name;
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( $dir );
		return $dir;
	}

	public static function protect( string $dir ): void {
		$files = array(
			'.htaccess'  => "# Gapban private storage\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $file => $content ) {
			$path = $dir . '/' . $file;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Move a validated temp file into storage. Returns the storage name.
	 */
	public static function store( string $tmp_path, bool $is_uploaded = false ): string {
		$name = strtolower( Ids::random_base32( 40 ) );
		$dest = self::dir() . '/' . $name;
		$ok   = $is_uploaded ? move_uploaded_file( $tmp_path, $dest ) : rename( $tmp_path, $dest ); // phpcs:ignore
		if ( ! $ok && ! $is_uploaded ) {
			$ok = copy( $tmp_path, $dest );
			wp_delete_file( $tmp_path );
		}
		if ( ! $ok ) {
			throw new \RuntimeException( 'storage_write_failed' );
		}
		chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $name;
	}

	public static function path( string $storage_name ): ?string {
		if ( ! preg_match( '/^[0-9a-z]{40}$/', $storage_name ) ) {
			return null;
		}
		$path = self::dir() . '/' . $storage_name;
		return is_file( $path ) ? $path : null;
	}

	public static function delete( string $storage_name ): void {
		$path = self::path( $storage_name );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * URL a public probe would hit if storage were web-accessible (for diagnostics).
	 */
	public static function public_probe_url(): ?string {
		if ( defined( 'GAPBAN_STORAGE_DIR' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$name    = get_option( self::OPTION );
		return $name ? trailingslashit( $uploads['baseurl'] ) . $name . '/index.php' : null;
	}
}
