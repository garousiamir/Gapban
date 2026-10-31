<?php
/**
 * Activation, upgrade, deactivation and uninstall.
 *
 * - Deactivation keeps all data and only stops scheduled work.
 * - Uninstall keeps data unless "delete data on uninstall" was explicitly enabled.
 * - Uninstall removes the Bale webhook only if it still points at this site's secret URL.
 * - Multisite: each site has its own tables/options; activation is per site
 *   (network activation is refused, see docs/compatibility.md).
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Persistence\Schema;
use Gapban\Security\AttachmentStorage;
use Gapban\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			deactivate_plugins( plugin_basename( GAPBAN_FILE ), true, true );
			wp_die(
				esc_html__( 'Gapban must be activated on each site individually; network activation is not supported.', 'gapban' ),
				esc_html__( 'Activation refused', 'gapban' ),
				array( 'back_link' => true )
			);
		}
		self::upgrade();
		Cron::schedule();
		AttachmentStorage::dir();
	}

	public static function upgrade(): void {
		Schema::migrate();
		Capabilities::install();
		Cron::schedule();
		update_option( 'gapban_version', GAPBAN_VERSION, false );
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}

	public static function uninstall(): void {
		Cron::unschedule();
		$settings = get_option( Settings::OPTION, array() );
		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}
		\Gapban\Bale\WebhookManager::remove_if_owned();

		$dir = AttachmentStorage::dir();
		if ( ! defined( 'GAPBAN_STORAGE_DIR' ) && is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $file ) {
				if ( is_file( $dir . '/' . $file ) ) {
					wp_delete_file( $dir . '/' . $file );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		Schema::drop_all();
		Capabilities::uninstall();
		foreach ( array(
			Settings::OPTION,
			Settings::SECRETS_OPTION,
			'gapban_version',
			'gapban_storage_dir',
			'gapban_bot_identity',
			'gapban_capability_observations',
			'gapban_webhook_owned',
			'gapban_poll_offset',
			'gapban_token_invalid_at',
			'gapban_test_message',
			'gapban_test_reply_at',
			'gapban_last_worker_run',
			'gapban_maintenance_at',
			'gapban_config_version',
		) as $option ) {
			delete_option( $option );
		}
	}
}
