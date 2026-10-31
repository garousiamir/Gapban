<?php
/**
 * Plugin Name:       Gapban | گپ‌بان
 * Description:       افزونه چت آنلاین وردپرس متصل به بله — visitors chat on your site, operators answer in Bale or the WordPress inbox.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            گپ‌بان
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gapban
 * Domain Path:       /languages
 *
 * @package Gapban
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'GAPBAN_VERSION' ) ) {
	// Another copy is already loaded; never redeclare.
	return;
}

define( 'GAPBAN_VERSION', '1.0.0' );
define( 'GAPBAN_DB_VERSION', 3 );
define( 'GAPBAN_FILE', __FILE__ );
define( 'GAPBAN_DIR', __DIR__ . '/' );
define( 'GAPBAN_URL', plugin_dir_url( __FILE__ ) );
define( 'GAPBAN_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, GAPBAN_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: minimum PHP version */
					__( 'Gapban requires PHP %s or newer. The plugin is inactive.', 'gapban' ),
					GAPBAN_MIN_PHP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'Gapban\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, 7 ) );
		$file     = GAPBAN_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \Gapban\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Gapban\Core\Installer::class, 'deactivate' ) );
register_uninstall_hook( __FILE__, array( \Gapban\Core\Installer::class, 'uninstall' ) );

add_action( 'plugins_loaded', array( \Gapban\Core\Plugin::class, 'boot' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'gapban', \Gapban\Core\Cli::class );
}
