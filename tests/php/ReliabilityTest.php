<?php
/**
 * Queue, concurrency and provider failure behaviour.
 */

namespace Gapban\Tests;

use Gapban\Chat\Assignment;
use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Queue\JobQueue;

final class ReliabilityTest extends TestCase {

	private function make_jobs_due(): void {
		Db::exec( 'UPDATE ' . Db::t( 'jobs' ) . " SET available_at = %s WHERE status = 'pending'", Db::at( time() - 1 ) );
	}

	public function test_twenty_simultaneous_visitors_have_isolated_histories(): void {
		$this->operator( '777' );
		$visitors = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$visitors[ $i ] = $this->visitor_with_message( "visitor-$i question" );
		}
		$this->work();
		$sent = $this->bale->sent();
		$this->assertCount( 20, $sent );
		// Answer each relayed message in reverse order.
		foreach ( array_reverse( $sent, true ) as $i => $call ) {
			preg_match( '/visitor-(\d+) question/', $call['params']['text'], $m );
			$this->webhook( FakeBale::update( 1000 + $i, $this->group, '777', 'answer-for-' . $m[1], (string) ( 1000 + $i ) ) );
		}
		$this->work();
		foreach ( $visitors as $i => $v ) {
			$bodies = array_column( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'], 'body' );
			$this->assertSame( array( "visitor-$i question", "answer-for-$i" ), $bodies, "visitor $i" );
		}
		// A visitor can never read another visitor's conversation: there is no id parameter at all.
		$other = $this->as_visitor( $visitors[0]['token'], 'GET', '/messages', array( 'after' => 0, 'conversation' => $visitors[1]['conversation']['id'] ) );
		$this->assertSame( array( 'visitor-0 question', 'answer-for-0' ), array_column( $other->get_data()['messages'], 'body' ) );
	}

	public function test_idempotent_visitor_send_with_same_client_key(): void {
		$token = $this->visitor();
		$this->as_visitor( $token, 'POST', '/conversation', array() );
		$a = $this->as_visitor( $token, 'POST', '/messages', array( 'body' => 'once', 'client_key' => 'same-key-123' ) )->get_data();
		$b = $this->as_visitor( $token, 'POST', '/messages', array( 'body' => 'once', 'client_key' => 'same-key-123' ) )->get_data();
		$this->assertSame( $a['message']['id'], $b['message']['id'] );
		$this->work();
		$this->assertCount( 1, $this->bale->sent() );
	}

