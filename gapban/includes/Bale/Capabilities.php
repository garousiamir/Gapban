<?php
/**
 * Bale capability matrix shown in setup/diagnostics and consulted by routing.
 *
 * Status values:
 *   observed    – this installation successfully performed the operation (timestamp recorded).
 *   unverified  – implemented against the Bot API convention but not yet observed on this site.
 *   unsupported – deliberately not offered; see note.
 *
 * @package Gapban
 */

namespace Gapban\Bale;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/**
	 * Operation => observation key(s).
	 */
	private const OBSERVABLE = array(
		'identity'       => array( 'getMe' ),
		'send_text'      => array( 'sendMessage' ),
		'send_photo'     => array( 'sendPhoto' ),
		'send_document'  => array( 'sendDocument' ),
		'receive_text'   => array( 'receive_text' ),
		'reply_metadata' => array( 'reply_metadata' ),
		'receive_media'  => array( 'getFile' ),
		'webhook'        => array( 'setWebhook', 'webhook_delivery' ),
		'get_updates'    => array( 'getUpdates' ),
	);

	/**
	 * @return array<string, array{status:string, label:string, note:string, observed_at:string}>
	 */
	public static function matrix(): array {
		$observed = get_option( BaleProvider::OBSERVED_OPTION, array() );
		$observed = is_array( $observed ) ? $observed : array();

		$rows = array(
			'identity'       => __( 'Bot identity (getMe)', 'gapban' ),
			'send_text'      => __( 'Send text (sendMessage)', 'gapban' ),
			'send_photo'     => __( 'Send photo (sendPhoto)', 'gapban' ),
			'send_document'  => __( 'Send document (sendDocument)', 'gapban' ),
			'receive_text'   => __( 'Receive operator text', 'gapban' ),
			'reply_metadata' => __( 'Reply metadata (reply_to_message)', 'gapban' ),
			'receive_media'  => __( 'Receive operator files (getFile)', 'gapban' ),
			'webhook'        => __( 'Webhook delivery (setWebhook)', 'gapban' ),
			'get_updates'    => __( 'Polling fallback (getUpdates)', 'gapban' ),
		);

		$out = array();
		foreach ( $rows as $key => $label ) {
			$at = '';
			foreach ( self::OBSERVABLE[ $key ] as $obs ) {
				if ( ! empty( $observed[ $obs ] ) ) {
					$at = (string) $observed[ $obs ];
				}
			}
			$out[ $key ] = array(
				'status'      => $at ? 'observed' : 'unverified',
				'label'       => $label,
				'note'        => $at ? '' : __( 'Implemented; not yet observed working on this site. Run the connection test.', 'gapban' ),
				'observed_at' => $at,
			);
		}

		$out['topics'] = array(
			'status'      => 'unsupported',
			'label'       => __( 'Topics (one topic per conversation)', 'gapban' ),
			'note'        => self::topics_explanation(),
			'observed_at' => '',
		);
		$out['inline_callbacks'] = array(
			'status'      => 'unsupported',
			'label'       => __( 'Inline buttons / callback queries', 'gapban' ),
			'note'        => __( 'Not offered. Operators use text commands (/close, /claim) in reply to a conversation message; every command is authorized against the operator allowlist.', 'gapban' ),
			'observed_at' => '',
		);
		$out['webhook_secret_header'] = array(
			'status'      => 'unsupported',
			'label'       => __( 'Signed webhook header', 'gapban' ),
			'note'        => __( 'No signed or header-authenticated webhook mechanism is assumed for Bale. Gapban uses a high-entropy, revocable secret URL path plus sender/chat allowlists.', 'gapban' ),
			'observed_at' => '',
		);
		$out['read_receipts'] = array(
			'status'      => 'unsupported',
			'label'       => __( 'Operator read receipts, presence and typing from Bale', 'gapban' ),
			'note'        => __( 'Not available to bots; Gapban never fabricates them. Website read/typing markers come only from the WordPress inbox.', 'gapban' ),
			'observed_at' => '',
		);
		$out['edit_sync'] = array(
			'status'      => 'unsupported',
			'label'       => __( 'Syncing edits made in Bale', 'gapban' ),
			'note'        => __( 'Edited operator messages are recorded as an audit event and are not re-sent to the visitor.', 'gapban' ),
			'observed_at' => '',
		);
		return $out;
	}

	public static function topics_supported(): bool {
		return false;
	}

	public static function topics_explanation(): string {
		return __( 'Topics are not supported by the current Bale API.', 'gapban' ) . ' ' .
			__( 'Gapban could not confirm topic creation, topic identifiers and per-topic message routing in the official Bale Bot API, so Topics Mode cannot be selected. Reply Mode is used instead; for real per-conversation separation use the WordPress inbox or separate destination groups per department.', 'gapban' );
	}
}
