<?php
/**
 * Plugin Name: WPX - Agent Control Plane
 * Plugin URI: https://github.com/beylerburak/wpx
 * Description: Agent-native CLI bridge for WordPress + Elementor. Enables AI coding agents and developers to manage WordPress sites and edit Elementor designs from the terminal.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Beyler Interactive
 * Author URI: https://beyler.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpx
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPX_VERSION', '0.1.0' );
define( 'WPX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main WPX Plugin Class
 */
final class WPX_Plugin {

    /**
     * Singleton instance.
     *
     * @var WPX_Plugin|null
     */
    private static ?WPX_Plugin $instance = null;

    /**
     * Get singleton instance.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files.
     */
    private function load_dependencies(): void {
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-operation-history.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-lock.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-elementor-compat.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-elementor-controls.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-elementor-bridge.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-atomic-bridge.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-atomic-styles.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-elementor-save.php';
        require_once WPX_PLUGIN_DIR . 'includes/class-wpx-elementor-globals.php';

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once WPX_PLUGIN_DIR . 'includes/class-wpx-cli-commands.php';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks(): void {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
    }

    /**
     * Plugin activation.
     */
    public function activate(): void {
        WPX_Operation_History::create_table();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin on WordPress init.
     */
    public function init(): void {
        // Future: REST API endpoints for non-SSH mode
    }

    /**
     * Check if Elementor is active.
     */
    public static function is_elementor_active(): bool {
        return defined( 'ELEMENTOR_VERSION' ) && did_action( 'elementor/loaded' );
    }

    /**
     * Get Elementor bridge instance.
     */
    public function elementor_bridge(): ?WPX_Elementor_Bridge {
        if ( ! self::is_elementor_active() ) {
            return null;
        }
        return new WPX_Elementor_Bridge();
    }
}

// Initialize plugin
WPX_Plugin::instance();
