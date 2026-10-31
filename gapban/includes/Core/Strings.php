<?php
/**
 * Translatable UI strings handed to JavaScript. All strings go through gettext
 * (text domain "gapban"), so the POT/PO/MO files cover PHP and JS alike.
 *
 * @package Gapban
 */

namespace Gapban\Core;

defined( 'ABSPATH' ) || exit;

final class Strings {

	/**
	 * Evaluate $fn with the "gapban" text domain temporarily loaded for $locale,
	 * independent of the site/user locale and of installed core language packs.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function in_locale( string $locale, callable $fn ) {
		global $l10n;
		$current = determine_locale();
		if ( $current === $locale ) {
			return $fn();
		}
		$backup = $l10n['gapban'] ?? null;
		if ( 'en_US' === $locale ) {
			$l10n['gapban'] = new \NOOP_Translations();
		} else {
			$mo = new \MO();
			$l10n['gapban'] = $mo->import_from_file( GAPBAN_DIR . 'languages/gapban-' . $locale . '.mo' ) ? $mo : new \NOOP_Translations();
		}
		try {
			return $fn();
		} finally {
			if ( null === $backup ) {
				unset( $l10n['gapban'] );
			} else {
				$l10n['gapban'] = $backup;
			}
		}
	}

	/**
	 * Visitor-facing messages for ChatException codes.
	 */
	public static function error( string $code ): string {
		$map = array(
			'conversation_closed_for_visitor' => __( 'This conversation is closed. Please start a new one.', 'gapban' ),
			'conversation_is_spam'            => __( 'This conversation is not available.', 'gapban' ),
			'no_conversation'                 => __( 'Please start a conversation first.', 'gapban' ),
			'not_found'                       => __( 'Not found.', 'gapban' ),
			'forbidden'                       => __( 'You are not allowed to do that.', 'gapban' ),
			'invalid_transition'              => __( 'That action is not possible in the conversation\'s current state.', 'gapban' ),
			'conflict'                        => __( 'Someone else changed this conversation. Reload and try again.', 'gapban' ),
			'claim_conflict'                  => __( 'Another operator has already claimed this conversation.', 'gapban' ),
			'transfer_conflict'               => __( 'The assignment changed meanwhile. Reload and try again.', 'gapban' ),
			'invalid_operator'                => __( 'That operator is not active.', 'gapban' ),
			'invalid_action'                  => __( 'Unknown action.', 'gapban' ),
		);
		return $map[ $code ] ?? __( 'Something went wrong. Please try again.', 'gapban' );
	}

