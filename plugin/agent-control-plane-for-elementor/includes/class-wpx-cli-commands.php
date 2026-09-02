<?php
/**
 * WPX CLI Commands
 *
 * Registers WP-CLI commands under the `wp wpx` namespace.
 * These commands are the bridge between the Go CLI (via SSH)
 * and the WordPress/Elementor functionality.
 *
 * All commands output JSON by default for machine consumption.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * WPX agent control plane for WordPress + Elementor.
 *
 * ## EXAMPLES
 *
 *     # Get site info
 *     wp wpx site-info
 *
 *     # List Elementor pages
 *     wp wpx pages
 *
 *     # Show element tree for a page
 *     wp wpx elementor tree 241
 *
 *     # Get element details
 *     wp wpx elementor get 241 f921a
 *
 *     # Update element settings
 *     wp wpx elementor set 241 f921a --settings='{"title":"New Title"}'
 *
 * @when after_wp_load
 */
class WPX_CLI_Commands {

    /**
     * Display site information.
     *
     * Returns WordPress version, active theme, plugin list, and Elementor status.
     *
     * ## EXAMPLES
     *
     *     wp wpx site-info
     *
     * @subcommand site-info
     */
    public function site_info( $args, $assoc_args ) {
        global $wp_version;

        $theme   = wp_get_theme();
        $plugins = get_plugins();
        $active  = get_option( 'active_plugins', [] );

        $plugin_list = [];
        foreach ( $plugins as $path => $data ) {
            $plugin_list[] = [
                'name'    => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
                'active'  => in_array( $path, $active, true ),
                'path'    => $path,
            ];
        }

        $info = [
            'wordpress' => [
                'version'    => $wp_version,
                'site_url'   => site_url(),
                'home_url'   => home_url(),
                'multisite'  => is_multisite(),
                'language'   => get_locale(),
                'timezone'   => wp_timezone_string(),
                'permalink'  => get_option( 'permalink_structure' ),
            ],
            'theme' => [
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            ],
            'elementor' => [
                'active'  => WPX_Plugin::is_elementor_active(),
                'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
                'pro'     => defined( 'ELEMENTOR_PRO_VERSION' ),
                'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
            ],
            'plugins'   => $plugin_list,
            'wpx'       => [
                'version' => WPX_VERSION,
            ],
        ];

        WP_CLI::line( wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * List pages built with Elementor.
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Post type to query. Default: page.
     *
     * [--status=<status>]
     * : Post status. Default: any.
     *
     * ## EXAMPLES
     *
     *     wp wpx pages
     *     wp wpx pages --post-type=post
     *
     */
    public function pages( $args, $assoc_args ) {
        $post_type = $assoc_args['post-type'] ?? 'page';
        $status    = $assoc_args['status'] ?? 'any';

        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Elementor records builder ownership in post meta.
            'meta_query'     => [
                [
                    'key'   => '_elementor_edit_mode',
                    'value' => 'builder',
                ],
            ],
        ] );

        $result = [];
        foreach ( $posts as $post ) {
            $raw_data    = get_post_meta( $post->ID, '_elementor_data', true );
            $elements    = ! empty( $raw_data ) ? json_decode( $raw_data, true ) : [];
            $widget_count = $this->count_widgets( $elements );

            $result[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'status'       => $post->post_status,
                'slug'         => $post->post_name,
                'url'          => get_permalink( $post->ID ),
                'modified'     => $post->post_modified,
                'element_count' => $widget_count,
            ];
        }

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Show Elementor element tree for a page.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to display the tree for.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor tree 241
     *
     * @subcommand elementor-tree
     */
    public function elementor_tree( $args, $assoc_args ) {
        $post_id = (int) $args[0];
        $bridge  = $this->bridge_for( $post_id );

        if ( ! ( new WPX_Elementor_Bridge() )->is_elementor_post( $post_id ) ) {
            WP_CLI::error( "Post {$post_id} is not built with Elementor." );
            return;
        }

        $tree = $bridge->build_tree( $post_id );

        if ( null === $tree ) {
            WP_CLI::error( "Failed to build tree for post {$post_id}." );
            return;
        }

        WP_CLI::line( wp_json_encode( $tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Get a single Elementor element with all its settings.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The Elementor element ID (7-char alphanumeric).
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor get 241 f921a
     *
     * @subcommand elementor-get
     */
    public function elementor_get( $args, $assoc_args ) {
        $post_id    = (int) $args[0];
        $element_id = $args[1];

        // The V4 bridge has no path lookup, so build the equivalent from the
        // pieces it does expose rather than pretending the classic one applies.
        if ( 'atomic' === WPX_Elementor_Compat::document_model( $post_id ) ) {
            $atomic  = new WPX_Atomic_Bridge();
            $element = $atomic->find_element( $post_id, $element_id );

            if ( null === $element ) {
                WP_CLI::error( "Element '{$element_id}' not found in post {$post_id}." );
                return;
            }

            $elements = $atomic->get_elements_data( $post_id ) ?? [];
            $origin   = $atomic->find_parent_and_index( $elements, $element_id );

            WP_CLI::line( wp_json_encode( [
                'element'  => $element,
                'readable' => array_map( [ $atomic, 'unwrap_prop' ], $element['settings'] ?? [] ),
                'styles'   => ( new WPX_Atomic_Styles() )->get_styles( $element ),
                'model'    => 'atomic',
                'parent'   => $origin['parent_id'] ?? null,
                'index'    => $origin['index'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            return;
        }

        $bridge = new WPX_Elementor_Bridge();
        $result = $bridge->find_element_with_path( $post_id, $element_id );

        if ( null === $result ) {
            WP_CLI::error( "Element '{$element_id}' not found in post {$post_id}." );
            return;
        }

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Update an Elementor element's settings.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The element ID to modify.
     *
     * --settings=<json>
     * : JSON object of settings to merge.
     *
     * [--dry-run]
     * : Show diff without applying changes.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor set 241 f921a --settings='{"title":"New Title"}'
     *     wp wpx elementor set 241 f921a --settings='{"title":"New Title"}' --dry-run
     *
     * @subcommand elementor-set
     */
    public function elementor_set( $args, $assoc_args ) {
        $post_id    = (int) $args[0];
        $element_id = $args[1];
        $settings   = json_decode( $assoc_args['settings'] ?? '{}', true );
        $dry_run    = isset( $assoc_args['dry-run'] );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( 'Invalid JSON in --settings parameter.' );
            return;
        }

        $save = new WPX_Elementor_Save();
        $result = $save->update_element( $post_id, $element_id, $settings, $dry_run, isset( $assoc_args['force'] ) );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Update responsive styles for an Elementor element.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The element ID.
     *
     * [--desktop=<json>]
     * : Desktop style changes as JSON.
     *
     * [--tablet=<json>]
     * : Tablet style changes as JSON.
     *
     * [--mobile=<json>]
     * : Mobile style changes as JSON.
     *
     * [--dry-run]
     * : Show diff without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor style 241 f921a \
     *       --desktop='{"typography_font_size":"72px"}' \
     *       --tablet='{"typography_font_size":"52px"}' \
     *       --mobile='{"typography_font_size":"38px"}'
     *
     * @subcommand elementor-style
     */
    public function elementor_style( $args, $assoc_args ) {
        $post_id    = (int) $args[0];
        $element_id = $args[1];
        $dry_run    = isset( $assoc_args['dry-run'] );

        $desktop = json_decode( $assoc_args['desktop'] ?? '{}', true ) ?? [];
        $tablet  = json_decode( $assoc_args['tablet'] ?? '{}', true ) ?? [];
        $mobile  = json_decode( $assoc_args['mobile'] ?? '{}', true ) ?? [];

        $save   = new WPX_Elementor_Save();
        $result = $save->update_responsive_styles(
            $post_id,
            $element_id,
            $desktop,
            $tablet,
            $mobile,
            $dry_run,
            isset( $assoc_args['force'] )
        );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Add a widget to an Elementor page.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * --type=<widget_type>
     * : Widget type (heading, button, image, text-editor, etc.)
     *
     * [--parent=<element_id>]
     * : Parent container ID. Default: root.
     *
     * [--after=<element_id>]
     * : Insert after this element.
     *
     * [--position=<index>]
     * : Position index in parent.
     *
     * [--settings=<json>]
     * : Widget settings as JSON.
     *
     * [--dry-run]
     * : Show what would be added without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor add-widget 241 --type=button \
     *       --parent=a81f3 --settings='{"text":"Click Me","link":{"url":"/contact"}}'
     *
     * @subcommand elementor-add-widget
     */
    public function elementor_add_widget( $args, $assoc_args ) {
        $post_id     = (int) $args[0];
        $widget_type = $assoc_args['type'] ?? '';
        $parent_id   = $assoc_args['parent'] ?? null;
        $after_id    = $assoc_args['after'] ?? null;
        $position    = isset( $assoc_args['position'] ) ? (int) $assoc_args['position'] : null;
        $settings    = json_decode( $assoc_args['settings'] ?? '{}', true ) ?? [];
        $dry_run     = isset( $assoc_args['dry-run'] );

        if ( empty( $widget_type ) ) {
            WP_CLI::error( '--type parameter is required.' );
            return;
        }

        $save   = new WPX_Elementor_Save();
        $result = $save->add_widget(
            $post_id,
            $widget_type,
            $settings,
            $parent_id,
            $after_id,
            $position,
            $dry_run,
            isset( $assoc_args['force'] )
        );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Delete an Elementor element.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The element ID to delete.
     *
     * [--dry-run]
     * : Show what would be deleted.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor delete 241 91bc2
     *     wp wpx elementor delete 241 91bc2 --dry-run
     *
     * @subcommand elementor-delete
     */
    public function elementor_delete( $args, $assoc_args ) {
        $post_id    = (int) $args[0];
        $element_id = $args[1];
        $dry_run    = isset( $assoc_args['dry-run'] );

        $save   = new WPX_Elementor_Save();
        $result = $save->delete_element( $post_id, $element_id, $dry_run, isset( $assoc_args['force'] ) );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Move an Elementor element to a new position.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The element to move.
     *
     * [--into=<parent_id>]
     * : New parent container ID.
     *
     * [--position=<index>]
     * : Position in the new parent.
     *
     * [--dry-run]
     * : Show what would change.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor move 241 213aa --into=72cc1 --position=0
     *
     * @subcommand elementor-move
     */
    public function elementor_move( $args, $assoc_args ) {
        $post_id     = (int) $args[0];
        $element_id  = $args[1];
        $new_parent  = $assoc_args['into'] ?? null;
        $position    = isset( $assoc_args['position'] ) ? (int) $assoc_args['position'] : null;
        $dry_run     = isset( $assoc_args['dry-run'] );

        $save   = new WPX_Elementor_Save();
        $result = $save->move_element( $post_id, $element_id, $new_parent, $position, $dry_run, isset( $assoc_args['force'] ) );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Get global Elementor colors.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor globals-colors
     *
     * @subcommand elementor-globals-colors
     */
    public function elementor_globals_colors( $args, $assoc_args ) {
        $globals = new WPX_Elementor_Globals();
        $colors  = $globals->get_colors();

        WP_CLI::line( wp_json_encode( $colors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Get global Elementor typography.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor globals-typography
     *
     * @subcommand elementor-globals-typography
     */
    public function elementor_globals_typography( $args, $assoc_args ) {
        $globals    = new WPX_Elementor_Globals();
        $typography = $globals->get_typography();

        WP_CLI::line( wp_json_encode( $typography, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Set a global Elementor color.
     *
     * ## OPTIONS
     *
     * <color_id>
     * : The color ID (primary, secondary, text, accent, or custom ID).
     *
     * <hex_value>
     * : The hex color value (#RGB, #RRGGBB, or #RRGGBBAA).
     *
     * [--dry-run]
     * : Show diff without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor globals-set-color primary "#111111"
     *     wp wpx elementor globals-set-color accent "#FF5C35" --dry-run
     *
     * @subcommand elementor-globals-set-color
     */
    public function elementor_globals_set_color( $args, $assoc_args ) {
        $color_id  = $args[0];
        $hex_value = $args[1];
        $dry_run   = isset( $assoc_args['dry-run'] );

        $globals = new WPX_Elementor_Globals();
        $result  = $globals->set_color( $color_id, $hex_value, $dry_run );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Get Elementor site settings.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor site-settings
     *
     * @subcommand elementor-site-settings
     */
    public function elementor_site_settings( $args, $assoc_args ) {
        $globals  = new WPX_Elementor_Globals();
        $settings = $globals->get_site_settings();

        WP_CLI::line( wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * List operation history.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of operations to show. Default: 20.
     *
     * [--post-id=<id>]
     * : Filter by post ID.
     *
     * ## EXAMPLES
     *
     *     wp wpx history
     *     wp wpx history --limit=50
     *     wp wpx history --post-id=241
     *
     */
    public function history( $args, $assoc_args ) {
        $limit   = (int) ( $assoc_args['limit'] ?? 20 );
        $post_id = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : null;

        $operations = WPX_Operation_History::list_recent( $limit, $post_id );

        $result = array_map( function ( $op ) {
            return [
                'operation_id' => $op->operation_id,
                'command'      => $op->command,
                'post_id'      => $op->post_id,
                'element_id'   => $op->element_id,
                'status'       => $op->status,
                'created_at'   => $op->created_at,
                'undone_at'    => $op->undone_at,
            ];
        }, $operations );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Undo an operation.
     *
     * ## OPTIONS
     *
     * <operation_id>
     * : The operation ID to undo (e.g., op_0a1b2c3d4e5f).
     *
     * ## EXAMPLES
     *
     *     wp wpx undo op_0a1b2c3d4e5f
     *
     */
    public function undo( $args, $assoc_args ) {
        $operation_id = $args[0];

        $save   = new WPX_Elementor_Save();
        $result = $save->undo( $operation_id );

        WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Report capabilities of this WPX installation.
     *
     * Shows what features are available based on installed plugins.
     *
     * ## EXAMPLES
     *
     *     wp wpx capabilities
     *
     */
    public function capabilities( $args, $assoc_args ) {
        global $wp_version;

        $capabilities = [
            'wordpress' => $wp_version,
            'wpx'       => WPX_VERSION,
            'plugins'   => [],
            'abilities' => [
                'wordpress.site.read',
                'wordpress.content.read',
                'wordpress.content.write',
                'wordpress.plugins.read',
                'wordpress.options.read',
                'wordpress.options.write',
            ],
        ];

        // Elementor capabilities
        if ( WPX_Plugin::is_elementor_active() ) {
            $capabilities['plugins']['elementor'] = ELEMENTOR_VERSION;
            $capabilities['abilities'] = array_merge( $capabilities['abilities'], [
                'elementor.document.read',
                'elementor.document.write',
                'elementor.document.tree',
                'elementor.element.read',
                'elementor.element.write',
                'elementor.element.create',
                'elementor.element.delete',
                'elementor.element.move',
                'elementor.styles.read',
                'elementor.styles.write',
                'elementor.globals.read',
                'elementor.globals.write',
            ] );

            if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
                $capabilities['plugins']['elementor-pro'] = ELEMENTOR_PRO_VERSION;
                $capabilities['abilities'][] = 'elementor.pro.available';
            }
        }

        // WooCommerce capabilities (future)
        if ( class_exists( 'WooCommerce' ) ) {
            $capabilities['plugins']['woocommerce'] = WC_VERSION;
            $capabilities['abilities'][] = 'woocommerce.available';
        }

        // ACF capabilities (future)
        if ( class_exists( 'ACF' ) ) {
            $capabilities['plugins']['acf'] = defined( 'ACF_VERSION' ) ? ACF_VERSION : 'unknown';
            $capabilities['abilities'][] = 'acf.available';
        }

        WP_CLI::line( wp_json_encode( $capabilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Duplicate an Elementor element and everything under it.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * <element_id>
     * : The element to duplicate.
     *
     * [--position=<index>]
     * : Position among the siblings. Default: immediately after the original.
     *
     * [--dry-run]
     * : Show what would happen without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor-duplicate 241 72cc104
     *
     * @subcommand elementor-duplicate
     */
    public function elementor_duplicate( $args, $assoc_args ) {
        $save   = new WPX_Elementor_Save();
        $result = $save->duplicate_element(
            (int) $args[0],
            $args[1],
            isset( $assoc_args['position'] ) ? (int) $assoc_args['position'] : null,
            isset( $assoc_args['dry-run'] ),
            isset( $assoc_args['force'] )
        );

        $this->emit( $result );
    }

    /**
     * Create a container on an Elementor page.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * [--parent=<element_id>]
     * : Parent element ID. Default: root.
     *
     * [--direction=<direction>]
     * : Flex direction: row, row-reverse, column, column-reverse.
     *
     * [--gap=<value>]
     * : Gap between children, e.g. 32 or 32px.
     *
     * [--justify=<value>]
     * : Justify content value.
     *
     * [--align=<value>]
     * : Align items value.
     *
     * [--grid-columns=<n>]
     * : Make it a grid with this many columns.
     *
     * [--position=<index>]
     * : Position within the parent.
     *
     * [--after=<element_id>]
     * : Insert after this element instead.
     *
     * [--settings=<json>]
     * : Raw settings JSON, merged last; overrides the flags above.
     *
     * [--dry-run]
     * : Show what would happen without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor-container-create 241 --direction=row --gap=32
     *     wp wpx elementor-container-create 241 --grid-columns=3 --parent=a81f3a1
     *
     * @subcommand elementor-container-create
     */
    public function elementor_container_create( $args, $assoc_args ) {
        $save   = new WPX_Elementor_Save();
        $result = $save->create_container(
            (int) $args[0],
            $assoc_args['parent'] ?? null,
            $this->layout_from_args( $assoc_args ),
            $this->json_arg( $assoc_args, 'settings' ),
            isset( $assoc_args['position'] ) ? (int) $assoc_args['position'] : null,
            $assoc_args['after'] ?? null,
            isset( $assoc_args['dry-run'] ),
            isset( $assoc_args['force'] )
        );

        $this->emit( $result );
    }

    /**
     * Wrap a set of sibling elements in a new container.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * --elements=<ids>
     * : Comma-separated element IDs to wrap. They must all share a parent.
     *
     * [--direction=<direction>]
     * : Flex direction for the new container.
     *
     * [--gap=<value>]
     * : Gap between children.
     *
     * [--justify=<value>]
     * : Justify content value.
     *
     * [--align=<value>]
     * : Align items value.
     *
     * [--grid-columns=<n>]
     * : Make the new container a grid with this many columns.
     *
     * [--settings=<json>]
     * : Raw settings JSON, merged last.
     *
     * [--dry-run]
     * : Show what would happen without applying.
     *
     * [--force]
     * : Write even if someone has the page open in the Elementor editor.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor-wrap 241 --elements=f921a02,41ab203 --direction=row --gap=24
     *
     * @subcommand elementor-wrap
     */
    public function elementor_wrap( $args, $assoc_args ) {
        $ids = array_values( array_filter( array_map(
            'trim',
            explode( ',', (string) ( $assoc_args['elements'] ?? '' ) )
        ) ) );

        if ( empty( $ids ) ) {
            WP_CLI::error( '--elements is required (comma-separated element IDs).' );
            return;
        }

        $save   = new WPX_Elementor_Save();
        $result = $save->wrap_in_container(
            (int) $args[0],
            $ids,
            $this->layout_from_args( $assoc_args ),
            $this->json_arg( $assoc_args, 'settings' ),
            isset( $assoc_args['dry-run'] ),
            isset( $assoc_args['force'] )
        );

        $this->emit( $result );
    }

    /**
     * Report whether a page is locked in the Elementor editor.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID.
     *
     * ## EXAMPLES
     *
     *     wp wpx elementor-lock-status 241
     *
     * @subcommand elementor-lock-status
     */
    public function elementor_lock_status( $args, $assoc_args ) {
        $lock = new WPX_Lock();

        WP_CLI::line( wp_json_encode(
            $lock->describe( (int) $args[0] ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) );
    }

    /**
     * The document bridge that speaks this post's element model.
     *
     * @param int $post_id The post ID.
     * @return WPX_Elementor_Bridge|WPX_Atomic_Bridge
     */
    private function bridge_for( int $post_id ) {
        return 'atomic' === WPX_Elementor_Compat::document_model( $post_id )
            ? new WPX_Atomic_Bridge()
            : new WPX_Elementor_Bridge();
    }

    /**
     * Build container layout options from CLI flags.
     *
     * @param array $assoc_args The associative CLI arguments.
     * @return array Layout options for the bridge.
     */
    private function layout_from_args( array $assoc_args ): array {
        $layout = [];

        if ( isset( $assoc_args['grid-columns'] ) ) {
            $layout['type']    = 'grid';
            $layout['columns'] = (int) $assoc_args['grid-columns'];
        }

        foreach ( [ 'direction', 'gap', 'justify', 'align' ] as $key ) {
            if ( isset( $assoc_args[ $key ] ) ) {
                $layout[ $key ] = $assoc_args[ $key ];
            }
        }

        return $layout;
    }

    /**
     * Decode a JSON-valued CLI argument, erroring out on malformed input
     * rather than silently treating it as empty.
     *
     * @param array  $assoc_args The associative CLI arguments.
     * @param string $key        The argument name.
     * @return array The decoded array, or [] when the argument is absent.
     */
    private function json_arg( array $assoc_args, string $key ): array {
        if ( ! isset( $assoc_args[ $key ] ) || '' === $assoc_args[ $key ] ) {
            return [];
        }

        $decoded = json_decode( (string) $assoc_args[ $key ], true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( "--{$key} is not valid JSON: " . json_last_error_msg() );
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Emit a save-layer result as JSON, exiting non-zero on failure.
     *
     * @param array $result The result array.
     */
    private function emit( array $result ): void {
        WP_CLI::line( wp_json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) );

        if ( empty( $result['success'] ) ) {
            WP_CLI::error( $result['message'] ?? 'Operation failed.' );
        }
    }

    /**
     * Count total widgets in an elements tree.
     *
     * @param array $elements The elements array.
     * @return int Widget count.
     */
    private function count_widgets( array $elements ): int {
        $count = 0;
        foreach ( $elements as $el ) {
            if ( ( $el['elType'] ?? '' ) === 'widget' ) {
                $count++;
            }
            if ( ! empty( $el['elements'] ) ) {
                $count += $this->count_widgets( $el['elements'] );
            }
        }
        return $count;
    }
}

// Register WP-CLI commands
WP_CLI::add_command( 'wpx', 'WPX_CLI_Commands' );
