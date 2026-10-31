<?php
/**
 * Base class: resets Gapban state, installs FakeBale, offers REST helpers.
 */

namespace Gapban\Tests;

use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Persistence\Schema;
use Gapban\Queue\Worker;
use Gapban\Routing\RoutingRegistry;

abstract class TestCase {

	protected FakeBale $bale;
	protected string $group = '-1001234567890';

	public function set_up(): void {
		global $wpdb;
		foreach ( Schema::TABLES as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) );
		}
		foreach ( array( Settings::OPTION, Settings::SECRETS_OPTION, 'gapban_bot_identity', 'gapban_capability_observations', 'gapban_webhook_owned', 'gapban_poll_offset', 'gapban_test_message', 'gapban_test_reply_at', 'gapban_token_invalid_at' ) as $o ) {
			delete_option( $o );
		}
		wp_cache_flush();
		Settings::flush();
		RoutingRegistry::set_provider( null );
		Db::insert( 'teams', array( 'name' => 'General', 'slug' => 'general', 'assignment_strategy' => 'manual', 'active' => 1, 'created_at' => Db::now() ) );

		$this->bale = new FakeBale();
		$this->bale->install();
		Settings::set_secret( 'bot_token', FakeBale::TOKEN );
		Settings::update( array( 'default_destination_chat' => $this->group, 'default_destination_title' => 'Support' ) );
		( new \Gapban\Bale\BaleProvider() )->verify();
		$this->bale->calls = array();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		if ( getenv( 'GAPBAN_DEBUG' ) ) {
			print_r( Db::rows( 'SELECT update_id, status, error FROM ' . Db::t( 'inbound_updates' ) ) );
			print_r( Db::rows( 'SELECT id, type, status, last_error FROM ' . Db::t( 'jobs' ) . " WHERE status <> 'done'" ) );
		}
		$this->bale->uninstall();
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_X_GAPBAN_SESSION'] );
	}

	/* ------------------------------ helpers ------------------------------ */

	protected function rest( string $method, string $path, array $params = array(), array $headers = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/gapban/v1' . $path );
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	protected function visitor(): string {
		$res = $this->rest( 'POST', '/session' );
		$this->assertSame( 200, $res->get_status(), 'session create' );
		return $res->get_data()['token'];
	}

	protected function as_visitor( string $token, string $method, string $path, array $params = array() ): \WP_REST_Response {
		return $this->rest( $method, $path, $params, array( 'X-Gapban-Session' => $token ) );
	}

	/**
	 * Start a conversation and send a message as a fresh visitor.
	 *
	 * @return array{token:string, conversation:array, message:array}
	 */
	protected function visitor_with_message( string $text = 'سلام، سؤال دارم', array $prechat = array() ): array {
		$token = $this->visitor();
		$res   = $this->as_visitor( $token, 'POST', '/conversation', $prechat + array( 'name' => 'Ali', 'page_url' => 'https://example.com/pricing?utm_source=x&token=secret' ) );
		$this->assertSame( 200, $res->get_status(), 'conversation start: ' . wp_json_encode( $res->get_data() ) );
		$msg = $this->as_visitor( $token, 'POST', '/messages', array( 'body' => $text, 'client_key' => 'k' . wp_generate_password( 12, false ) ) );
		$this->assertSame( 200, $msg->get_status(), 'send: ' . wp_json_encode( $msg->get_data() ) );
		return array(
			'token'        => $token,
			'conversation' => $res->get_data()['conversation'],
			'message'      => $msg->get_data()['message'],
		);
	}

	protected function work(): array {
		return Worker::run( 10.0, 500 );
	}

	protected function operator( string $bale_id, string $name = 'Maryam', ?int $wp_user = null ): int {
		return Db::insert(
			'operators',
			array(
				'display_name' => $name,
				'bale_user_id' => $bale_id,
				'wp_user_id'   => $wp_user,
				'status'       => 'active',
				'availability' => 'online',
				'created_at'   => Db::now(),
			)
		);
	}

	/**
	 * Feed an update through the real webhook endpoint.
	 */
	protected function webhook( array $update ): \WP_REST_Response {
		$secret  = \Gapban\Bale\WebhookManager::secret();
		$request = new \WP_REST_Request( 'POST', '/gapban/v1/bale/webhook/' . $secret );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $update ) );
		return rest_do_request( $request );
	}

	protected function conversation_row( string $public_id ): array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE public_id = %s', $public_id );
	}

	protected function admin(): int {
		$uid = (int) username_exists( 'admin' );
		wp_set_current_user( $uid );
		return $uid;
	}

	/* ----------------------------- assertions ---------------------------- */

	protected function assertTrue( $v, string $msg = '' ): void {
		if ( true !== $v ) {
			throw new \RuntimeException( 'Expected true. ' . $msg );
		}
	}

	protected function assertFalse( $v, string $msg = '' ): void {
		if ( false !== $v ) {
			throw new \RuntimeException( 'Expected false. ' . $msg );
		}
	}

	protected function assertSame( $expected, $actual, string $msg = '' ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( sprintf( 'Expected %s, got %s. %s', var_export( $expected, true ), var_export( $actual, true ), $msg ) );
		}
	}

	protected function assertCount( int $n, array $list, string $msg = '' ): void {
		$this->assertSame( $n, count( $list ), $msg );
	}

	protected function assertContains( string $needle, string $hay, string $msg = '' ): void {
		if ( false === strpos( $hay, $needle ) ) {
			throw new \RuntimeException( sprintf( 'Expected "%s" to contain "%s". %s', mb_substr( $hay, 0, 300 ), $needle, $msg ) );
		}
	}

	protected function assertNotContains( string $needle, string $hay, string $msg = '' ): void {
		if ( false !== strpos( $hay, $needle ) ) {
			throw new \RuntimeException( sprintf( 'Did not expect "%s" in "%s". %s', $needle, mb_substr( $hay, 0, 300 ), $msg ) );
		}
	}
}