	/**
	 * Widget dictionary (rendered in the widget language, not the admin's).
	 *
	 * @return array<string, string>
	 */
	public static function widget(): array {
		return array(
			'open_chat'             => __( 'Chat with us', 'gapban' ),
			'unread_count'          => __( '%d unread messages', 'gapban' ),
			'dismiss'               => __( 'Dismiss', 'gapban' ),
			'error_loading'         => __( 'The chat could not be loaded. Please check your connection and try again.', 'gapban' ),
			'automated_message'     => __( 'Automated message', 'gapban' ),
			'new_reply_teaser'      => __( 'You have a new reply from our team.', 'gapban' ),
			'collapse'              => __( 'Collapse', 'gapban' ),
			'expand'                => __( 'Expand', 'gapban' ),
			'more_options'          => __( 'More options', 'gapban' ),
			'minimize'              => __( 'Minimize chat', 'gapban' ),
			'status_online'         => __( 'Online — we usually reply within minutes', 'gapban' ),
			'status_away'           => __( 'Our team is away right now; leave a message', 'gapban' ),
			'status_temporary_away' => __( 'Temporarily away', 'gapban' ),
			'status_offline'        => __( 'Outside business hours; leave a message', 'gapban' ),
			'status_holiday'        => __( 'Closed for the holiday; leave a message', 'gapban' ),
			'operator_typing'       => __( 'Typing…', 'gapban' ),
			'reference'             => __( 'Reference', 'gapban' ),
			'powered_by_brand'      => __( 'Messages are stored securely on this website.', 'gapban' ),
			'sound_on'              => __( 'Turn sound on', 'gapban' ),
			'sound_off'             => __( 'Turn sound off', 'gapban' ),
			'enable_notifications'  => __( 'Notify me about new replies', 'gapban' ),
			'download_transcript'   => __( 'Download transcript', 'gapban' ),
			'end_chat'              => __( 'End conversation', 'gapban' ),
			'end_chat_confirm'      => __( 'End this conversation?', 'gapban' ),
			'start_chat'            => __( 'Start a conversation', 'gapban' ),
			'leave_message'         => __( 'Leave a message', 'gapban' ),
			'suggested_questions'   => __( 'Common questions', 'gapban' ),
			'faq_title'             => __( 'Help articles', 'gapban' ),
			'read_more'             => __( 'Read more', 'gapban' ),
			'talk_to_human'         => __( 'Talk to a person', 'gapban' ),
			'offline_form_title'    => __( 'Leave us a message', 'gapban' ),
			'prechat_title'         => __( 'Before we start', 'gapban' ),
			'prechat_intro'         => __( 'A few details help us answer faster.', 'gapban' ),
			'field_name'            => __( 'Your name', 'gapban' ),
			'field_email'           => __( 'Email', 'gapban' ),
			'field_phone'           => __( 'Mobile number', 'gapban' ),
			'field_department'      => __( 'Department', 'gapban' ),
			'choose_department'     => __( 'Choose…', 'gapban' ),
			'field_required'        => __( 'This field is required.', 'gapban' ),
			'continue'              => __( 'Continue', 'gapban' ),
			'back'                  => __( 'Back', 'gapban' ),
			'messages'              => __( 'Messages', 'gapban' ),
			'new_messages'          => __( 'New messages ↓', 'gapban' ),
			'load_older'            => __( 'Load earlier messages', 'gapban' ),
			'event_closed'          => __( 'The conversation was closed', 'gapban' ),
			'event_reopened'        => __( 'The conversation was reopened', 'gapban' ),
			'support_team'          => __( 'Support team', 'gapban' ),
			'sending'               => __( 'Sending…', 'gapban' ),
			'not_sent'              => __( 'Not sent.', 'gapban' ),
			'retry'                 => __( 'Retry', 'gapban' ),
			'status_accepted'       => __( 'Received by the website', 'gapban' ),
			'status_delivered'      => __( 'Delivered to the support team', 'gapban' ),
			'status_seen'           => __( 'Seen by the support team', 'gapban' ),
			'file_unavailable'      => __( 'The file is no longer available.', 'gapban' ),
			'download'              => __( 'Download', 'gapban' ),
			'close'                 => __( 'Close', 'gapban' ),
			'rate_title'            => __( 'How was your conversation?', 'gapban' ),
			'rate_n'                => __( '%d out of 5', 'gapban' ),
			'rate_comment'          => __( 'Anything to add? (optional)', 'gapban' ),
			'submit_rating'         => __( 'Send rating', 'gapban' ),
			'rate_thanks'           => __( 'Thank you for your feedback!', 'gapban' ),
			'new_conversation'      => __( 'Start a new conversation', 'gapban' ),
			'type_message'          => __( 'Write a message…', 'gapban' ),
			'send'                  => __( 'Send', 'gapban' ),
			'emoji'                 => __( 'Emoji', 'gapban' ),
			'attach_file'           => __( 'Attach a file', 'gapban' ),
			'recording'             => __( 'Recording…', 'gapban' ),
			'record_voice'          => __( 'Record a voice message (sent as an audio file)', 'gapban' ),
			'voice_message'         => __( 'Voice message', 'gapban' ),
			'uploading'             => __( 'Uploading', 'gapban' ),
			'cancel_upload'         => __( 'Cancel upload', 'gapban' ),
			'remove_file'           => __( 'Remove file', 'gapban' ),
			'file_too_large'        => __( 'Files can be at most %d MB.', 'gapban' ),
			'file_type_not_allowed' => __( 'This file type is not allowed.', 'gapban' ),
			'mic_denied'            => __( 'Microphone access was not allowed. You can type or attach a file instead.', 'gapban' ),
			'wait_for_upload'       => __( 'Please wait until the upload finishes.', 'gapban' ),
			'connection_lost'       => __( 'Connection lost. Reconnecting…', 'gapban' ),
			'sent_a_file'           => __( 'Sent a file', 'gapban' ),
			'rate_limited'          => __( 'You are sending messages too quickly. Please wait a moment.', 'gapban' ),
			'generic_error'         => __( 'Something went wrong. Please try again.', 'gapban' ),
			'loading'               => __( 'Loading…', 'gapban' ),
		);
	}
}
