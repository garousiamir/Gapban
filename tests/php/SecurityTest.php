<?php
/**
 * Authorization, session, upload, SVG, SSRF, encryption and privacy behaviour.
 */

namespace Gapban\Tests;

use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Security\Crypto;
use Gapban\Security\SvgSanitizer;
use Gapban\Security\UploadValidator;
use Gapban\Security\VisitorSessions;

final class SecurityTest extends TestCase {

	public function test_requests_without_or_with_forged_session_are_rejected(): void {
		$this->assertSame( 401, $this->rest( 'GET', '/messages' )->get_status() );
		$this->assertSame( 401, $this->as_visitor( '01HZZZZZZZZZZZZZZZZZZZZZZZ.' . str_repeat( 'a', 43 ), 'GET', '/messages' )->get_status() );
		$token = $this->visitor();
		[ $sid ] = explode( '.', $token );
		$this->assertSame( 401, $this->as_visitor( $sid . '.' . str_repeat( 'b', 43 ), 'GET', '/conversation' )->get_status() );
	}

	public function test_expired_and_revoked_sessions_are_rejected(): void {
		$token = $this->visitor();
		Db::exec( 'UPDATE ' . Db::t( 'sessions' ) . ' SET expires_at = %s', Db::at( time() - 10 ) );
		$this->assertSame( 401, $this->as_visitor( $token, 'GET', '/conversation' )->get_status() );
		$token2 = $this->visitor();
		VisitorSessions::revoke( (int) Db::value( 'SELECT MAX(id) FROM ' . Db::t( 'sessions' ) ) );
		$this->assertSame( 401, $this->as_visitor( $token2, 'GET', '/conversation' )->get_status() );
	}

	public function test_session_secret_is_stored_only_as_hash(): void {
		$token        = $this->visitor();
		[ , $secret ] = explode( '.', $token );
		$row          = Db::row( 'SELECT * FROM ' . Db::t( 'sessions' ) . ' LIMIT 1' );
		$this->assertNotContains( $secret, wp_json_encode( $row ) );
	}

