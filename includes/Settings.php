<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant;

use Dev3\AiShoppingAssistant\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3sa_settings_defaults / dev3sa_settings_sanitize. Every key is described once in
 * spec() (type + limits) and sanitized generically from that.
 */
class Settings {

	/**
	 * Key => [ type, default, extra ]. Types: bool, int (min,max), text (max), textarea (max),
	 * color, enum (choices), ids, list (max items, max length), time.
	 *
	 * @return array
	 */
	public static function spec() {
		return array(
			// Widget.
			'widget_enabled'    => array( 'bool', true ),
			'bot_name'          => array( 'text', __( 'Shopping assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ), 40 ),
			'subtitle'          => array( 'text', __( 'Ask about products, orders and shipping', 'dev3-ai-shopping-assistant-for-woocommerce' ), 70 ),
			'avatar_id'         => array( 'int', 0, 0, PHP_INT_MAX ),
			'accent_color'      => array( 'color', '#4f46e5' ),
			'position'          => array( 'enum', 'right', array( 'right', 'left' ) ),
			'offset_x'          => array( 'int', 20, 0, 200 ),
			'offset_y'          => array( 'int', 20, 0, 300 ),
			'launcher_style'    => array( 'enum', 'label', array( 'label', 'icon' ) ),
			'launcher_label'    => array( 'text', __( 'Chat with us', 'dev3-ai-shopping-assistant-for-woocommerce' ), 30 ),
			'greeting'          => array( 'textarea', __( 'Hi! I can help you find products, track an order or answer questions about shipping and returns. What are you looking for?', 'dev3-ai-shopping-assistant-for-woocommerce' ), 400 ),
			'starters'          => array(
				'list',
				array(
					__( 'Show me best sellers', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					__( 'Track my order', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					__( 'Shipping & returns', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					__( 'Talk to a person', 'dev3-ai-shopping-assistant-for-woocommerce' ),
				),
				6,
				40,
			),
			'placeholder'       => array( 'text', __( 'Ask about products, orders or shipping…', 'dev3-ai-shopping-assistant-for-woocommerce' ), 80 ),
			'away_message'      => array( 'textarea', __( 'Our team is offline right now. Leave a message and we will reply by email.', 'dev3-ai-shopping-assistant-for-woocommerce' ), 300 ),
			'greeting_bubble'   => array( 'bool', true ),
			'auto_open'         => array( 'int', 0, 0, 600 ),
			// Targeting.
			'show_on'           => array( 'enum', 'all', array( 'all', 'woo', 'pages' ) ),
			'pages'             => array( 'ids', array() ),
			'exclude_pages'     => array( 'ids', array() ),
			'hide_on_checkout'  => array( 'bool', true ),
			'audience'          => array( 'enum', 'all', array( 'all', 'guests', 'customers' ) ),
			// Capabilities.
			'product_search'    => array( 'bool', true ),
			'add_to_cart'       => array( 'bool', true ),
			'order_tracking'    => array( 'bool', true ),
			'handoff'           => array( 'bool', true ),
			'max_products'      => array( 'int', 4, 1, 8 ),
			'hide_out_of_stock' => array( 'bool', false ),
			// Handoff / contact.
			'handoff_email'     => array( 'email', '' ),
			'whatsapp'          => array( 'phone', '' ),
			'phone'             => array( 'phone', '' ),
			'hours_enabled'     => array( 'bool', false ),
			'hours_start'       => array( 'time', '09:00' ),
			'hours_end'         => array( 'time', '17:00' ),
			'hours_days'        => array( 'days', array( 1, 2, 3, 4, 5 ) ),
			// AI.
			'ai_chat'           => array( 'bool', true ),
			'ai_tone'           => array( 'enum', 'friendly', array( 'friendly', 'professional', 'playful', 'luxury', 'concise' ) ),
			'answer_length'     => array( 'enum', 'short', array( 'short', 'medium' ) ),
			'ai_instructions'   => array( 'textarea', '', 1500 ),
			'ai_auto_add'       => array( 'bool', true ),
			'max_ai_turns'      => array( 'int', 40, 1, 500 ),
			'chat_rate_limit'   => array( 'int', 12, 1, 120 ),
			'auto_index'        => array( 'bool', true ),
			// Privacy.
			'store_transcripts' => array( 'bool', true ),
			'retention_days'    => array( 'int', 90, 1, 3650 ),
			'show_consent'      => array( 'bool', true ),
			'consent_text'      => array( 'text', __( 'Chats are stored to help us improve. See our privacy policy.', 'dev3-ai-shopping-assistant-for-woocommerce' ), 160 ),
		);
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$out = array();
		foreach ( self::spec() as $key => $row ) {
			$out[ $key ] = $row[1];
		}
		// Assistant answers are factual: keep the shared temperature low.
		$defaults['temperature'] = 0.3;
		return array_merge( $defaults, $out );
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::spec() as $key => $row ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::clean( $row, $input[ $key ] );
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Cleans one value by its spec row. Returns null to keep the current value.
	 *
	 * @param array $row   Spec row.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function clean( array $row, $value ) {
		switch ( $row[0] ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				return (int) min( $row[3], max( $row[2], (int) $value ) );
			case 'text':
				return mb_substr( sanitize_text_field( wp_unslash( (string) $value ) ), 0, $row[2] );
			case 'textarea':
				return mb_substr( sanitize_textarea_field( wp_unslash( (string) $value ) ), 0, $row[2] );
			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : null;
			case 'enum':
				return in_array( (string) $value, $row[2], true ) ? (string) $value : null;
			case 'ids':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			case 'list':
				$items = is_array( $value ) ? $value : explode( "\n", (string) $value );
				$clean = array();
				foreach ( $items as $item ) {
					$item = mb_substr( trim( sanitize_text_field( wp_unslash( (string) $item ) ) ), 0, $row[3] );
					if ( '' !== $item ) {
						$clean[] = $item;
					}
				}
				return array_slice( array_values( array_unique( $clean ) ), 0, $row[2] );
			case 'email':
				$email = sanitize_email( (string) $value );
				return '' === trim( (string) $value ) || is_email( $email ) ? $email : null;
			case 'phone':
				return mb_substr( preg_replace( '/[^0-9+]/', '', (string) $value ), 0, 20 );
			case 'time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : null;
			case 'days':
				$days = array_map( 'intval', (array) $value );
				return array_values( array_unique( array_filter( $days, static fn( $d ) => $d >= 0 && $d <= 6 ) ) );
		}
		return null;
	}

	/**
	 * Shortcut.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Address that receives handoff emails.
	 *
	 * @return string
	 */
	public static function handoff_email() {
		$email = (string) self::get( 'handoff_email', '' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Whether the team is inside business hours (always true when hours are off).
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return bool
	 */
	public static function is_online( $now = null ) {
		if ( ! self::get( 'hours_enabled', false ) ) {
			return true;
		}
		$date = ( new \DateTimeImmutable( '@' . ( null === $now ? time() : (int) $now ) ) )->setTimezone( wp_timezone() );
		$day  = (int) $date->format( 'w' );
		if ( ! in_array( $day, array_map( 'intval', (array) self::get( 'hours_days', array() ) ), true ) ) {
			return false;
		}
		$time = $date->format( 'H:i' );
		return $time >= (string) self::get( 'hours_start', '09:00' ) && $time < (string) self::get( 'hours_end', '17:00' );
	}
}
