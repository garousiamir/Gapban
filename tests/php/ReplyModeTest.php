<?php
/**
 * Reply Mode end-to-end behaviour against FakeBale (internal behaviour only).
 */

namespace Gapban\Tests;

use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Routing\ReplyRouter;

final class ReplyModeTest extends TestCase {

	public function test_visitor_message_is_relayed_with_reference_and_mapped(): void {
		$v = $this->visitor_with_message( 'سلام، قیمت پلن حرفه‌ای چند است؟' );
		$this->work();
		$sent = $this->bale->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( $this->group, (string) $sent[0]['params']['chat_id'] );
		$this->assertContains( $v['conversation']['reference'], $sent[0]['params']['text'] );
		$this->assertContains( 'قیمت پلن حرفه‌ای', $sent[0]['params']['text'] );
		// Sensitive query parameters are stripped from the page context.
		$this->assertNotContains( 'secret', $sent[0]['params']['text'] );
		$this->assertContains( 'utm_source=x', $sent[0]['params']['text'] );

		$map = Db::row( 'SELECT * FROM ' . Db::t( 'provider_mappings' ) . ' WHERE provider_message_id = %s', '1000' );
		$this->assertSame( $this->group, $map['chat_id'] );
		$msg = Db::row( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] );
		$this->assertSame( 'sent', $msg['delivery_status'] );
	}

	public function test_operator_reply_round_trip_reaches_only_that_visitor(): void {
		$this->operator( '777' );
		$a = $this->visitor_with_message( 'from A' );
		$b = $this->visitor_with_message( 'from B' );
		$this->work();
		$sent = $this->bale->sent();
		$a_mid = (string) ( 1000 );
		$b_mid = (string) ( 1001 );
		$this->assertContains( 'from A', $sent[0]['params']['text'] );

		$res = $this->webhook( FakeBale::update( 1, $this->group, '777', 'پاسخ به الف', $a_mid ) );
		$this->assertSame( 200, $res->get_status() );
		$this->work();

		$a_msgs = $this->as_visitor( $a['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$b_msgs = $this->as_visitor( $b['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'پاسخ به الف', end( $a_msgs )['body'] );
		$this->assertSame( 'operator', end( $a_msgs )['author'] );
		$this->assertSame( 'Maryam', end( $a_msgs )['author_name'] );
		foreach ( $b_msgs as $m ) {
			$this->assertNotContains( 'پاسخ به الف', $m['body'], 'leak into B' );
		}
		unset( $b_mid );
	}

	public function test_reply_to_older_message_and_to_operator_message_routes_correctly(): void {
		$this->operator( '777' );
		$v = $this->visitor_with_message( 'first' );
		$this->work();
		$this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'second', 'client_key' => 'second-key-1' ) );
		$this->work();
		// Reply to the FIRST (older) relayed message.
		$this->webhook( FakeBale::update( 10, $this->group, '777', 'answer one', '1000' ) );
		$this->work();
		// Reply to the operator's own previous Bale message (50010) — must resolve too.
		$this->webhook( FakeBale::update( 11, $this->group, '777', 'answer two', '50010' ) );
		$this->work();
		$bodies = array_column( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'], 'body' );
		$this->assertTrue( in_array( 'answer one', $bodies, true ), 'older reply' );
		$this->assertTrue( in_array( 'answer two', $bodies, true ), 'reply to operator message' );
	}

	public function test_long_message_is_chunked_and_every_chunk_resolves(): void {
		Settings::update( array( 'max_text_chunk' => 500 ) );
		$this->operator( '777' );
		$long = str_repeat( 'کلمه‌ای طولانی ', 120 );
		$v    = $this->visitor_with_message( $long );
		$this->work();
		$sent = $this->bale->sent();
		$this->assertTrue( count( $sent ) >= 3, 'chunks: ' . count( $sent ) );
		$parts = Db::rows( 'SELECT part_index, provider_message_id FROM ' . Db::t( 'provider_mappings' ) . ' WHERE message_id = %d ORDER BY part_index', $v['message']['id'] );
		$this->assertCount( count( $sent ), $parts );
		// Reply to the LAST chunk.
		$last = end( $parts )['provider_message_id'];
		$this->webhook( FakeBale::update( 20, $this->group, '777', 'reply to chunk', $last ) );
		$this->work();
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'reply to chunk', end( $msgs )['body'] );
	}

	public function test_partial_chunk_failure_resumes_without_resending(): void {
		Settings::update( array( 'max_text_chunk' => 500 ) );
		$v     = $this->visitor_with_message( str_repeat( 'abc def ', 200 ) );
		$count = 0;
		$this->bale->overrides['sendMessage'] = function () use ( &$count ) {
			++$count;
			return 2 === $count ? new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : null;
		};
		$this->work();
		Db::exec( 'UPDATE ' . Db::t( 'jobs' ) . " SET available_at = %s WHERE status = 'pending'", Db::at( time() - 1 ) );
		$this->work();
		$calls = $this->bale->sent();
		unset( $calls[1] ); // The failed connection attempt never reached Bale.
		$texts = array_map( static fn( $c ) => $c['params']['text'], $calls );
		$this->assertSame( count( $texts ), count( array_unique( $texts ) ), 'no chunk sent twice' );
		$this->assertSame( 'sent', Db::value( 'SELECT delivery_status FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $v['message']['id'] ) );
	}

	public function test_media_reply_is_downloaded_and_attached(): void {
		$this->operator( '777' );
		$v   = $this->visitor_with_message( 'send me the invoice' );
		$this->work();
		$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
		$this->bale->files['documents/FILE1'] = $pdf;
		$update = FakeBale::update( 30, $this->group, '777', '', '1000', array( 'document' => array( 'file_id' => 'FILE1', 'file_name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen( $pdf ) ), 'caption' => 'فاکتور' ) );
		$this->webhook( $update );
		$this->work();
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$last = end( $msgs );
		$this->assertSame( 'فاکتور', $last['body'] );
		$this->assertCount( 1, $last['attachments'] );
		$this->assertSame( 'application/pdf', $last['attachments'][0]['mime'] );
	}

	public function test_reply_to_media_message_resolves(): void {
		$this->operator( '777' );
		$v = $this->visitor_with_message( 'text first' );
		$this->work();
		// Simulate a relayed photo mapping as attachment part.
		$router = new ReplyRouter( \Gapban\Routing\RoutingRegistry::provider() );
		$conv   = $this->conversation_row( $v['conversation']['id'] );
		$router->map( FakeBale::BOT_ID, $this->group, '4242', (int) $conv['id'], (int) $v['message']['id'], ReplyRouter::ATTACHMENT_PART_OFFSET, 'out' );
		$this->webhook( FakeBale::update( 31, $this->group, '777', 'about your photo', '4242' ) );
		$this->work();
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'about your photo', end( $msgs )['body'] );
	}

	public function test_unmapped_reply_is_rejected_with_feedback_and_no_fallback(): void {
		$this->operator( '777' );
		$v = $this->visitor_with_message( 'hello' );
		$this->work();
		$this->bale->calls = array();
		$this->webhook( FakeBale::update( 40, $this->group, '777', 'reply to nothing', '99999' ) );
		$this->webhook( FakeBale::update( 41, $this->group, '777', 'not a reply at all' ) );
		$this->work();
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		foreach ( $msgs as $m ) {
			$this->assertNotContains( 'reply to nothing', $m['body'] );
			$this->assertNotContains( 'not a reply', $m['body'] );
		}
		$this->assertCount( 2, $this->bale->sent(), 'two feedback notices' );
		$this->assertContains( '🤖', $this->bale->sent()[0]['params']['text'] );
	}

	public function test_unauthorized_sender_and_unapproved_chat_are_ignored(): void {
		$v = $this->visitor_with_message( 'hello' );
		$this->work();
		$this->webhook( FakeBale::update( 50, $this->group, '666', 'I am not an operator', '1000' ) );
		$this->operator( '777' );
		$this->webhook( FakeBale::update( 51, '-100999', '777', 'from another group', '1000' ) );
		$this->webhook( FakeBale::update( 52, $this->group, '888', 'bot echo', '1000', array( 'from' => array( 'id' => 888, 'is_bot' => true, 'first_name' => 'bot' ) ) ) );
		$this->work();
		$bodies = array_column( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'], 'body' );
		$this->assertCount( 1, $bodies );
		$statuses = Db::rows( 'SELECT update_id, error FROM ' . Db::t( 'inbound_updates' ) . ' ORDER BY id' );
		$this->assertSame( 'unknown_sender', $statuses[0]['error'] );
		$this->assertSame( 'chat_not_approved', $statuses[1]['error'] );
		$this->assertSame( 'bot_sender', $statuses[2]['error'] );
	}

	public function test_forged_webhook_with_wrong_secret_is_rejected(): void {
		$request = new \WP_REST_Request( 'POST', '/gapban/v1/bale/webhook/' . str_repeat( 'A', 43 ) );
		$request->set_body( wp_json_encode( FakeBale::update( 60, $this->group, '777', 'x', '1000' ) ) );
		$res = rest_do_request( $request );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'inbound_updates' ) ) );
	}

	public function test_oversized_webhook_body_is_rejected(): void {
		$request = new \WP_REST_Request( 'POST', '/gapban/v1/bale/webhook/' . \Gapban\Bale\WebhookManager::secret() );
		$request->set_body( str_repeat( 'x', 1048577 ) );
		$this->assertSame( 413, rest_do_request( $request )->get_status() );
	}

	public function test_duplicate_and_out_of_order_updates(): void {
		$this->operator( '777' );
		$v = $this->visitor_with_message( 'hello' );
		$this->work();
		$later   = FakeBale::update( 71, $this->group, '777', 'second answer', '1000' );
		$earlier = FakeBale::update( 70, $this->group, '777', 'first answer', '1000' );
		$this->webhook( $later );
		$this->webhook( $earlier );
		$this->webhook( $later );   // duplicate
		$this->webhook( $earlier ); // duplicate
		$this->work();
		$bodies = array_column( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'], 'body' );
		$this->assertSame( 1, count( array_keys( $bodies, 'second answer', true ) ) );
		$this->assertSame( 1, count( array_keys( $bodies, 'first answer', true ) ) );
		$this->assertSame( 2, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'inbound_updates' ) ) );
	}

	public function test_routing_destination_change_preserves_existing_conversations(): void {
		$this->operator( '777' );
		$old = $this->visitor_with_message( 'old conversation' );
		$this->work();
		Settings::update( array( 'default_destination_chat' => '-100555' ) );
		$this->as_visitor( $old['token'], 'POST', '/messages', array( 'body' => 'follow-up', 'client_key' => 'follow-up-1' ) );
		$new = $this->visitor_with_message( 'new conversation' );
		$this->work();
		$chats = array_map( static fn( $c ) => (string) $c['params']['chat_id'], $this->bale->sent() );
		$this->assertSame( array( $this->group, $this->group, '-100555' ), $chats );
		// Replies in the old group still resolve for the old conversation.
		$this->webhook( FakeBale::update( 80, $this->group, '777', 'still works', '1000' ) );
		$this->work();
		$msgs = $this->as_visitor( $old['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'still works', end( $msgs )['body'] );
		unset( $new );
	}

	public function test_topics_mode_cannot_be_selected(): void {
		$this->admin();
		$res = $this->rest( 'POST', '/admin/settings', array( 'routing_mode' => 'topics' ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'reply', Settings::get( 'routing_mode' ) );
		// Even if the option is forced, new conversations fall back to reply mode.
		update_option( Settings::OPTION, array_merge( Settings::all(), array( 'routing_mode' => 'topics' ) ) );
		Settings::flush();
		$v = $this->visitor_with_message( 'x' );
		$this->assertSame( 'reply', $this->conversation_row( $v['conversation']['id'] )['routing_mode'] );
	}

	public function test_operator_commands_claim_and_close(): void {
		$op = $this->operator( '777' );
		$this->operator( '778', 'Reza' );
		$v = $this->visitor_with_message( 'hi' );
		$this->work();
		$this->webhook( FakeBale::update( 90, $this->group, '777', '/claim', '1000' ) );
		$this->work();
		$this->assertSame( (string) $op, (string) $this->conversation_row( $v['conversation']['id'] )['assigned_operator_id'] );
		// Another operator cannot answer an assigned conversation.
		$this->webhook( FakeBale::update( 91, $this->group, '778', 'I will answer', '1000' ) );
		$this->work();
		$bodies = array_column( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'], 'body' );
		$this->assertFalse( in_array( 'I will answer', $bodies, true ) );
		$this->webhook( FakeBale::update( 92, $this->group, '777', '/close', '1000' ) );
		$this->work();
		$this->assertSame( 'closed', $this->conversation_row( $v['conversation']['id'] )['status'] );
	}

	public function test_operator_enrollment_one_time_code(): void {
		$this->admin();
		$op  = $this->operator( '', 'New Op' );
		Db::update( 'operators', array( 'bale_user_id' => null ), array( 'id' => $op ) );
		$res = $this->rest( 'POST', '/admin/operators/' . $op . '/enroll' );
		$code = $res->get_data()['code'];
		$this->webhook( FakeBale::update( 100, '555', '555', '/start ' . $code, null, array(), 'private' ) );
		$this->work();
		$this->assertSame( '555', (string) Db::value( 'SELECT bale_user_id FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', $op ) );
		// Reuse is rejected.
		$this->webhook( FakeBale::update( 101, '556', '556', '/start ' . $code, null, array(), 'private' ) );
		$this->work();
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'operators' ) . " WHERE bale_user_id = '556'" ) );
	}

	public function test_destination_binding_code(): void {
		$this->admin();
		$res  = $this->rest( 'POST', '/admin/teams/0/bind' );
		$code = $res->get_data()['code'];
		$this->webhook( FakeBale::update( 110, '-100777', '1', '/gapban_bind ' . $code ) );
		$this->work();
		$this->assertSame( '-100777', Settings::get( 'default_destination_chat' ) );
	}

	public function test_inbox_reply_is_mirrored_to_bale_and_counts_as_human_response(): void {
		$uid = $this->admin();
		$v   = $this->visitor_with_message( 'question' );
		$this->work();
		$conv = $this->conversation_row( $v['conversation']['id'] );
		$res  = $this->rest( 'POST', '/admin/conversations/' . $conv['id'] . '/messages', array( 'body' => 'answer from WP', 'client_key' => 'wpkey12345' ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->work();
		$last = end( $this->bale->calls );
		$this->assertContains( 'answer from WP', $last['params']['text'] );
		$this->assertSame( '1000', (string) $last['params']['reply_to_message_id'] );
		$this->assertTrue( null !== $this->conversation_row( $v['conversation']['id'] )['first_human_response_at'] );
		unset( $uid );
	}

	public function test_automated_greeting_is_labeled_and_not_human_response(): void {
		Settings::update( array( 'automated_greeting' => 'سلام! پیام خودکار' ) );
		$v    = $this->visitor_with_message( 'hi' );
		$msgs = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		$this->assertSame( 'automation', $msgs[0]['author'] );
		$this->assertSame( null, $this->conversation_row( $v['conversation']['id'] )['first_human_response_at'] );
	}

	public function test_test_message_reply_marks_receiving_verified(): void {
		$this->admin();
		$res = $this->rest( 'POST', '/admin/connection/test' );
		$this->assertSame( 200, $res->get_status() );
		$mid = $res->get_data()['message_id'];
		$this->webhook( FakeBale::update( 120, $this->group, '42', 'got it', $mid ) );
		$this->work();
		$this->assertTrue( '' !== (string) get_option( 'gapban_test_reply_at', '' ) );
	}
}
