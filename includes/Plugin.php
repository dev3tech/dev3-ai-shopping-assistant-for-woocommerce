<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant;

use Dev3\AiShoppingAssistant\Core3\Core\Assets;
use Dev3\AiShoppingAssistant\Core3\Core\Privacy;
use Dev3\AiShoppingAssistant\Core3\Core\Schema;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Tables;
use Dev3\AiShoppingAssistant\Index\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3sa_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SA_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Indexer::EVENT );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3sa_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3sa_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3sa_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3sa_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SA_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Conversations::class, 'purge' ) );
		add_action( self::CRON_HOOK, array( Indexer::class, 'run_dirty' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 5 );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ) );
		// Background job types must be registered on every request (the runner looks them up).
		Indexer::register_job();
		add_action( 'admin_init', array( $this, 'admin_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woo' ) );
			return;
		}
		( new Index\Indexer() )->register();
		( new Woo\Attribution() )->register();
		( new Frontend\Widget() )->register();
	}

	/**
	 * Admin bundle (registered once; Assets::register adds the boot script on every call).
	 */
	public function admin_assets() {
		if ( wp_script_is( Assets::handle( 'admin' ), 'registered' ) ) {
			return;
		}
		Assets::register(
			array( 'admin' ),
			array(
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'chatStrings' => Frontend\Widget::strings(),
				'homeUrl'     => home_url( '/' ),
				'avatarUrl'   => (string) wp_get_attachment_image_url( (int) Settings::get( 'avatar_id', 0 ), 'thumbnail' ),
			)
		);
	}

	/**
	 * GDPR exporter/eraser for chats (registered on init so the label can be translated).
	 */
	public function privacy() {
		Privacy::add_exporter( __( 'Shopping assistant chats', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( Conversations::class, 'export_personal_data' ) );
		Privacy::add_eraser( __( 'Shopping assistant chats', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( Conversations::class, 'erase_personal_data' ) );
	}

	/**
	 * Translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-shopping-assistant-for-woocommerce', false, dirname( plugin_basename( DEV3SA_FILE ) ) . '/languages' );
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility.
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3SA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3SA_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function needs_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Shopping Assistant & Chatbot needs WooCommerce to be active.', 'dev3-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		if ( class_exists( 'WooCommerce' ) ) {
			( new Rest\Public_Controller() )->register_routes();
		}
	}
}