	public function test_per_conversation_order_is_preserved_through_retries(): void {
		$v = $this->visitor_with_message( 'm1' );
		$this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'm2', 'client_key' => 'order-key-2' ) );
		$this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'm3', 'client_key' => 'order-key-3' ) );
		$fail = true;
		$this->bale->overrides['sendMessage'] = function () use ( &$fail ) {
			if ( $fail ) {
				$fail = false;
				return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
			return null;
		};
		$this->work(); // m1 fails; m2/m3 must not overtake it.
		$this->assertCount( 1, $this->bale->sent() );
		$this->make_jobs_due();
		$this->work();
		$texts = array_map( static fn( $c ) => $c['params']['text'], array_slice( $this->bale->sent(), 1 ) );
		$this->assertContains( 'm1', $texts[0] );
		$this->assertContains( 'm2', $texts[1] );
		$this->assertContains( 'm3', $texts[2] );
	}

	public function test_concurrent_claims_have_exactly_one_winner(): void {
		$a = $this->operator( '1', 'A' );
		$b = $this->operator( '2', 'B' );
		$v = $this->visitor_with_message( 'x' );
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		$results = array( Assignment::claim( $cid, $a, 'test', 0 ), Assignment::claim( $cid, $b, 'test', 0 ) );
		$this->assertSame( array( true, false ), $results );
		// Transfer with a stale expectation fails.
		$this->assertFalse( Assignment::transfer( $cid, $b, $a, 'test', 0 ) );
		$this->assertTrue( Assignment::transfer( $cid, $a, $b, 'test', 0 ) );
	}

	public function test_parallel_claims_from_separate_processes(): void {
		$a   = $this->operator( '1', 'A' );
		$b   = $this->operator( '2', 'B' );
		$v   = $this->visitor_with_message( 'x' );
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		$script = sprintf(
			'define("GAPBAN_DISABLE_INLINE_WORKER",true);$_SERVER["HTTP_HOST"]="localhost";require "%s/wp-load.php";usleep(200000);echo \\Gapban\\Chat\\Assignment::claim(%d,(int)$argv[1],"test",0)?"won":"lost";',
			getenv( 'WP_PATH' ) ?: '/tmp/claude-0/wp',
			$cid
		);
		$env   = 'WP_DB_NAME=wptest ';
		$procs = array();
		foreach ( array( $a, $b ) as $op ) {
			$procs[] = popen( $env . 'php -r ' . escapeshellarg( $script ) . ' ' . $op, 'r' );
		}
		$out = array_map( static fn( $p ) => trim( stream_get_contents( $p ) ), $procs );
		array_map( 'pclose', $procs );
		sort( $out );
		$this->assertSame( array( 'lost', 'won' ), $out );
	}

	public function test_parallel_workers_never_double_send(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->visitor_with_message( "parallel-$i" );
		}
		$script = sprintf(
			'define("GAPBAN_DISABLE_INLINE_WORKER",true);$_SERVER["HTTP_HOST"]="localhost";require "%s/wp-load.php";require "%s/FakeBale.php";$f=new \\Gapban\\Tests\\FakeBale();$f->install();\\Gapban\\Queue\\Worker::run(5,100);echo count($f->sent());',
			getenv( 'WP_PATH' ) ?: '/tmp/claude-0/wp',
			__DIR__
		);
		$procs = array();
		for ( $p = 0; $p < 3; $p++ ) {
			$procs[] = popen( 'WP_DB_NAME=wptest php -r ' . escapeshellarg( $script ), 'r' );
		}
		$counts = array_map( static fn( $p ) => (int) trim( stream_get_contents( $p ) ), $procs );
		array_map( 'pclose', $procs );
		$this->assertSame( 6, array_sum( $counts ), 'total sends across workers: ' . implode( ',', $counts ) );
		$this->assertSame( 6, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE delivery_status = 'sent'" ) );
	}

	public function test_worker_crash_recovery_marks_uncertain_and_retries(): void {
		$v = $this->visitor_with_message( 'crash me' );
		// Simulate a worker that claimed the job and died.
		$jobs = JobQueue::claim( 'deadworker00000000000000000000aa', 5 );
		$this->assertCount( 1, $jobs );
		Db::exec( 'UPDATE ' . Db::t( 'jobs' ) . ' SET locked_until = %s', Db::at( time() - 10 ) );
		$this->work(); // recover_expired() runs first.
		$this->assertCount( 1, $this->bale->sent() );
		$msg = Db::row( 'SELECT delivery_status, meta FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] );
		$this->assertSame( 'sent', $msg['delivery_status'] );
		$this->assertContains( 'possible_duplicate', (string) $msg['meta'] );
	}

	public function test_uncertain_send_with_hold_policy_goes_to_dead_letter(): void {
		Settings::update( array( 'uncertain_send_policy' => 'hold' ) );
		$v = $this->visitor_with_message( 'timeout' );
		$this->bale->overrides['sendMessage'] = static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
		$this->work();
		$job = Db::row( 'SELECT status, uncertain FROM ' . Db::t( 'jobs' ) . ' LIMIT 1' );
		$this->assertSame( 'dead', $job['status'] );
		$this->assertSame( '1', (string) $job['uncertain'] );
		$this->assertSame( 'uncertain', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
		// Authorized retry.
		$this->admin();
		unset( $this->bale->overrides['sendMessage'] );
		$id  = (int) Db::value( 'SELECT id FROM ' . Db::t( 'jobs' ) . ' LIMIT 1' );
		$res = $this->rest( 'POST', '/admin/queue/' . $id . '/retry' );
		$this->assertTrue( $res->get_data()['retried'] );
		$this->work();
		$this->assertSame( 'sent', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
	}

	public function test_provider_outage_then_recovery(): void {
		$v = $this->visitor_with_message( 'during outage' );
		$this->bale->overrides['sendMessage'] = static fn() => array( '__status' => 502, 'ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway' );
		Settings::update( array( 'uncertain_send_policy' => 'retry' ) );
		$this->work();
		$this->assertSame( 'uncertain', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
		unset( $this->bale->overrides['sendMessage'] );
		$this->make_jobs_due();
		$this->work();
		$this->assertSame( 'sent', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
	}

	public function test_rate_limit_honours_retry_after_without_burning_attempts(): void {
		$this->visitor_with_message( 'rl' );
		$this->bale->overrides['sendMessage'] = static fn() => array( '__status' => 429, 'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => array( 'retry_after' => 30 ) );
		$this->work();
		$job = Db::row( 'SELECT status, attempts, available_at FROM ' . Db::t( 'jobs' ) . ' LIMIT 1' );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( '0', (string) $job['attempts'] );
		$delay = strtotime( $job['available_at'] . ' UTC' ) - time();
		$this->assertTrue( $delay >= 29 && $delay <= 33, 'delay ' . $delay );
	}

	public function test_invalid_token_is_permanent_and_flagged(): void {
		$v = $this->visitor_with_message( 'x' );
		\Gapban\Core\Settings::set_secret( 'bot_token', '123456789:WRONGWRONGWRONGWRONG' );
		\Gapban\Routing\RoutingRegistry::set_provider( null );
		$this->work();
		$this->assertSame( 'dead', Db::value( 'SELECT status FROM ' . Db::t( 'jobs' ) . ' LIMIT 1' ) );
		$this->assertTrue( '' !== (string) get_option( 'gapban_token_invalid_at', '' ) );
		$this->assertSame( 'failed', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
	}

	public function test_deleted_group_is_permanent_failure(): void {
		$v = $this->visitor_with_message( 'x' );
		$this->bale->overrides['sendMessage'] = static fn() => array( '__status' => 400, 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found' );
		$this->work();
		$this->assertSame( 'failed', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
		// The visitor's message is still stored and visible in WordPress.
		$this->admin();
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		$msgs = $this->rest( 'GET', '/admin/conversations/' . $cid . '/messages' )->get_data()['messages'];
		$this->assertSame( 'x', $msgs[0]['body'] );
	}

	public function test_malformed_provider_response_is_uncertain_for_sends(): void {
		$this->visitor_with_message( 'x' );
		$this->bale->overrides['sendMessage'] = static fn() => array( '__status' => 200, 'garbage' => true );
		$this->work();
		$this->assertSame( '1', (string) Db::value( 'SELECT uncertain FROM ' . Db::t( 'jobs' ) . ' LIMIT 1' ) );
	}

	public function test_orphaned_queued_message_is_requeued(): void {
		$v = $this->visitor_with_message( 'orphan' );
		Db::exec( 'DELETE FROM ' . Db::t( 'jobs' ) );
		Db::exec( 'UPDATE ' . Db::t( 'messages' ) . ' SET created_at = %s', Db::at( time() - 600 ) );
		$this->assertSame( 1, \Gapban\Routing\Delivery::requeue_orphans() );
		$this->work();
		$this->assertSame( 'sent', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
	}

	public function test_polling_records_before_advancing_offset_and_refuses_with_webhook(): void {
		Settings::update( array( 'transport' => 'polling' ) );
		$this->operator( '777' );
		$v = $this->visitor_with_message( 'poll me' );
		$this->work();
		$this->bale->pending_updates = array( FakeBale::update( 500, $this->group, '777', 'polled answer', '1000' ) );
		$r = \Gapban\Bale\Poller::run( 2.0 );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( '500', get_option( \Gapban\Bale\Poller::OFFSET_OPTION ) );
		$this->work();
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'polled answer', end( $msgs )['body'] );
		// The next poll acknowledges with offset 501.
		\Gapban\Bale\Poller::run( 0.5 );
		$this->assertSame( 501, $this->bale->offset_seen );

		$this->bale->webhook_url = 'https://other.example/hook';
		$this->assertSame( 'webhook_active', \Gapban\Bale\Poller::run( 0.5 )['status'] );
	}

	public function test_overlapping_pollers_are_prevented(): void {
		$owner = \Gapban\Security\Locks::acquire( 'bale_poller', 60 );
		$this->assertTrue( null !== $owner );
		$this->assertSame( 'already_running', \Gapban\Bale\Poller::run( 0.5 )['status'] );
		\Gapban\Security\Locks::release( 'bale_poller', $owner );
	}

	public function test_webhook_install_requires_https(): void {
		$this->admin();
		$res = $this->rest( 'POST', '/admin/connection/webhook', array( 'action' => 'install' ) );
		$this->assertSame( 502, $res->get_status() );
		$this->assertSame( '', (string) $this->bale->webhook_url );
	}

	public function test_webhook_install_refuses_to_replace_foreign_webhook(): void {
		$this->admin();
		$https = static fn( $u ) => str_replace( 'http://', 'https://', $u );
		add_filter( 'rest_url', $https );
		$this->bale->webhook_url = 'https://another-app.example/bale';
		$res = $this->rest( 'POST', '/admin/connection/webhook', array( 'action' => 'install' ) );
		$this->assertSame( 'foreign_webhook', $res->get_data()['reason'] );
		$this->assertSame( 'https://another-app.example/bale', $this->bale->webhook_url );
		// Removal never touches a foreign webhook.
		$res = $this->rest( 'POST', '/admin/connection/webhook', array( 'action' => 'remove' ) );
		$this->assertFalse( $res->get_data()['removed'] );
		$this->assertSame( 'https://another-app.example/bale', $this->bale->webhook_url );
		// Our own webhook installs (with explicit confirmation) and is then removable.
		$res = $this->rest( 'POST', '/admin/connection/webhook', array( 'action' => 'install', 'force' => true ) );
		$this->assertTrue( $res->get_data()['installed'] );
		$this->assertContains( '/gapban/v1/bale/webhook/', $this->bale->webhook_url );
		$res = $this->rest( 'POST', '/admin/connection/webhook', array( 'action' => 'remove' ) );
		$this->assertTrue( $res->get_data()['removed'] );
		remove_filter( 'rest_url', $https );
	}
}
