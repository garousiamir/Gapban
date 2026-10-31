<?php
/**
 * State machine, localization helpers, availability, validation and reports.
 */

namespace Gapban\Tests;

use Gapban\Chat\Availability;
use Gapban\Core\Locale;
use Gapban\Core\Settings;
use Gapban\Domain\ConversationState as S;
use Gapban\Persistence\Db;
use Gapban\Rules\RuleValidator;

final class DomainTest extends TestCase {

	public function test_state_transitions(): void {
		$this->assertSame( S::WAITING, S::next( S::QUEUED, 'operator_message' ) );
		$this->assertSame( S::OPEN, S::next( S::WAITING, 'visitor_message' ) );
		$this->assertSame( S::CLOSED, S::next( S::OPEN, 'close' ) );
		$this->assertSame( S::OPEN, S::next( S::CLOSED, 'reopen' ) );
		$this->assertSame( null, S::next( S::SPAM, 'visitor_message' ) );
		$this->assertSame( S::CLOSED, S::next( S::SPAM, 'unspam' ) );
	}

	public function test_spam_conversation_refuses_visitor_messages(): void {
		$v   = $this->visitor_with_message( 'x' );
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		\Gapban\Chat\ConversationService::transition( $cid, 'spam', 'test', 0 );
		// The visitor's active conversation is gone; a new message needs a new conversation.
		$res = $this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'more', 'client_key' => 'after-spam-1' ) );
		$this->assertSame( 404, $res->get_status() );
	}

	public function test_reopen_on_visitor_message_when_allowed(): void {
		$v   = $this->visitor_with_message( 'x' );
		$cid = (int) $this->conversation_row( $v['conversation']['id'] )['id'];
		\Gapban\Chat\ConversationService::transition( $cid, 'close', 'test', 0 );
		$res = $this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'again', 'client_key' => 'reopen-key-1' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'queued', $this->conversation_row( $v['conversation']['id'] )['status'] );
		Settings::update( array( 'allow_reopen' => false ) );
		\Gapban\Chat\ConversationService::transition( $cid, 'close', 'test', 0 );
		$res = $this->as_visitor( $v['token'], 'POST', '/messages', array( 'body' => 'no', 'client_key' => 'reopen-key-2' ) );
		$this->assertSame( 409, $res->get_status() );
	}

	public function test_persian_digits_and_phone_normalization(): void {
		$this->assertSame( '0912345', Locale::normalize_digits( '۰۹۱۲٣٤٥' ) );
		$this->assertSame( '+989121234567', Locale::normalize_phone( '۰۹۱۲ ۱۲۳ ۴۵۶۷' ) );
		$this->assertSame( '+989121234567', Locale::normalize_phone( '+98 912 123 4567' ) );
		$this->assertSame( '+989121234567', Locale::normalize_phone( '00989121234567' ) );
		$this->assertSame( '+447700900123', Locale::normalize_phone( '+44 7700 900123' ) );
		$this->assertSame( '', Locale::normalize_phone( '12' ) );
		$this->assertSame( '-100123', Settings::chat_id( '-۱۰۰۱۲۳' ) );
	}

	public function test_prechat_phone_validation_uses_normalization(): void {
		Settings::update( array( 'prechat_fields' => array( 'name' => 'optional', 'email' => 'optional', 'phone' => 'required', 'department' => 'hidden' ) ) );
		$token = $this->visitor();
		$bad   = $this->as_visitor( $token, 'POST', '/conversation', array( 'phone' => 'abc' ) );
		$this->assertSame( 422, $bad->get_status() );
		$ok = $this->as_visitor( $token, 'POST', '/conversation', array( 'phone' => '۰۹۱۲۱۲۳۴۵۶۷' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( '+989121234567', Db::value( 'SELECT phone FROM ' . Db::t( 'visitors' ) . ' ORDER BY id DESC LIMIT 1' ) );
	}

	public function test_business_hours_respect_timezone_and_holidays(): void {
		Settings::update(
			array(
				'timezone'               => 'Asia/Tehran',
				'business_hours_enabled' => true,
				'business_hours'         => array( 'sat' => array( array( '09:00', '17:00' ) ) ),
				'holidays'               => array( array( 'date' => '2026-11-07', 'label' => 'x' ) ),
			)
		);
		// Saturday 2026-10-31 06:00 UTC = 09:30 Tehran (UTC+03:30) → open.
		$open = new \DateTimeImmutable( '2026-10-31 06:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertTrue( Availability::within_hours( Settings::get( 'business_hours' ), $open ) );
		// 05:00 UTC = 08:30 Tehran → closed.
		$early = new \DateTimeImmutable( '2026-10-31 05:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( 'outside_hours', Availability::state( null, $early )['reason'] );
		// Holiday Saturday.
		$holiday = new \DateTimeImmutable( '2026-11-07 07:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( 'holiday', Availability::state( null, $holiday )['reason'] );
	}

	public function test_presence_comes_only_from_explicit_state_or_fresh_heartbeat(): void {
		$auto = Db::insert( 'operators', array( 'display_name' => 'A', 'availability' => 'auto', 'created_at' => Db::now() ) );
		$this->assertSame( array(), Availability::available_operator_ids() );
		Db::update( 'operators', array( 'heartbeat_at' => Db::now() ), array( 'id' => $auto ) );
		$this->assertSame( array( $auto ), Availability::available_operator_ids() );
		Db::update( 'operators', array( 'heartbeat_at' => Db::at( time() - 300 ) ), array( 'id' => $auto ) );
		$this->assertSame( array(), Availability::available_operator_ids(), 'heartbeat expires' );
	}

	public function test_round_robin_and_least_loaded_assignment(): void {
		$a = $this->operator( '1', 'A' );
		$b = $this->operator( '2', 'B' );
		Db::update( 'teams', array( 'assignment_strategy' => 'round_robin' ), array( 'id' => 1 ) );
		$first  = $this->visitor_with_message( '1' );
		$second = $this->visitor_with_message( '2' );
		$ops    = array( (int) $this->conversation_row( $first['conversation']['id'] )['assigned_operator_id'], (int) $this->conversation_row( $second['conversation']['id'] )['assigned_operator_id'] );
		sort( $ops );
		$this->assertSame( array( $a, $b ), $ops );
		Db::update( 'teams', array( 'assignment_strategy' => 'least_loaded' ), array( 'id' => 1 ) );
		Db::update( 'operators', array( 'availability' => 'offline' ), array( 'id' => $b ) );
		$third = $this->visitor_with_message( '3' );
		$this->assertSame( (string) $a, (string) $this->conversation_row( $third['conversation']['id'] )['assigned_operator_id'] );
	}

	public function test_rule_validator_rejects_code_and_bad_structures(): void {
		$this->assertTrue( is_wp_error( RuleValidator::validate( array( 'name' => 'x', 'actions' => array( array( 'type' => 'eval', 'text' => 'alert(1)' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( RuleValidator::validate( array( 'name' => 'x', 'conditions' => array( 'op' => 'and', 'items' => array( array( 'type' => 'javascript', 'value' => '1' ) ) ), 'actions' => array( array( 'type' => 'show_launcher' ) ) ) ) ) );
		$deep = array( 'op' => 'and', 'items' => array( array( 'op' => 'and', 'items' => array( array( 'op' => 'and', 'items' => array( array( 'op' => 'and', 'items' => array( array( 'op' => 'and', 'items' => array() ) ) ) ) ) ) ) ) );
		$this->assertTrue( is_wp_error( RuleValidator::validate( array( 'name' => 'x', 'conditions' => $deep, 'actions' => array( array( 'type' => 'show_launcher' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( RuleValidator::validate( array( 'name' => 'x', 'actions' => array( array( 'type' => 'show_launcher' ), array( 'type' => 'hide_launcher' ) ) ) ) ) );
		$ok = RuleValidator::validate( array( 'name' => 'x', 'conditions' => array( 'op' => 'and', 'items' => array( array( 'type' => 'wc_cart_value', 'compare' => 'gte', 'value' => '۱۰۰۰' ) ) ), 'actions' => array( array( 'type' => 'show_teaser', 'text' => '<b>hi</b>' ) ) ) );
		$this->assertSame( 1000.0, $ok['conditions']['items'][0]['value'] );
		$this->assertSame( 'hi', $ok['actions'][0]['text'] );
	}

	public function test_reports_exclude_automated_messages_from_human_response(): void {
		Settings::update( array( 'automated_greeting' => 'auto hello' ) );
		$this->operator( '777' );
		$a = $this->visitor_with_message( 'needs answer' );
		$b = $this->visitor_with_message( 'answered' );
		$this->work();
		$this->webhook( FakeBale::update( 1, $this->group, '777', 'human answer', '1001' ) );
		$this->work();
		$r = \Gapban\Reporting\Reports::summary( Db::at( time() - 3600 ), Db::at( time() + 3600 ) );
		$this->assertSame( 2, $r['volume'] );
		$this->assertSame( 1, $r['unanswered'] );
		$this->assertSame( 0.5, $r['unanswered_rate'] );
		$this->assertSame( 1, $r['first_response']['count'] );
		unset( $a, $b );
	}

	public function test_settings_sanitization(): void {
		$s = Settings::sanitize(
			array(
				'brand_color'       => 'javascript:alert(1)',
				'launcher_position' => 'top',
				'max_upload_mb'     => '۹۹۹',
				'unknown_key'       => 'x',
				'welcome_title'     => '<script>x</script>سلام',
				'trusted_proxies'   => "10.0.0.0/8\nnot-an-ip",
			)
		);
		$this->assertSame( '#ef4b4b', $s['brand_color'] );
		$this->assertSame( 'end', $s['launcher_position'] );
		$this->assertSame( 20, $s['max_upload_mb'] );
		$this->assertFalse( isset( $s['unknown_key'] ) );
		$this->assertSame( 'سلام', $s['welcome_title'] );
		$this->assertSame( array( '10.0.0.0/8' ), $s['trusted_proxies'] );
	}
}