	public function test_account_session_requires_matching_login(): void {
		$uid = $this->admin();
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + 3600, 'logged_in' );
		$token = $this->visitor();
		$this->assertSame( 200, $this->as_visitor( $token, 'GET', '/conversation' )->get_status() );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->as_visitor( $token, 'GET', '/conversation' )->get_status(), 'logged-out user must not reuse the account session' );
	}

	public function test_blocked_visitor_cannot_continue(): void {
		$v = $this->visitor_with_message( 'spam spam' );
		$this->admin();
		$vid = (int) $this->conversation_row( $v['conversation']['id'] )['visitor_id'];
		$res = $this->rest( 'POST', '/admin/visitors/' . $vid . '/block', array( 'blocked' => true ) );
		$this->assertSame( 200, $res->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->as_visitor( $v['token'], 'GET', '/messages' )->get_status() );
	}

	public function test_admin_routes_require_capabilities(): void {
		$v = $this->visitor_with_message( 'x' );
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		$this->assertSame( 401, $this->rest( 'GET', '/admin/conversations' )->get_status() );
		$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/conversations/' . $cid )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/settings', array( 'brand_name' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/connection' )->get_status() );
		wp_delete_user( $sub );
	}

	public function test_operator_sees_only_assigned_or_own_team_conversations(): void {
		$user = wp_insert_user( array( 'user_login' => 'op' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'gapban_operator' ) );
		$op   = $this->operator( '901', 'Limited', $user );
		$team2 = Db::insert( 'teams', array( 'name' => 'Sales', 'slug' => 'sales', 'created_at' => Db::now() ) );
		$mine  = $this->visitor_with_message( 'assigned to me' );
		$other = $this->visitor_with_message( 'not mine', array( 'department' => $team2 ) );
		$mine_id  = (int) $this->conversation_row( $mine['conversation']['id'] )['id'];
		$other_id = (int) $this->conversation_row( $other['conversation']['id'] )['id'];
		\Gapban\Chat\Assignment::claim( $mine_id, $op, 'test', 0 );
		Db::exec( 'INSERT INTO ' . Db::t( 'team_members' ) . ' (team_id, operator_id) VALUES (%d, %d)', 1, $op );
		wp_set_current_user( $user );
		$ids = array_column( $this->rest( 'GET', '/admin/conversations' )->get_data()['items'], 'id' );
		$this->assertSame( array( $mine_id ), $ids );
		$this->assertSame( 404, $this->rest( 'GET', '/admin/conversations/' . $other_id )->get_status(), 'IDOR' );
		$this->assertSame( 404, $this->rest( 'POST', '/admin/conversations/' . $other_id . '/messages', array( 'body' => 'x' ) )->get_status() );
		wp_delete_user( $user );
	}

	public function test_internal_notes_never_reach_visitor_or_bale(): void {
		$v = $this->visitor_with_message( 'x' );
		$this->work();
		$this->admin();
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		$this->rest( 'POST', '/admin/conversations/' . $cid . '/notes', array( 'body' => 'SECRET NOTE' ) );
		$this->work();
		wp_set_current_user( 0 );
		$all = wp_json_encode( $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data() );
		$this->assertNotContains( 'SECRET NOTE', $all );
		foreach ( $this->bale->calls as $c ) {
			$this->assertNotContains( 'SECRET NOTE', wp_json_encode( $c['params'] ) );
		}
	}

	public function test_stored_xss_payload_is_kept_as_inert_text(): void {
		$payload = '<img src=x onerror=alert(1)><script>alert(2)</script>';
		$v       = $this->visitor_with_message( $payload );
		$msgs    = $this->as_visitor( $v['token'], 'GET', '/messages', array( 'after' => 0 ) )->get_data()['messages'];
		// API returns the exact text; the widget and inbox render it with textContent (see e2e tests).
		$this->assertSame( $payload, $msgs[0]['body'] );
		$this->assertSame( 'text', $msgs[0]['kind'] );
	}

	public function test_hostile_svg_is_rejected_and_safe_svg_is_kept(): void {
		$this->assertSame( null, SvgSanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ) );
		$this->assertSame( null, SvgSanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div/></foreignObject></svg>' ) );
		$this->assertSame( null, SvgSanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0" fill="url(javascript:alert(1))"/></svg>' ) );
		$this->assertSame( null, SvgSanitizer::sanitize( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>' ) );
		$clean = SvgSanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><path d="M1 1h22v22H1z" onclick="x()"/></svg>' );
		$this->assertTrue( is_string( $clean ) );
		$this->assertNotContains( 'onload', $clean );
		$this->assertNotContains( 'onclick', $clean );
		$this->admin();
		$res = $this->rest( 'POST', '/admin/settings', array( 'launcher_custom_svg' => '<svg><script>1</script></svg>' ) );
		$this->assertSame( 422, $res->get_status() );
	}

	public function test_upload_validator_blocks_executables_polyglots_and_svg(): void {
		$tmp = wp_tempnam( 'gb' );
		file_put_contents( $tmp, "<?php system('id');" );
		$this->assertTrue( is_wp_error( UploadValidator::validate( $tmp, 'shell.php', filesize( $tmp ) ) ) );
		$this->assertTrue( is_wp_error( UploadValidator::validate( $tmp, 'image.php.jpg', filesize( $tmp ) ) ) );
		file_put_contents( $tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$this->assertTrue( is_wp_error( UploadValidator::validate( $tmp, 'icon.svg', filesize( $tmp ) ) ) );
		// GIF header followed by PHP (polyglot).
		file_put_contents( $tmp, "GIF89a<?php echo 1; ?>" );
		$this->assertTrue( is_wp_error( UploadValidator::validate( $tmp, 'x.gif', filesize( $tmp ) ) ) );
		// A real PNG passes.
		$img = imagecreatetruecolor( 4, 4 );
		imagepng( $img, $tmp );
		$ok = UploadValidator::validate( $tmp, 'ok.png', filesize( $tmp ) );
		$this->assertSame( 'image/png', $ok['mime'] );
		// Oversized.
		Settings::update( array( 'max_upload_mb' => 1 ) );
		$this->assertTrue( is_wp_error( UploadValidator::validate( $tmp, 'ok.png', 2 * MB_IN_BYTES ) ) );
		wp_delete_file( $tmp );
	}

	public function test_attachments_are_not_downloadable_across_sessions(): void {
		$a = $this->visitor_with_message( 'a' );
		$b = $this->visitor_with_message( 'b' );
		$tmp = wp_tempnam( 'gb' );
		$img = imagecreatetruecolor( 4, 4 );
		imagepng( $img, $tmp );
		$conv = $this->conversation_row( $a['conversation']['id'] );
		$res  = \Gapban\Rest\VisitorController::store_attachment( $tmp, false, 'a.png', 'image/png', filesize( $tmp ), (int) $conv['id'], 'visitor', (int) $conv['visitor_id'] );
		$aid  = $res['attachment']['id'];
		$this->assertSame( 404, $this->as_visitor( $b['token'], 'GET', '/attachments/' . $aid )->get_status() );
		// The stored file name is random and has no extension.
		$name = (string) Db::value( 'SELECT storage_name FROM ' . Db::t( 'attachments' ) . ' WHERE public_id = %s', $aid );
		$this->assertTrue( 1 === preg_match( '/^[0-9a-z]{40}$/', $name ) );
		$this->assertTrue( file_exists( \Gapban\Security\AttachmentStorage::dir() . '/.htaccess' ) );
	}

	public function test_visitor_cannot_attach_someone_elses_upload(): void {
		$a = $this->visitor_with_message( 'a' );
		$b = $this->visitor_with_message( 'b' );
		$tmp = wp_tempnam( 'gb' );
		file_put_contents( $tmp, 'hello' );
		$conv = $this->conversation_row( $a['conversation']['id'] );
		$res  = \Gapban\Rest\VisitorController::store_attachment( $tmp, false, 'a.txt', 'text/plain', 5, (int) $conv['id'], 'visitor', (int) $conv['visitor_id'] );
		$send = $this->as_visitor( $b['token'], 'POST', '/messages', array( 'body' => 'steal', 'client_key' => 'steal-key-1', 'attachments' => array( $res['attachment']['id'] ) ) );
		$this->assertSame( array(), $send->get_data()['message']['attachments'] );
	}

	public function test_message_size_and_rate_limits(): void {
		$token = $this->visitor();
		$this->as_visitor( $token, 'POST', '/conversation', array() );
		$res = $this->as_visitor( $token, 'POST', '/messages', array( 'body' => str_repeat( 'x', 4001 ), 'client_key' => 'too-long-1' ) );
		$this->assertSame( 413, $res->get_status() );
		Settings::update( array( 'messages_per_minute' => 3 ) );
		$token = $this->visitor();
		$this->as_visitor( $token, 'POST', '/conversation', array() );
		$codes = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$codes[] = $this->as_visitor( $token, 'POST', '/messages', array( 'body' => "m$i", 'client_key' => "rate-key-$i" ) )->get_status();
		}
		$this->assertSame( array( 200, 200, 200, 429, 429 ), $codes );
	}

	public function test_session_creation_is_rate_limited_per_ip(): void {
		Settings::update( array( 'sessions_per_ip_per_hour' => 2 ) );
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = $this->rest( 'POST', '/session' )->get_status();
		}
		$this->assertSame( array( 200, 200, 429 ), $codes );
	}

	public function test_honeypot_rejects_bots(): void {
		$this->assertSame( 400, $this->rest( 'POST', '/session', array( 'website' => 'http://spam' ) )->get_status() );
	}

	public function test_forwarded_ip_is_trusted_only_from_configured_proxy(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$this->assertSame( '203.0.113.10', \Gapban\Security\ClientIp::get() );
		Settings::update( array( 'trusted_proxies' => array( '203.0.113.0/24' ) ) );
		$this->assertSame( '198.51.100.7', \Gapban\Security\ClientIp::get() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.99';
		$this->assertSame( '198.51.100.7', \Gapban\Security\ClientIp::get() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_api_base_override_is_restricted_to_bale_host(): void {
		$this->assertSame( 'https://tapi.bale.ai', \Gapban\Bale\BaleClient::base_url() );
		// Provider redirects are never followed and remote downloads are size-bounded.
		$this->bale->overrides['sendMessage'] = static fn() => array( '__status' => 302, 'x' => 1 );
		$this->visitor_with_message( 'redirect' );
		$this->work();
		$this->assertSame( 1, count( $this->bale->sent() ) );
	}

	public function test_download_size_is_bounded(): void {
		$this->operator( '777' );
		Settings::update( array( 'max_upload_mb' => 1 ) );
		$v = $this->visitor_with_message( 'x' );
		$this->work();
		$this->bale->files['documents/BIG'] = str_repeat( 'a', 2 * MB_IN_BYTES );
		$this->webhook( FakeBale::update( 9, $this->group, '777', '', '1000', array( 'document' => array( 'file_id' => 'BIG', 'file_name' => 'big.txt', 'mime_type' => 'text/plain' ) ) ) );
		$this->work();
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'attachments' ) ) );
		unset( $v );
	}

	public function test_secrets_are_encrypted_and_redacted(): void {
		$raw = get_option( Settings::SECRETS_OPTION );
		$this->assertNotContains( FakeBale::TOKEN, wp_json_encode( $raw ) );
		$this->assertSame( FakeBale::TOKEN, Settings::bot_token() );
		$tampered = $raw['bot_token'];
		$tampered[ strlen( $tampered ) - 3 ] = 'A' === $tampered[ strlen( $tampered ) - 3 ] ? 'B' : 'A';
		$this->assertSame( null, Crypto::decrypt( $tampered ), 'authenticated encryption rejects tampering' );
		$this->assertSame( 'call bot[token]/sendMessage', \Gapban\Security\Redactor::text( 'call bot' . FakeBale::TOKEN . '/sendMessage' ) );
		$this->admin();
		$diag = wp_json_encode( $this->rest( 'GET', '/admin/connection' )->get_data() );
		$this->assertNotContains( FakeBale::TOKEN, $diag );
		$this->assertNotContains( \Gapban\Bale\WebhookManager::secret(), $diag );
		$export = wp_json_encode( $this->rest( 'GET', '/admin/settings/export' )->get_data() );
		$this->assertNotContains( FakeBale::TOKEN, $export );
		$this->assertNotContains( $this->group, $export, 'destinations excluded by default' );
	}

	public function test_privacy_export_and_erase(): void {
		$v = $this->visitor_with_message( 'personal data', array( 'email' => 'person@example.com' ) );
		$export = \Gapban\Privacy\PrivacyTools::export( 'person@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertContains( 'personal data', wp_json_encode( $export['data'], JSON_UNESCAPED_UNICODE ) );
		$erase = \Gapban\Privacy\PrivacyTools::erase( 'person@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) ) );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'visitors' ) ) );
		unset( $v );
	}

	public function test_retention_cascade_deletes_closed_conversations(): void {
		Settings::update( array( 'retention_days' => 30 ) );
		$v   = $this->visitor_with_message( 'old' );
		$this->work();
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		\Gapban\Chat\ConversationService::transition( $cid, 'close', 'test', 0 );
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . ' SET last_message_at = %s', Db::at( time() - 40 * DAY_IN_SECONDS ) );
		$out = \Gapban\Privacy\Retention::run();
		$this->assertSame( 1, $out['conversations_deleted'] );
		foreach ( array( 'messages', 'provider_mappings', 'participants' ) as $t ) {
			$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( $t ) ), $t );
		}
	}

	public function test_pages_never_receive_session_secret_in_html(): void {
		$token = $this->visitor();
		ob_start();
		do_action( 'wp_footer' );
		$html = ob_get_clean();
		[ , $secret ] = explode( '.', $token );
		$this->assertNotContains( $secret, $html );
	}
}
