<?php
/**
 * Human handoff: emails the store with the transcript; admin replies go to the shopper by email.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails (no HTML from shoppers is ever rendered).
 */
class Handoff {

	/**
	 * Contact options shown with the handoff form.
	 *
	 * @return array { online, away, whatsapp, phone }
	 */
	public static function options() {
		$whatsapp = (string) Settings::get( 'whatsapp', '' );
		$phone    = (string) Settings::get( 'phone', '' );
		return array(
			'online'   => Settings::is_online(),
			'away'     => Settings::is_online() ? '' : (string) Settings::get( 'away_message', '' ),
			'whatsapp' => '' !== $whatsapp ? 'https://wa.me/' . ltrim( $whatsapp, '+' ) : '',
			'phone'    => '' !== $phone ? 'tel:' . $phone : '',
		);
	}

	/**
	 * Sends a handoff request.
	 *
	 * @param array  $conversation Conversation.
	 * @param string $name         Shopper name.
	 * @param string $email        Shopper email.
	 * @param string $message      Message.
	 * @return true|\WP_Error
	 */
	public static function send( array $conversation, $name, $email, $message ) {
		$name    = mb_substr( sanitize_text_field( (string) $name ), 0, 120 );
		$email   = sanitize_email( (string) $email );
		$message = Text::input( $message, 2000 );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3sa_bad_email', __( 'Enter a valid email address so we can reply.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( '' === $message ) {
			return new \WP_Error( 'dev3sa_empty_message', __( 'Write a short message for our team.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		Conversations::update(
			$conversation['id'],
			array(
				'name'   => $name,
				'email'  => $email,
				'status' => 'needs_reply',
			)
		);
		Conversations::add_message(
			$conversation['id'],
			'user',
			$message,
			array(
				'intent' => 'handoff',
				'source' => 'form',
			)
		);
		$lines = array();
		foreach ( Conversations::messages( $conversation['id'], 60 ) as $m ) {
			$lines[] = strtoupper( $m['role'] ) . ': ' . $m['content'];
		}
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: shopper name or email, 2: site name */
		$subject = sprintf( __( '[%2$s] Chat: %1$s needs a reply', 'dev3-ai-shopping-assistant-for-woocommerce' ), '' !== $name ? $name : $email, $blog );
		$body    = sprintf(
			/* translators: 1: name, 2: email, 3: message, 4: transcript, 5: admin link */
			__( "Name: %1\$s\nEmail: %2\$s\n\nMessage:\n%3\$s\n\nTranscript:\n%4\$s\n\nOpen the conversation: %5\$s", 'dev3-ai-shopping-assistant-for-woocommerce' ),
			$name,
			$email,
			$message,
			implode( "\n", $lines ),
			admin_url( 'admin.php?page=' . DEV3SA_SLUG . '#/conversations/' . $conversation['id'] )
		);
		$sent = wp_mail( Settings::handoff_email(), $subject, $body, array( 'Reply-To: ' . ( '' !== $name ? $name . ' ' : '' ) . '<' . $email . '>' ) );
		/**
		 * Fires after a shopper asks for a human.
		 *
		 * @param array  $conversation Conversation.
		 * @param string $message      Message.
		 */
		do_action( 'dev3sa_handoff_requested', $conversation, $message );
		return $sent ? true : new \WP_Error( 'dev3sa_mail_failed', __( 'We could not send your message. Please try again or contact us by email.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 500 ) );
	}

	/**
	 * Admin reply by email; stored as an agent message.
	 *
	 * @param array  $conversation Conversation.
	 * @param string $reply        Text.
	 * @param bool   $resolve      Mark resolved.
	 * @return true|\WP_Error
	 */
	public static function reply( array $conversation, $reply, $resolve = true ) {
		$reply = Text::input( $reply, 4000 );
		if ( '' === $reply ) {
			return new \WP_Error( 'dev3sa_empty_reply', __( 'Write a reply first.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $conversation['email'] ) ) {
			return new \WP_Error( 'dev3sa_no_email', __( 'This shopper did not leave an email address.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$sent = wp_mail( $conversation['email'], sprintf( __( 'Reply from %s', 'dev3-ai-shopping-assistant-for-woocommerce' ), $blog ), $reply . "\n\n— " . $blog . "\n" . home_url( '/' ) );
		if ( ! $sent ) {
			return new \WP_Error( 'dev3sa_mail_failed', __( 'The email could not be sent. Check your site’s mail settings.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 500 ) );
		}
		Conversations::add_message(
			$conversation['id'],
			'agent',
			$reply,
			array(
				'source' => 'agent',
				'meta'   => array( 'by' => get_current_user_id() ),
			)
		);
		Conversations::update( $conversation['id'], array( 'status' => $resolve ? 'resolved' : 'open' ) );
		return true;
	}
}
