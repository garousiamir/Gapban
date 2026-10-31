<?php
/**
 * Composition root: wires hooks for every module.
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Persistence\Schema;
use Gapban\Queue\Worker;
use Gapban\Routing\Delivery;
use Gapban\Routing\InboundProcessor;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		load_plugin_textdomain( 'gapban', false, dirname( plugin_basename( GAPBAN_FILE ) ) . '/languages' );

		// Upgrades after a plugin update (activation hooks do not run on update).
		if ( (int) get_option( Schema::OPTION, 0 ) < GAPBAN_DB_VERSION ) {
			Installer::upgrade();
		}

		Worker::register( 'deliver_message', array( Delivery::class, 'handle' ) );
		Worker::register( 'process_inbound', array( InboundProcessor::class, 'handle_job' ) );
		Worker::register( 'notify', array( InboundProcessor::class, 'handle_notify' ) );
		add_action( 'gapban_job_failed', array( Delivery::class, 'on_job_failed' ), 10, 3 );

		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'cron_schedules', array( Cron::class, 'schedules' ) );
		add_action( Cron::WORKER_HOOK, array( Cron::class, 'tick' ) );
		add_action( Cron::MAINTENANCE_HOOK, array( Maintenance::class, 'run' ) );

		\Gapban\Privacy\PrivacyTools::register();

		if ( is_admin() ) {
			\Gapban\Admin\AdminMenu::register();
		} else {
			\Gapban\Widget\Frontend::register();
		}
	}

	public static function routes(): void {
		\Gapban\Rest\VisitorController::register();
		\Gapban\Rest\WebhookController::register();
		\Gapban\Rest\InboxController::register();
		\Gapban\Rest\ManageController::register();
	}
}
