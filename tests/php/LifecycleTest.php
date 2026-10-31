<?php
/**
 * Install, migration, deactivate/reactivate and uninstall behaviour.
 */

namespace Gapban\Tests;

use Gapban\Core\Installer;
use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Persistence\Schema;

final class LifecycleTest extends TestCase {

	private function table_count(): int {
		global $wpdb;
		return count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'gapban_' ) . '%' ) ) );
	}

	public function test_fresh_install_creates_all_tables_and_caps(): void {
		Schema::drop_all();
		$this->assertSame( 0, $this->table_count() );
		Installer::activate();
		$this->assertSame( count( Schema::TABLES ), $this->table_count() );
		$this->assertSame( GAPBAN_DB_VERSION, (int) get_option( Schema::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'gapban_manage_settings' ) );
		$this->assertTrue( null !== get_role( 'gapban_operator' ) );
		$this->assertSame( 1, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'teams' ) ), 'default team from migration 3' );
		$this->assertTrue( false !== wp_next_scheduled( 'gapban_worker' ) );
	}

	public function test_migration_from_v1_backfills_routing_snapshot(): void {
		$v = $this->visitor_with_message( 'legacy' );
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . " SET routing_snapshot = ''" );
		update_option( Schema::OPTION, 1 );
		Schema::migrate();
		$snap = json_decode( (string) $this->conversation_row( $v['conversation']['id'] )['routing_snapshot'], true );
		$this->assertSame( 'reply', $snap['mode'] );
		$this->assertTrue( ! empty( $snap['legacy'] ) );
		$this->assertSame( GAPBAN_DB_VERSION, (int) get_option( Schema::OPTION ) );
		// Running again is a no-op.
		Schema::migrate();
		$this->assertSame( count( Schema::TABLES ), $this->table_count() );
	}

	public function test_deactivate_keeps_data_and_stops_cron_then_reactivate(): void {
		$v = $this->visitor_with_message( 'keep me' );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( 'gapban_worker' ) );
		$this->assertSame( 1, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) ) );
		Installer::activate();
		$this->assertTrue( false !== wp_next_scheduled( 'gapban_worker' ) );
		$this->assertSame( 'keep me', Db::value( 'SELECT body FROM ' . Db::t( 'messages' ) ) );
		unset( $v );
	}

	public function test_uninstall_preserves_data_by_default(): void {
		$this->visitor_with_message( 'still here' );
		Installer::uninstall();
		$this->assertSame( count( Schema::TABLES ), $this->table_count() );
		$this->assertSame( 1, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) ) );
		Installer::activate();
	}

	public function test_uninstall_with_opt_in_deletes_everything_and_only_our_webhook(): void {
		$this->visitor_with_message( 'bye' );
		add_filter( 'rest_url', $https = static fn( $u ) => str_replace( 'http://', 'https://', $u ) );
		\Gapban\Bale\WebhookManager::install( new \Gapban\Bale\BaleClient(), true );
		$this->assertContains( 'gapban/v1/bale/webhook', (string) $this->bale->webhook_url );
		Settings::update( array( 'delete_data_on_uninstall' => true ) );
		Installer::uninstall();
		remove_filter( 'rest_url', $https );
		$this->assertSame( 0, $this->table_count() );
		$this->assertSame( '', (string) $this->bale->webhook_url, 'our webhook removed' );
		$this->assertFalse( get_option( Settings::SECRETS_OPTION ) );
		$this->assertSame( null, get_role( 'gapban_operator' ) );
		Installer::activate();
		$this->assertSame( count( Schema::TABLES ), $this->table_count() );
	}

	public function test_uninstall_never_removes_a_foreign_webhook(): void {
		$this->bale->webhook_url = 'https://someone-else.example/hook';
		Settings::update( array( 'delete_data_on_uninstall' => true ) );
		Installer::uninstall();
		$this->assertSame( 'https://someone-else.example/hook', $this->bale->webhook_url );
		Installer::activate();
	}
}
