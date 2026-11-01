<?php
/**
 * Storefront: floating chat, inline chat (block + [dev3sa_chat]) and [dev3sa_chat_button].
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Frontend;

use Dev3\AiShoppingAssistant\Chat\Handoff;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The script is small, deferred to the footer and makes no request until the shopper opens the chat.
 */
class Widget {

	const HANDLE = 'dev3-ai-shopping-assistant-for-woocommerce-storefront';

	/**
	 * Whether an inline chat/button was rendered on this page.
	 *
	 * @var bool
	 */
	private static $needed = false;

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'late' ), 1 );
		add_shortcode( 'dev3sa_chat', array( __CLASS__, 'shortcode_chat' ) );
		add_shortcode( 'dev3sa_chat_button', array( __CLASS__, 'shortcode_button' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
	}

	/**
	 * Server-rendered blocks (editor script: build/blocks.js).
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$asset_file = DEV3SA_PATH . 'build/blocks.asset.php';
		if ( is_readable( $asset_file ) && ! wp_script_is( 'dev3sa-blocks', 'registered' ) ) {
			$asset = include $asset_file;
			wp_register_script( 'dev3sa-blocks', DEV3SA_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'dev3sa-blocks', 'dev3-ai-shopping-assistant-for-woocommerce', DEV3SA_PATH . 'languages' );
		}
		register_block_type(
			'dev3sa/chat',
			array(
				'api_version'     => 2,
				'editor_script'   => 'dev3sa-blocks',
				'attributes'      => array(
					'height' => array(
						'type'    => 'number',
						'default' => 560,
					),
				),
				'render_callback' => static fn( $atts ) => self::shortcode_chat( $atts ),
			)
		);
		register_block_type(
			'dev3sa/chat-button',
			array(
				'api_version'     => 2,
				'editor_script'   => 'dev3sa-blocks',
				'attributes'      => array(
					'label' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => static fn( $atts ) => self::shortcode_button( $atts ),
			)
		);
	}

	/**
	 * Whether the floating chat shows on this request.
	 *
	 * @return bool
	 */
	public static function should_show() {
		$show = (bool) Settings::get( 'widget_enabled', true ) && ! is_admin() && ! is_feed() && ! is_embed();
		if ( $show ) {
			$audience = (string) Settings::get( 'audience', 'all' );
			if ( ( 'guests' === $audience && is_user_logged_in() ) || ( 'customers' === $audience && ! is_user_logged_in() ) ) {
				$show = false;
			}
		}
		$page_id = (int) get_queried_object_id();
		if ( $show && in_array( $page_id, array_map( 'intval', (array) Settings::get( 'exclude_pages', array() ) ), true ) && $page_id ) {
			$show = false;
		}
		if ( $show && Settings::get( 'hide_on_checkout', true ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			$show = false;
		}
		if ( $show ) {
			$where = (string) Settings::get( 'show_on', 'all' );
			if ( 'woo' === $where ) {
				$show = function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
			} elseif ( 'pages' === $where ) {
				$show = $page_id && in_array( $page_id, array_map( 'intval', (array) Settings::get( 'pages', array() ) ), true );
			}
		}
		/**
		 * Whether the floating shopping assistant shows on this page.
		 *
		 * @param bool $show Show.
		 */
		return (bool) apply_filters( 'dev3sa_show_widget', $show );
	}

	/**
	 * Registers the bundle once.
	 *
	 * @return bool
	 */
	private static function register_bundle() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return true;
		}
		$file = DEV3SA_PATH . 'build/storefront.asset.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$asset = include $file;
		wp_register_script( self::HANDLE, DEV3SA_URL . 'build/storefront.js', array(), $asset['version'], true );
		if ( is_readable( DEV3SA_PATH . 'build/storefront.css' ) ) {
			wp_register_style( self::HANDLE, DEV3SA_URL . 'build/storefront.css', array(), $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
		return true;
	}

	/**
	 * Adds the boot script and enqueues.
	 *
	 * @param bool $floating Show the floating launcher.
	 */
	private static function load( $floating ) {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) || ! self::register_bundle() ) {
			return;
		}
		wp_add_inline_script( self::HANDLE, 'window.dev3saChat = ' . wp_json_encode( self::boot( $floating ) ) . ';', 'before' );
		wp_enqueue_script( self::HANDLE );
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
	}

	/**
	 * Enqueues on pages that show the floating chat.
	 */
	public function enqueue() {
		if ( self::should_show() ) {
			self::load( true );
		}
	}

	/**
	 * Inline chat or button rendered after wp_head (classic themes / widgets).
	 */
	public function late() {
		if ( self::$needed ) {
			self::load( self::should_show() );
		}
	}

	/**
	 * Boot data (safe to cache for guests: no nonce, no personal data).
	 *
	 * @param bool $floating Floating launcher.
	 * @return array
	 */
	public static function boot( $floating = true ) {
		$avatar_id  = (int) Settings::get( 'avatar_id', 0 );
		$product_id = function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0;
		$privacy    = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$boot       = array(
			'rest'        => esc_url_raw( rest_url( 'dev3sa/v1/public/' ) ),
			'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'floating'    => (bool) $floating,
			'name'        => (string) Settings::get( 'bot_name' ),
			'subtitle'    => (string) Settings::get( 'subtitle' ),
			'avatar'      => $avatar_id ? (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) : '',
			'accent'      => (string) Settings::get( 'accent_color', '#4f46e5' ),
			'position'    => (string) Settings::get( 'position', 'right' ),
			'offsetX'     => (int) Settings::get( 'offset_x', 20 ),
			'offsetY'     => (int) Settings::get( 'offset_y', 20 ),
			'launcher'    => (string) Settings::get( 'launcher_style', 'label' ),
			'label'       => (string) Settings::get( 'launcher_label' ),
			'greeting'    => (string) Settings::get( 'greeting' ),
			'bubble'      => (bool) Settings::get( 'greeting_bubble', true ),
			'starters'    => array_values( (array) Settings::get( 'starters', array() ) ),
			'placeholder' => (string) Settings::get( 'placeholder' ),
			'autoOpen'    => (int) Settings::get( 'auto_open', 0 ),
			'consent'     => Settings::get( 'show_consent', true ) ? (string) Settings::get( 'consent_text' ) : '',
			'privacy'     => $privacy,
			'online'      => Handoff::options()['online'],
			'features'    => array(
				'cart'    => (bool) Settings::get( 'add_to_cart', true ),
				'orders'  => (bool) Settings::get( 'order_tracking', true ),
				'handoff' => (bool) Settings::get( 'handoff', true ),
			),
			'product'     => $product_id ? array(
				'id'   => $product_id,
				'name' => wp_strip_all_tags( get_the_title( $product_id ) ),
			) : null,
			'loggedIn'    => is_user_logged_in(),
			'lang'        => str_replace( '_', '-', get_locale() ),
			'rtl'         => is_rtl(),
			'i18n'        => self::strings(),
		);
		/**
		 * Filters the storefront chat boot data.
		 *
		 * @param array $boot Boot data.
		 */
		return apply_filters( 'dev3sa_widget_config', $boot );
	}

	/**
	 * Translatable widget strings.
	 *
	 * @return array
	 */
	public static function strings() {
		return array(
			'open'         => __( 'Open chat', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'close'        => __( 'Close chat', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'newChat'      => __( 'Start a new chat', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'send'         => __( 'Send', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'message'      => __( 'Message', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'typing'       => __( 'Typing…', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'online'       => __( 'Online', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'away'         => __( 'Team away · assistant online', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'error'        => __( 'Sorry, something went wrong. Please try again.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'offline'      => __( 'You seem to be offline. Check your connection and try again.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'busy'         => __( 'You are sending messages very fast. Please wait a moment.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'retry'        => __( 'Retry', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'view'         => __( 'View', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'added'        => __( 'Added to your cart', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'adding'       => __( 'Adding…', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'viewCart'     => __( 'View cart', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'checkout'     => __( 'Checkout', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'undo'         => __( 'Undo', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'removed'      => __( 'Removed from your cart.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'outOfStock'   => __( 'Out of stock', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'orderNumber'  => __( 'Order number', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'email'        => __( 'Email', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'name'         => __( 'Name', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'yourMessage'  => __( 'Your message', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'findOrder'    => __( 'Find my order', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'sendTeam'     => __( 'Send to the team', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'sent'         => __( 'Sent', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'whatsapp'     => __( 'WhatsApp', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'call'         => __( 'Call us', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'tracking'     => __( 'Tracking', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'viewOrder'    => __( 'View order', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'total'        => __( 'Total', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'helpful'      => __( 'Helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'notHelpful'   => __( 'Not helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'thanksRating' => __( 'Thanks for the feedback', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'askProduct'   => __( 'Ask about this product', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			/* translators: %s: product name */
			'askAbout'     => __( 'Tell me more about %s', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'privacy'      => __( 'Privacy policy', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'required'     => __( 'Please fill in this field.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'badEmail'     => __( 'Enter a valid email address.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'products'     => __( 'Products', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'chatLog'      => __( 'Chat messages', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'you'          => __( 'You', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'team'         => __( 'Store team', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'unread'       => __( 'New message', 'dev3-ai-shopping-assistant-for-woocommerce' ),
		);
	}

	/**
	 * [dev3sa_chat height="560"]: inline chat.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_chat( $atts = array() ) {
		$atts         = shortcode_atts( array( 'height' => 560 ), (array) $atts, 'dev3sa_chat' );
		$height       = max( 360, min( 1200, (int) $atts['height'] ) );
		self::$needed = true;
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			self::load( self::should_show() );
		}
		return '<div class="dev3sa-inline" data-dev3sa-inline style="--dev3sa-inline-h:' . esc_attr( $height ) . 'px"><div class="dev3sa-inline__placeholder" aria-hidden="true"></div></div>';
	}

	/**
	 * [dev3sa_chat_button label="…"]: button that opens the chat.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_button( $atts = array() ) {
		$atts         = shortcode_atts( array( 'label' => '' ), (array) $atts, 'dev3sa_chat_button' );
		$label        = '' !== trim( (string) $atts['label'] ) ? (string) $atts['label'] : (string) Settings::get( 'launcher_label' );
		self::$needed = true;
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			self::load( self::should_show() );
		}
		return '<button type="button" class="dev3sa-open-button wp-element-button" data-dev3sa-open>' . esc_html( $label ) . '</button>';
	}
}
