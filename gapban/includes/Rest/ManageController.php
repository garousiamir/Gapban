<?php
/**
 * Administration API: connection setup, operators, teams, settings, rules,
 * knowledge base, reports, queue and diagnostics.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Bale\BaleClient;
use Gapban\Bale\BaleProvider;
use Gapban\Bale\Capabilities as BaleCaps;
use Gapban\Bale\WebhookManager;
use Gapban\Chat\Audit;
use Gapban\Chat\Availability;
use Gapban\Core\Locale;
use Gapban\Core\Settings;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Persistence\Schema;
use Gapban\Provider\ProviderException;
use Gapban\Queue\JobQueue;
use Gapban\Queue\Worker;
use Gapban\Reporting\Reports;
use Gapban\Routing\Enrollment;
use Gapban\Routing\RoutingRegistry;
use Gapban\Rules\RuleEvents;
use Gapban\Rules\RuleValidator;
use Gapban\Security\AttachmentStorage;
use Gapban\Security\Capabilities as Caps;
use Gapban\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class ManageController {

	public static function register(): void {
		$s = Caps::MANAGE_SETTINGS;
		self::route( '/admin/settings', 'GET', 'get_settings', $s );
		self::route( '/admin/settings', 'POST', 'save_settings', $s );
		self::route( '/admin/settings/export', 'GET', 'export_settings', $s );
		self::route( '/admin/settings/import', 'POST', 'import_settings', $s );

		self::route( '/admin/connection', 'GET', 'connection', $s );
		self::route( '/admin/connection/token', 'POST', 'save_token', $s );
		self::route( '/admin/connection/token', 'DELETE', 'delete_token', $s );
		self::route( '/admin/connection/webhook', 'POST', 'webhook', $s );
		self::route( '/admin/connection/test', 'POST', 'test_send', $s );
		self::route( '/admin/connection/cron-secret', 'POST', 'rotate_cron_secret', $s );

		self::route( '/admin/operators', 'GET', 'operators', array( $s, Caps::ASSIGN, Caps::VIEW_ALL, Caps::VIEW_ASSIGNED ) );
		self::route( '/admin/operators', 'POST', 'save_operator', $s );
		self::route( '/admin/operators/(?P<id>\d+)', 'POST', 'save_operator', $s );
		self::route( '/admin/operators/(?P<id>\d+)', 'DELETE', 'delete_operator', $s );
		self::route( '/admin/operators/(?P<id>\d+)/enroll', 'POST', 'enroll_operator', $s );
		self::route( '/admin/enrollments/(?P<id>\d+)', 'GET', 'enrollment', $s );

		self::route( '/admin/teams', 'GET', 'teams', array( $s, Caps::ASSIGN, Caps::VIEW_ALL, Caps::VIEW_ASSIGNED ) );
		self::route( '/admin/teams', 'POST', 'save_team', $s );
		self::route( '/admin/teams/(?P<id>\d+)', 'POST', 'save_team', $s );
		self::route( '/admin/teams/(?P<id>\d+)', 'DELETE', 'delete_team', $s );
		self::route( '/admin/teams/(?P<id>\d+)/bind', 'POST', 'bind_destination', $s );

		self::route( '/admin/rules', 'GET', 'rules', $s );
		self::route( '/admin/rules', 'POST', 'save_rule', $s );
		self::route( '/admin/rules/(?P<id>\d+)', 'POST', 'save_rule', $s );
		self::route( '/admin/rules/(?P<id>\d+)', 'DELETE', 'delete_rule', $s );
		self::route( '/admin/rules/validate', 'POST', 'validate_rule', $s );
		self::route( '/admin/rules/import', 'POST', 'import_rules', $s );
		self::route( '/admin/rules/export', 'GET', 'export_rules', $s );
		self::route( '/admin/targeting-data', 'GET', 'targeting_data', $s );

		self::route( '/admin/knowledge', 'GET', 'knowledge', array( $s, Caps::REPLY ) );
		self::route( '/admin/knowledge', 'POST', 'save_knowledge', $s );
		self::route( '/admin/knowledge/(?P<id>\d+)', 'POST', 'save_knowledge', $s );
		self::route( '/admin/knowledge/(?P<id>\d+)', 'DELETE', 'delete_knowledge', $s );

		self::route( '/admin/reports', 'GET', 'reports', Caps::REPORT );
		self::route( '/admin/queue', 'GET', 'queue', Caps::DIAGNOSTICS );
		self::route( '/admin/queue/(?P<id>\d+)/retry', 'POST', 'queue_retry', Caps::DIAGNOSTICS );
		self::route( '/admin/queue/(?P<id>\d+)/discard', 'POST', 'queue_discard', Caps::DIAGNOSTICS );
		self::route( '/admin/queue/run', 'POST', 'queue_run', Caps::DIAGNOSTICS );
		self::route( '/admin/diagnostics', 'GET', 'diagnostics', Caps::DIAGNOSTICS );
		self::route( '/admin/inbound', 'GET', 'inbound', Caps::DIAGNOSTICS );
	}

	/**
	 * @param string|string[] $caps
	 */
	private static function route( string $path, string $method, string $callback, $caps ): void {
		$caps = (array) $caps;
		register_rest_route(
			'gapban/v1',
			$path,
			array(
				'methods'             => $method,
				'callback'            => static function ( \WP_REST_Request $r ) use ( $callback ) {
					try {
						$res = Serializer::response( self::$callback( $r ) );
					} catch ( ProviderException $e ) {
						$res = Serializer::response( self::provider_error( $e ) );
					} catch ( \Gapban\Chat\ChatException $e ) {
						$res = Serializer::response( $e->to_wp_error() );
					}
					$res->header( 'Cache-Control', 'no-store, private' );
					return $res;
				},
				'permission_callback' => static function () use ( $caps ) {
					foreach ( $caps as $cap ) {
						if ( current_user_can( $cap ) ) {
							return true;
						}
					}
					return false;
				},
			)
		);
	}

	public static function provider_error( ProviderException $e ): \WP_Error {
		$messages = array(
			ProviderException::AUTH         => __( 'Bale rejected the bot token. Check the token from @BotFather in Bale and try again.', 'gapban' ),
			ProviderException::RATE_LIMITED => __( 'Bale is rate limiting requests. Wait a little and try again.', 'gapban' ),
			ProviderException::RETRYABLE    => __( 'Could not reach Bale. Check the server\'s internet access and firewall (outbound HTTPS to tapi.bale.ai).', 'gapban' ),
			ProviderException::UNCERTAIN    => __( 'Bale did not answer clearly; the request may or may not have been applied. Check the chat before retrying.', 'gapban' ),
			ProviderException::PERMANENT    => __( 'Bale refused the request.', 'gapban' ),
		);
		$detail = Redactor::text( $e->getMessage() );
		if ( preg_match( '/chat not found/i', $detail ) ) {
			$messages[ ProviderException::PERMANENT ] = __( 'Bale says the chat was not found. Make sure the bot is a member of the group and the chat ID is correct.', 'gapban' );
		} elseif ( preg_match( '/forbidden|kicked|blocked|not enough rights/i', $detail ) ) {
			$messages[ ProviderException::PERMANENT ] = __( 'The bot is not allowed to post in this chat. Add the bot to the group again and give it permission to send messages.', 'gapban' );
		} elseif ( 'webhook_requires_https' === $detail ) {
			$messages[ ProviderException::PERMANENT ] = __( 'Webhooks need an HTTPS site address. Enable HTTPS or use the polling mode with a server cron job.', 'gapban' );
		}
		return new \WP_Error(
			'gapban_provider_' . $e->kind,
			$messages[ $e->kind ] ?? $detail,
			array(
				'status' => 502,
				'detail' => $detail,
			)
		);
	}

	/* --------------------------- settings ---------------------------- */

	public static function get_settings() {
		$s = Settings::all();
		return array(
			'settings'  => $s,
			'routing'   => array(
				'modes' => array_map(
					static fn( $r ) => array(
						'mode'      => $r->mode(),
						'available' => $r->available(),
						'reason'    => $r->unavailable_reason(),
					),
					array_values( RoutingRegistry::routers() )
				),
			),
			'timezones' => timezone_identifiers_list(),
			'site_timezone' => wp_timezone_string(),
			'upload_types'  => array_keys( \Gapban\Security\UploadValidator::SAFE_TYPES ),
			'woocommerce'   => class_exists( 'WooCommerce' ),
		);
	}

	public static function save_settings( \WP_REST_Request $r ) {
		$input = (array) $r->get_json_params();
		if ( isset( $input['routing_mode'] ) && ! RoutingRegistry::can_select( (string) $input['routing_mode'] ) ) {
			$router = RoutingRegistry::router( (string) $input['routing_mode'] );
			return new \WP_Error( 'gapban_mode_unavailable', $router ? $router->unavailable_reason() : __( 'Unknown routing mode.', 'gapban' ), array( 'status' => 422 ) );
		}
		if ( isset( $input['launcher_custom_svg'] ) && '' !== trim( (string) $input['launcher_custom_svg'] ) && null === \Gapban\Security\SvgSanitizer::sanitize( (string) $input['launcher_custom_svg'] ) ) {
			return new \WP_Error( 'gapban_svg_rejected', __( 'The SVG icon contains unsupported or unsafe content (scripts, styles, links or external references). Use a simple path-based icon.', 'gapban' ), array( 'status' => 422 ) );
		}
		$saved = Settings::update( $input );
		Audit::log( 'user', get_current_user_id(), 'settings.updated', 'settings', 0, array( 'keys' => array_keys( Settings::sanitize( $input ) ) ) );
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'settings' => $saved );
	}

	public static function export_settings( \WP_REST_Request $r ) {
		$settings = Settings::all();
		if ( ! filter_var( $r->get_param( 'include_destinations' ), FILTER_VALIDATE_BOOLEAN ) ) {
			foreach ( Settings::export_excluded() as $k ) {
				unset( $settings[ $k ] );
			}
		}
		Audit::log( 'user', get_current_user_id(), 'settings.exported', 'settings' );
		return array(
			'format'     => 'gapban-settings',
			'version'    => 1,
			'exported_at'=> gmdate( 'c' ),
			'settings'   => $settings,
			'rules'      => self::export_rules()['rules'],
			'knowledge'  => array_map(
				static fn( $k ) => array_intersect_key( $k, array_flip( array( 'type', 'title', 'shortcut', 'body', 'url', 'active', 'sort_order' ) ) ),
				Db::rows( 'SELECT * FROM ' . Db::t( 'knowledge' ) . ' ORDER BY type, sort_order, id' )
			),
			'note'       => 'Bot token, webhook and cron secrets are never exported.',
		);
	}

	public static function import_settings( \WP_REST_Request $r ) {
		$data = (array) $r->get_json_params();
		if ( 'gapban-settings' !== ( $data['format'] ?? '' ) || ! is_array( $data['settings'] ?? null ) ) {
			return new \WP_Error( 'gapban_import_invalid', __( 'This file is not a Gapban settings export.', 'gapban' ), array( 'status' => 422 ) );
		}
		$settings = $data['settings'];
		unset( $settings['routing_mode'] );
		if ( isset( $data['settings']['routing_mode'] ) && RoutingRegistry::can_select( (string) $data['settings']['routing_mode'] ) ) {
			$settings['routing_mode'] = $data['settings']['routing_mode'];
		}
		Settings::update( $settings );
		$rules = 0;
		foreach ( (array) ( $data['rules'] ?? array() ) as $def ) {
			$valid = RuleValidator::validate( $def );
			if ( ! is_wp_error( $valid ) ) {
				RuleEvents::save( $valid );
				++$rules;
			}
		}
		$knowledge = 0;
		foreach ( (array) ( $data['knowledge'] ?? array() ) as $k ) {
			if ( is_array( $k ) && in_array( $k['type'] ?? '', array( 'canned', 'faq' ), true ) ) {
				self::insert_knowledge( $k );
				++$knowledge;
			}
		}
		Audit::log( 'user', get_current_user_id(), 'settings.imported', 'settings', 0, array( 'rules' => $rules, 'knowledge' => $knowledge ) );
		\Gapban\Widget\Frontend::bump_config_version();
		return array(
			'imported'  => true,
			'rules'     => $rules,
			'knowledge' => $knowledge,
		);
	}

	/* --------------------------- connection --------------------------- */

	public static function connection() {
		$client   = new BaleClient();
		$identity = get_option( BaleProvider::IDENTITY_OPTION );
		$out      = array(
			'configured'       => $client->has_token(),
			'token_masked'     => $client->has_token() ? Settings::mask( Settings::bot_token() ) : '',
			'token_from_config'=> Settings::token_from_config(),
			'token_invalid_at' => (string) get_option( 'gapban_token_invalid_at', '' ),
			'identity'         => is_array( $identity ) ? array_intersect_key( $identity, array_flip( array( 'id', 'username', 'first_name', 'verified_at' ) ) ) : null,
			'transport'        => Settings::get( 'transport' ),
			'webhook'          => null,
			'webhook_error'    => null,
			'capabilities'     => BaleCaps::matrix(),
			'destination'      => array(
				'chat_id' => Settings::get( 'default_destination_chat' ),
				'title'   => Settings::get( 'default_destination_title' ),
			),
			'https'            => 0 === strpos( rest_url(), 'https://' ),
			'cron_url'         => Settings::secret( 'cron_secret' ) ? rest_url( 'gapban/v1/cron/' ) . '••••••' : '',
			'queue'            => JobQueue::stats(),
			'api_host'         => (string) wp_parse_url( BaleClient::base_url(), PHP_URL_HOST ),
		);
		if ( $client->has_token() ) {
			try {
				$out['webhook'] = WebhookManager::status( $client );
			} catch ( ProviderException $e ) {
				$out['webhook_error'] = self::provider_error( $e )->get_error_message();
			}
		}
		return $out;
	}

	public static function save_token( \WP_REST_Request $r ) {
		if ( Settings::token_from_config() ) {
			return new \WP_Error( 'gapban_token_config', __( 'The token is defined in wp-config.php (GAPBAN_BALE_TOKEN) and cannot be changed here.', 'gapban' ), array( 'status' => 409 ) );
		}
		$token = trim( Locale::normalize_digits( (string) $r->get_param( 'token' ) ) );
		$test  = new BaleClient( $token );
		if ( ! $test->has_token() ) {
			return new \WP_Error( 'gapban_token_format', __( 'This does not look like a Bale bot token. It should look like 123456789:AbC…', 'gapban' ), array( 'status' => 422 ) );
		}
		$provider = new BaleProvider( $test );
		$identity = $provider->verify();
		$previous = Settings::bot_token();
		Settings::set_secret( 'bot_token', $token );
		delete_option( 'gapban_token_invalid_at' );
		RoutingRegistry::set_provider( null );
		if ( $previous && strstr( $previous, ':', true ) !== strstr( $token, ':', true ) ) {
			// A different bot: old mappings and offsets belong to the previous bot.
			delete_option( \Gapban\Bale\Poller::OFFSET_OPTION );
		}
		Audit::log( 'user', get_current_user_id(), 'connection.token_saved', 'connection', 0, array( 'bot' => $identity['username'] ) );
		return array(
			'identity'     => array_intersect_key( $identity, array_flip( array( 'id', 'username', 'first_name', 'verified_at' ) ) ),
			'token_masked' => Settings::mask( $token ),
		);
	}

	public static function delete_token() {
		if ( Settings::token_from_config() ) {
			return new \WP_Error( 'gapban_token_config', __( 'The token is defined in wp-config.php (GAPBAN_BALE_TOKEN) and cannot be changed here.', 'gapban' ), array( 'status' => 409 ) );
		}
		WebhookManager::remove_if_owned();
		Settings::set_secret( 'bot_token', null );
		delete_option( BaleProvider::IDENTITY_OPTION );
		RoutingRegistry::set_provider( null );
		Audit::log( 'user', get_current_user_id(), 'connection.token_removed', 'connection' );
		return array( 'removed' => true );
	}

	public static function webhook( \WP_REST_Request $r ) {
		$client = new BaleClient();
		$action = (string) $r->get_param( 'action' );
		switch ( $action ) {
			case 'install':
				$res = WebhookManager::install( $client, filter_var( $r->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN ) );
				if ( ! empty( $res['installed'] ) ) {
					Settings::update( array( 'transport' => 'webhook' ) );
				}
				break;
			case 'remove':
				$res = array( 'removed' => WebhookManager::remove( $client ) );
				break;
			case 'rotate':
				WebhookManager::rotate_secret();
				$res = WebhookManager::install( $client, true );
				break;
			case 'use_polling':
				$res = array( 'removed' => WebhookManager::remove( $client ) );
				Settings::update( array( 'transport' => 'polling' ) );
				if ( ! Settings::secret( 'cron_secret' ) ) {
					Settings::set_secret( 'cron_secret', Ids::secret( 32 ) );
				}
				break;
			default:
				return new \WP_Error( 'gapban_invalid', __( 'Invalid request.', 'gapban' ), array( 'status' => 400 ) );
		}
		Audit::log( 'user', get_current_user_id(), 'connection.webhook_' . $action, 'connection' );
		$res['status'] = WebhookManager::status( $client );
		return $res;
	}

	public static function test_send( \WP_REST_Request $r ) {
		$chat = Settings::chat_id( (string) ( $r->get_param( 'chat_id' ) ?: Settings::get( 'default_destination_chat' ) ) );
		if ( '' === $chat ) {
			return new \WP_Error( 'gapban_no_destination', __( 'Set a destination chat first.', 'gapban' ), array( 'status' => 422 ) );
		}
		$id = RoutingRegistry::provider()->send_text(
			$chat,
			__( '✅ Gapban connection test. Reply to this message with any text to test receiving; the result appears on the setup screen.', 'gapban' )
		);
		update_option(
			'gapban_test_message',
			array(
				'chat_id'    => $chat,
				'message_id' => $id,
				'sent_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
		return array( 'sent' => true, 'message_id' => $id );
	}

	public static function rotate_cron_secret() {
		$secret = Ids::secret( 32 );
		Settings::set_secret( 'cron_secret', $secret );
		Audit::log( 'user', get_current_user_id(), 'connection.cron_secret_rotated', 'connection' );
		// Shown once so the administrator can paste it into the host's cron job.
		return array( 'cron_url' => rest_url( 'gapban/v1/cron/' . $secret ) );
	}

	/* --------------------------- operators ---------------------------- */

	public static function operators() {
		$rows    = Db::rows( 'SELECT * FROM ' . Db::t( 'operators' ) . ' ORDER BY display_name' );
		$members = array();
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'team_members' ) ) as $m ) {
			$members[ (int) $m['operator_id'] ][] = (int) $m['team_id'];
		}
		$available = Availability::available_operator_ids();
		return array_map(
			static function ( $o ) use ( $members, $available ) {
				$user = $o['wp_user_id'] ? get_userdata( (int) $o['wp_user_id'] ) : null;
				return array(
					'id'             => (int) $o['id'],
					'display_name'   => $o['display_name'],
					'wp_user_id'     => $o['wp_user_id'] ? (int) $o['wp_user_id'] : null,
					'wp_user_login'  => $user ? $user->user_login : '',
					'bale_linked'    => ! empty( $o['bale_user_id'] ),
					'bale_user_id'   => (string) $o['bale_user_id'],
					'bale_username'  => $o['bale_username'],
					'status'         => $o['status'],
					'availability'   => $o['availability'],
					'available_now'  => in_array( (int) $o['id'], $available, true ),
					'heartbeat_at'   => Serializer::iso( $o['heartbeat_at'] ),
					'max_concurrent' => (int) $o['max_concurrent'],
					'schedule'       => $o['schedule'] ? json_decode( (string) $o['schedule'], true ) : null,
					'teams'          => $members[ (int) $o['id'] ] ?? array(),
				);
			},
			$rows
		);
	}

	public static function save_operator( \WP_REST_Request $r ) {
		$id   = (int) ( $r['id'] ?? 0 );
		$data = array(
			'display_name'   => mb_substr( sanitize_text_field( (string) $r->get_param( 'display_name' ) ), 0, 120 ),
			'status'         => 'disabled' === $r->get_param( 'status' ) ? 'disabled' : 'active',
			'availability'   => in_array( $r->get_param( 'availability' ), array( 'online', 'away', 'offline', 'auto' ), true ) ? $r->get_param( 'availability' ) : 'auto',
			'max_concurrent' => max( 1, min( 100, (int) Locale::normalize_digits( (string) ( $r->get_param( 'max_concurrent' ) ?? 5 ) ) ) ),
		);
		if ( '' === $data['display_name'] ) {
			return new \WP_Error( 'gapban_invalid', __( 'Enter the operator\'s name.', 'gapban' ), array( 'status' => 422 ) );
		}
		$wp_user = (int) $r->get_param( 'wp_user_id' );
		$data['wp_user_id'] = $wp_user && get_userdata( $wp_user ) ? $wp_user : null;

		$schedule = $r->get_param( 'schedule' );
		if ( is_array( $schedule ) && $schedule ) {
			$clean = Settings::sanitize( array( 'business_hours' => $schedule ) )['business_hours'];
			$data['schedule'] = wp_json_encode( $clean );
		} else {
			$data['schedule'] = null;
		}
		if ( $r->has_param( 'bale_user_id' ) ) {
			$bale = Settings::chat_id( (string) $r->get_param( 'bale_user_id' ) );
			if ( '' !== $bale && Db::value( 'SELECT 1 FROM ' . Db::t( 'operators' ) . ' WHERE bale_user_id = %s AND id <> %d', $bale, $id ) ) {
				return new \WP_Error( 'gapban_duplicate', __( 'This Bale user is already linked to another operator.', 'gapban' ), array( 'status' => 409 ) );
			}
			$data['bale_user_id'] = '' === $bale ? null : $bale;
		}
		if ( $id ) {
			Db::update( 'operators', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = Db::now();
			$id                 = Db::insert( 'operators', $data );
		}
		$teams = array_map( 'intval', (array) $r->get_param( 'teams' ) );
		Db::exec( 'DELETE FROM ' . Db::t( 'team_members' ) . ' WHERE operator_id = %d', $id );
		foreach ( $teams as $team ) {
			if ( Db::value( 'SELECT 1 FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', $team ) ) {
				Db::exec( 'INSERT IGNORE INTO ' . Db::t( 'team_members' ) . ' (team_id, operator_id) VALUES (%d, %d)', $team, $id );
			}
		}
		if ( $data['wp_user_id'] ) {
			$user = get_userdata( $data['wp_user_id'] );
			if ( $user && ! user_can( $user, Caps::VIEW_ASSIGNED ) ) {
				$user->add_cap( Caps::VIEW_ASSIGNED );
				$user->add_cap( Caps::REPLY );
			}
		}
		Audit::log( 'user', get_current_user_id(), 'operator.saved', 'operator', $id );
		return array( 'id' => $id );
	}

	public static function delete_operator( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . " SET assigned_operator_id = NULL, status = IF(status IN ('open','waiting'), 'queued', status) WHERE assigned_operator_id = %d", $id );
		Db::exec( 'DELETE FROM ' . Db::t( 'team_members' ) . ' WHERE operator_id = %d', $id );
		// Keep the row for message attribution, but unlink Bale and disable.
		Db::update( 'operators', array( 'status' => 'disabled', 'bale_user_id' => null, 'availability' => 'offline' ), array( 'id' => $id ) );
		Audit::log( 'user', get_current_user_id(), 'operator.disabled', 'operator', $id );
		return array( 'disabled' => true );
	}

	public static function enroll_operator( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Db::value( 'SELECT 1 FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', $id ) ) {
			return new \WP_Error( 'gapban_not_found', __( 'Operator not found.', 'gapban' ), array( 'status' => 404 ) );
		}
		$e        = Enrollment::create( 'operator', $id, null );
		$identity = get_option( BaleProvider::IDENTITY_OPTION );
		return array(
			'id'         => $e['id'],
			'code'       => $e['code'],
			'command'    => '/start ' . $e['code'],
			'bot'        => is_array( $identity ) ? (string) $identity['username'] : '',
			'expires_at' => Serializer::iso( $e['expires_at'] ),
		);
	}

	public static function enrollment( \WP_REST_Request $r ) {
		$e = Enrollment::status( (int) $r['id'] );
		if ( ! $e ) {
			return new \WP_Error( 'gapban_not_found', __( 'Not found.', 'gapban' ), array( 'status' => 404 ) );
		}
		$e['expires_at'] = Serializer::iso( $e['expires_at'] );
		$e['used_at']    = Serializer::iso( $e['used_at'] );
		return $e;
	}

	/* ------------------------------ teams ------------------------------ */

	public static function teams() {
		return array_map(
			static fn( $t ) => array(
				'id'                  => (int) $t['id'],
				'name'                => $t['name'],
				'slug'                => $t['slug'],
				'description'         => $t['description'],
				'destination_chat_id' => $t['destination_chat_id'],
				'destination_title'   => $t['destination_title'],
				'assignment_strategy' => $t['assignment_strategy'],
				'public'              => (bool) (int) $t['public'],
				'active'              => (bool) (int) $t['active'],
				'sort_order'          => (int) $t['sort_order'],
			),
			Db::rows( 'SELECT * FROM ' . Db::t( 'teams' ) . ' ORDER BY sort_order, id' )
		);
	}

	public static function save_team( \WP_REST_Request $r ) {
		$id   = (int) ( $r['id'] ?? 0 );
		$name = mb_substr( sanitize_text_field( (string) $r->get_param( 'name' ) ), 0, 120 );
		if ( '' === $name ) {
			return new \WP_Error( 'gapban_invalid', __( 'Enter the department name.', 'gapban' ), array( 'status' => 422 ) );
		}
		$data = array(
			'name'                => $name,
			'description'         => mb_substr( sanitize_text_field( (string) $r->get_param( 'description' ) ), 0, 255 ),
			'destination_chat_id' => Settings::chat_id( (string) $r->get_param( 'destination_chat_id' ) ),
			'destination_title'   => mb_substr( sanitize_text_field( (string) $r->get_param( 'destination_title' ) ), 0, 190 ),
			'assignment_strategy' => in_array( $r->get_param( 'assignment_strategy' ), array( 'manual', 'round_robin', 'least_loaded' ), true ) ? $r->get_param( 'assignment_strategy' ) : 'manual',
			'public'              => filter_var( $r->get_param( 'public' ) ?? true, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			'active'              => filter_var( $r->get_param( 'active' ) ?? true, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			'sort_order'          => (int) $r->get_param( 'sort_order' ),
		);
		if ( $id ) {
			Db::update( 'teams', $data, array( 'id' => $id ) );
		} else {
			$slug = sanitize_title( $name );
			$data['slug']       = ( '' === $slug || Db::value( 'SELECT 1 FROM ' . Db::t( 'teams' ) . ' WHERE slug = %s', $slug ) ) ? 'team-' . strtolower( Ids::random_base32( 6 ) ) : substr( $slug, 0, 64 );
			$data['created_at'] = Db::now();
			$id                 = Db::insert( 'teams', $data );
		}
		Audit::log( 'user', get_current_user_id(), 'team.saved', 'team', $id );
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'id' => $id );
	}

	public static function delete_team( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'teams' ) ) <= 1 ) {
			return new \WP_Error( 'gapban_last_team', __( 'At least one department is required.', 'gapban' ), array( 'status' => 409 ) );
		}
		// Existing conversations keep their routing snapshot; only the team link is cleared.
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . ' SET team_id = NULL WHERE team_id = %d', $id );
		Db::exec( 'DELETE FROM ' . Db::t( 'team_members' ) . ' WHERE team_id = %d', $id );
		Db::exec( 'DELETE FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', $id );
		Audit::log( 'user', get_current_user_id(), 'team.deleted', 'team', $id );
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'deleted' => true );
	}

	/**
	 * Destination binding code; team id 0 means the global default destination.
	 */
	public static function bind_destination( \WP_REST_Request $r ) {
		$team = (int) $r['id'];
		$e    = Enrollment::create( 'destination', null, $team ?: null );
		return array(
			'id'         => $e['id'],
			'code'       => $e['code'],
			'command'    => '/gapban_bind ' . $e['code'],
			'expires_at' => Serializer::iso( $e['expires_at'] ),
		);
	}

	/* ------------------------------ rules ------------------------------ */

	public static function rules() {
		$stats = array();
		foreach ( Db::rows( 'SELECT rule_id, event, COUNT(*) AS n FROM ' . Db::t( 'rule_events' ) . ' WHERE created_at > %s GROUP BY rule_id, event', Db::at( time() - 30 * DAY_IN_SECONDS ) ) as $s ) {
			$stats[ (int) $s['rule_id'] ][ $s['event'] ] = (int) $s['n'];
		}
		return array_map(
			static function ( $row ) use ( $stats ) {
				$def            = json_decode( (string) $row['definition'], true ) ?: array();
				$def['id']      = (int) $row['id'];
				$def['enabled'] = (bool) (int) $row['enabled'];
				$def['stats']   = $stats[ (int) $row['id'] ] ?? array();
				return $def;
			},
			Db::rows( 'SELECT * FROM ' . Db::t( 'rules' ) . ' ORDER BY priority DESC, id ASC' )
		);
	}

	public static function save_rule( \WP_REST_Request $r ) {
		$valid = RuleValidator::validate( $r->get_json_params() );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$id = RuleEvents::save( $valid, (int) ( $r['id'] ?? 0 ) );
		Audit::log( 'user', get_current_user_id(), 'rule.saved', 'rule', $id );
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'id' => $id, 'rule' => $valid );
	}

	public static function validate_rule( \WP_REST_Request $r ) {
		$valid = RuleValidator::validate( $r->get_json_params() );
		return is_wp_error( $valid ) ? $valid : array( 'rule' => $valid );
	}

	public static function delete_rule( \WP_REST_Request $r ) {
		Db::exec( 'DELETE FROM ' . Db::t( 'rules' ) . ' WHERE id = %d', (int) $r['id'] );
		Db::exec( 'DELETE FROM ' . Db::t( 'rule_events' ) . ' WHERE rule_id = %d', (int) $r['id'] );
		RuleEvents::flush();
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'deleted' => true );
	}

	public static function export_rules() {
		$rules = array_map(
			static function ( $d ) {
				unset( $d['id'], $d['stats'] );
				return $d;
			},
			self::rules()
		);
		return array(
			'format' => 'gapban-rules',
			'version' => 1,
			'rules'  => $rules,
		);
	}

	public static function import_rules( \WP_REST_Request $r ) {
		$data = (array) $r->get_json_params();
		if ( 'gapban-rules' !== ( $data['format'] ?? '' ) ) {
			return new \WP_Error( 'gapban_import_invalid', __( 'This file is not a Gapban rules export.', 'gapban' ), array( 'status' => 422 ) );
		}
		$errors = array();
		$count  = 0;
		foreach ( array_slice( (array) ( $data['rules'] ?? array() ), 0, 100 ) as $i => $def ) {
			$valid = RuleValidator::validate( $def );
			if ( is_wp_error( $valid ) ) {
				$errors[] = array( 'index' => $i, 'message' => $valid->get_error_message() );
				continue;
			}
			$valid['enabled'] = false; // Imported rules start disabled for review.
			RuleEvents::save( $valid );
			++$count;
		}
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'imported' => $count, 'errors' => $errors );
	}

	/**
	 * Lookup data for the rule editor (pages, post types, taxonomies, roles, templates).
	 */
	public static function targeting_data( \WP_REST_Request $r ) {
		$search = sanitize_text_field( (string) $r->get_param( 'search' ) );
		$posts  = get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => 30,
				's'              => $search,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$terms                    = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false, 'number' => 200 ) );
			$taxonomies[ $tax->name ] = array(
				'label' => $tax->label,
				'terms' => is_array( $terms ) ? array_map( static fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), $terms ) : array(),
			);
		}
		return array(
			'posts'      => array_map( static fn( $p ) => array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'type' => $p->post_type, 'url' => get_permalink( $p ) ), $posts ),
			'post_types' => array_values( array_map( static fn( $t ) => array( 'name' => $t->name, 'label' => $t->label ), get_post_types( array( 'public' => true ), 'objects' ) ) ),
			'taxonomies' => $taxonomies,
			'roles'      => array_map( static fn( $k, $v ) => array( 'name' => $k, 'label' => translate_user_role( $v['name'] ) ), array_keys( wp_roles()->roles ), wp_roles()->roles ),
			'templates'  => array_values( array_unique( array_merge( array( 'default' ), array_keys( wp_get_theme()->get_page_templates() ) ) ) ),
			'teams'      => self::teams(),
			'woocommerce'=> class_exists( 'WooCommerce' ),
		);
	}

	/* ---------------------------- knowledge ---------------------------- */

	public static function knowledge( \WP_REST_Request $r ) {
		$type = in_array( $r->get_param( 'type' ), array( 'canned', 'faq' ), true ) ? $r->get_param( 'type' ) : null;
		$rows = $type
			? Db::rows( 'SELECT * FROM ' . Db::t( 'knowledge' ) . ' WHERE type = %s ORDER BY sort_order, id', $type )
			: Db::rows( 'SELECT * FROM ' . Db::t( 'knowledge' ) . ' ORDER BY type, sort_order, id' );
		return array_map(
			static fn( $k ) => array(
				'id'         => (int) $k['id'],
				'type'       => $k['type'],
				'title'      => $k['title'],
				'shortcut'   => $k['shortcut'],
				'body'       => $k['body'],
				'url'        => $k['url'],
				'active'     => (bool) (int) $k['active'],
				'sort_order' => (int) $k['sort_order'],
			),
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $k
	 */
	private static function knowledge_data( array $k ): array {
		return array(
			'type'       => 'faq' === ( $k['type'] ?? '' ) ? 'faq' : 'canned',
			'title'      => mb_substr( sanitize_text_field( (string) ( $k['title'] ?? '' ) ), 0, 190 ),
			'shortcut'   => mb_substr( sanitize_key( (string) ( $k['shortcut'] ?? '' ) ), 0, 64 ),
			'body'       => mb_substr( sanitize_textarea_field( (string) ( $k['body'] ?? '' ) ), 0, 5000 ),
			'url'        => esc_url_raw( (string) ( $k['url'] ?? '' ), array( 'http', 'https' ) ),
			'active'     => ! isset( $k['active'] ) || filter_var( $k['active'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			'sort_order' => (int) ( $k['sort_order'] ?? 0 ),
		);
	}

	private static function insert_knowledge( array $k ): int {
		$data               = self::knowledge_data( $k );
		$data['created_at'] = Db::now();
		return Db::insert( 'knowledge', $data );
	}

	public static function save_knowledge( \WP_REST_Request $r ) {
		$id   = (int) ( $r['id'] ?? 0 );
		$data = self::knowledge_data( (array) $r->get_json_params() );
		if ( '' === $data['title'] || '' === $data['body'] ) {
			return new \WP_Error( 'gapban_invalid', __( 'Title and text are required.', 'gapban' ), array( 'status' => 422 ) );
		}
		if ( $id ) {
			Db::update( 'knowledge', $data, array( 'id' => $id ) );
		} else {
			$id = self::insert_knowledge( $data );
		}
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'id' => $id );
	}

	public static function delete_knowledge( \WP_REST_Request $r ) {
		Db::exec( 'DELETE FROM ' . Db::t( 'knowledge' ) . ' WHERE id = %d', (int) $r['id'] );
		\Gapban\Widget\Frontend::bump_config_version();
		return array( 'deleted' => true );
	}

	/* ----------------------- reports / queue / diag -------------------- */

	public static function reports( \WP_REST_Request $r ) {
		$tz   = Locale::timezone();
		$from = (string) $r->get_param( 'from' );
		$to   = (string) $r->get_param( 'to' );
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : ( new \DateTimeImmutable( '-29 days', $tz ) )->format( 'Y-m-d' );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$utc  = new \DateTimeZone( 'UTC' );
		$f    = ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$t    = ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$out  = Reports::summary( $f, $t );
		$out['local_range'] = array( 'from' => $from, 'to' => $to, 'timezone' => $tz->getName() );
		return $out;
	}

	public static function queue( \WP_REST_Request $r ) {
		$status = in_array( $r->get_param( 'status' ), array( 'pending', 'running', 'dead', 'done', 'discarded' ), true ) ? $r->get_param( 'status' ) : 'dead';
		$rows   = Db::rows( 'SELECT id, type, order_key, status, attempts, max_attempts, available_at, uncertain, last_error, created_at, updated_at, payload FROM ' . Db::t( 'jobs' ) . ' WHERE status = %s ORDER BY id DESC LIMIT 100', $status );
		return array(
			'stats' => JobQueue::stats(),
			'jobs'  => array_map(
				static function ( $j ) {
					$payload = json_decode( (string) $j['payload'], true ) ?: array();
					$conv    = 0 === strpos( (string) $j['order_key'], 'c:' ) ? (int) substr( (string) $j['order_key'], 2 ) : null;
					return array(
						'id'           => (int) $j['id'],
						'type'         => $j['type'],
						'status'       => $j['status'],
						'attempts'     => (int) $j['attempts'],
						'max_attempts' => (int) $j['max_attempts'],
						'uncertain'    => (bool) (int) $j['uncertain'],
						'last_error'   => Redactor::text( (string) $j['last_error'] ),
						'conversation_id' => $conv,
						'message_id'   => isset( $payload['message_id'] ) ? (int) $payload['message_id'] : null,
						'available_at' => Serializer::iso( $j['available_at'] ),
						'created_at'   => Serializer::iso( $j['created_at'] ),
						'updated_at'   => Serializer::iso( $j['updated_at'] ),
					);
				},
				$rows
			),
		);
	}

	public static function queue_retry( \WP_REST_Request $r ) {
		$ok = JobQueue::retry( (int) $r['id'] );
		if ( $ok ) {
			$job     = Db::row( 'SELECT payload FROM ' . Db::t( 'jobs' ) . ' WHERE id = %d', (int) $r['id'] );
			$payload = json_decode( (string) $job['payload'], true ) ?: array();
			if ( ! empty( $payload['message_id'] ) ) {
				Db::update( 'messages', array( 'delivery_status' => 'queued' ), array( 'id' => (int) $payload['message_id'] ) );
			}
			Audit::log( 'user', get_current_user_id(), 'queue.retry', 'job', (int) $r['id'] );
		}
		return array( 'retried' => $ok );
	}

	public static function queue_discard( \WP_REST_Request $r ) {
		$ok = JobQueue::discard( (int) $r['id'] );
		if ( $ok ) {
			Audit::log( 'user', get_current_user_id(), 'queue.discard', 'job', (int) $r['id'] );
		}
		return array( 'discarded' => $ok );
	}

	public static function queue_run() {
		return Worker::run( 15.0, 100 );
	}

	public static function inbound() {
		return array_map(
			static fn( $u ) => array(
				'id'           => (int) $u['id'],
				'update_id'    => $u['update_id'],
				'status'       => $u['status'],
				'attempts'     => (int) $u['attempts'],
				'error'        => $u['error'],
				'received_at'  => Serializer::iso( $u['received_at'] ),
				'processed_at' => Serializer::iso( $u['processed_at'] ),
			),
			Db::rows( 'SELECT id, update_id, status, attempts, error, received_at, processed_at FROM ' . Db::t( 'inbound_updates' ) . ' ORDER BY id DESC LIMIT 50' )
		);
	}

	public static function diagnostics() {
		global $wpdb;
		$tables = array();
		foreach ( Schema::TABLES as $t ) {
			$tables[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $t ) ) );
		}
		$storage_public = null;
		$probe          = AttachmentStorage::public_probe_url();
		if ( $probe ) {
			$res            = wp_remote_head( str_replace( 'index.php', '.htaccess', $probe ), array( 'timeout' => 5, 'redirection' => 0, 'sslverify' => false ) );
			$storage_public = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
		}
		$test = get_option( 'gapban_test_message' );
		return array(
			'versions'          => array(
				'plugin'    => GAPBAN_VERSION,
				'db_schema' => (int) get_option( Schema::OPTION ),
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'database'  => $wpdb->db_server_info(),
			),
			'tables'            => $tables,
			'innodb'            => 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', Schema::table( 'messages' ) ) ),
			'sodium'            => function_exists( 'sodium_crypto_secretbox' ),
			'intl'              => class_exists( 'IntlDateFormatter' ),
			'encryption_key'    => defined( 'GAPBAN_ENCRYPTION_KEY' ) ? 'wp-config' : 'derived-from-salts',
			'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_wp_cron'      => wp_next_scheduled( 'gapban_worker' ) ? gmdate( 'c', (int) wp_next_scheduled( 'gapban_worker' ) ) : null,
			'last_worker_run'   => get_option( 'gapban_last_worker_run' ) ? gmdate( 'c', (int) get_option( 'gapban_last_worker_run' ) ) : null,
			'storage_public'    => $storage_public,
			'storage_custom'    => defined( 'GAPBAN_STORAGE_DIR' ),
			'object_cache'      => wp_using_ext_object_cache(),
			'queue'             => JobQueue::stats(),
			'test_message'      => is_array( $test ) ? array( 'sent_at' => $test['sent_at'], 'reply_received' => (bool) get_option( 'gapban_test_reply_at' ) ) : null,
			'test_reply_at'     => (string) get_option( 'gapban_test_reply_at', '' ),
			'capabilities'      => BaleCaps::matrix(),
			'poll_offset'       => (string) get_option( \Gapban\Bale\Poller::OFFSET_OPTION, '' ),
			'transport'         => Settings::get( 'transport' ),
		);
	}
}
