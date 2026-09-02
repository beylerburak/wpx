<?php
/**
 * WPX integration test fixtures.
 *
 * Rebuilds a deterministic pair of Elementor pages and prints their IDs as
 * shell-eval'able assignments. Run with:
 *
 *     wp eval-file tests/integration/fixtures.php --path=<wp-root>
 *
 * Page A is the page under test. Page B exists only so that tests can prove an
 * edit to page A does not invalidate an unrelated page's generated CSS.
 *
 * @package WPX
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}

/**
 * The page under test.
 *
 * Deliberately includes: a root container, two leaf widgets, a nested inner
 * container, and a button whose `link` setting is a nested object with sibling
 * keys (the shape that a shallow merge destroys).
 */
$page_a = [
    [
        'id'       => 'a81f3a1',
        'elType'   => 'container',
        'isInner'  => false,
        'settings' => [
            'container_type' => 'flex',
            'flex_direction' => 'column',
            'padding'        => [ 'unit' => 'px', 'top' => '80', 'right' => '20', 'bottom' => '80', 'left' => '20', 'isLinked' => false ],
        ],
        'elements' => [
            [
                'id'         => 'f921a02',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'isInner'    => false,
                'settings'   => [ 'title' => 'Build Better Products.', 'header_size' => 'h1', 'align' => 'left' ],
                'elements'   => [],
            ],
            [
                'id'         => '41ab203',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'isInner'    => false,
                'settings'   => [ 'editor' => '<p>We design and build digital products.</p>' ],
                'elements'   => [],
            ],
            [
                'id'       => '72cc104',
                'elType'   => 'container',
                'isInner'  => true,
                'settings' => [
                    'container_type' => 'flex',
                    'flex_direction' => 'row',
                    'gap'            => [ 'unit' => 'px', 'size' => 24, 'sizes' => [] ],
                ],
                'elements' => [
                    [
                        'id'         => '213aa05',
                        'elType'     => 'widget',
                        'widgetType' => 'button',
                        'isInner'    => false,
                        'settings'   => [
                            'text' => 'Start a Project',
                            'link' => [ 'url' => '/contact', 'is_external' => '', 'nofollow' => '' ],
                        ],
                        'elements'   => [],
                    ],
                    [
                        'id'         => '91bc206',
                        'elType'     => 'widget',
                        'widgetType' => 'image',
                        'isInner'    => false,
                        'settings'   => [
                            'image'      => [ 'url' => 'https://placehold.co/600x400.png', 'id' => '' ],
                            'image_size' => 'large',
                        ],
                        'elements'   => [],
                    ],
                ],
            ],
        ],
    ],
];

/** The bystander page. Its CSS must survive edits to page A. */
$page_b = [
    [
        'id'       => 'bbb1111',
        'elType'   => 'container',
        'isInner'  => false,
        'settings' => [],
        'elements' => [
            [
                'id'         => 'ccc2222',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'isInner'    => false,
                'settings'   => [
                    'title'                  => 'Bystander page',
                    'typography_typography'  => 'custom',
                    'typography_font_size'   => [ 'unit' => 'px', 'size' => 40, 'sizes' => [] ],
                ],
                'elements'   => [],
            ],
        ],
    ],
];

/**
 * Replace a page and its Elementor data, returning the new post ID.
 *
 * @param string $slug  Page slug.
 * @param string $title Page title.
 * @param array  $data  Elementor elements data.
 * @return int The post ID.
 */
function wpx_fixture_page( string $slug, string $title, array $data ): int {
    $existing = get_page_by_path( $slug, OBJECT, 'page' );
    if ( $existing ) {
        wp_delete_post( $existing->ID, true );
    }

    $post_id = wp_insert_post( [
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_content' => '',
    ] );

    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
    update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '0' );
    update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
        \Elementor\Core\Files\CSS\Post::create( $post_id )->update();
    }

    return $post_id;
}

$id_a = wpx_fixture_page( 'wpx-test-hero', 'WPX Test Hero', $page_a );
$id_b = wpx_fixture_page( 'wpx-other', 'WPX Other', $page_b );

/**
 * A genuine Elementor V4 (atomic) page.
 *
 * Props are built through the bridge rather than hand-written, because atomic
 * prop values are type-enveloped and the envelope shape is decided by each
 * prop's live schema — hard-coding one here would test our guess, not Elementor.
 */
$id_v4 = 0;
if ( class_exists( 'WPX_Atomic_Bridge' ) ) {
    $bridge = new WPX_Atomic_Bridge();

    $page_v4 = [
        [
            'id'              => 'v4root1',
            'elType'          => 'e-flexbox',
            'version'         => '0.0',
            'settings'        => [],
            'styles'          => [],
            'editor_settings' => [],
            'elements'        => [
                [
                    'id'              => 'v4head1',
                    'elType'          => 'widget',
                    'widgetType'      => 'e-heading',
                    'version'         => '0.0',
                    'settings'        => [ 'title' => $bridge->wrap_prop( 'widget', 'e-heading', 'title', 'Atomic Hero' ) ],
                    'styles'          => [],
                    'editor_settings' => [],
                    'elements'        => [],
                ],
                [
                    'id'              => 'v4btn01',
                    'elType'          => 'widget',
                    'widgetType'      => 'e-button',
                    'version'         => '0.0',
                    'settings'        => [ 'text' => $bridge->wrap_prop( 'widget', 'e-button', 'text', 'Get Started' ) ],
                    'styles'          => [],
                    'editor_settings' => [],
                    'elements'        => [],
                ],
            ],
        ],
    ];

    $id_v4 = wpx_fixture_page( 'wpx-v4-page', 'WPX V4 Page', $page_v4 );
}

$kit_id = get_option( 'elementor_active_kit' );

echo "PAGE_A={$id_a}\n";
echo "PAGE_B={$id_b}\n";
echo "PAGE_V4={$id_v4}\n";
echo 'V4_URL=' . ( $id_v4 ? get_permalink( $id_v4 ) : '' ) . "\n";
echo 'KIT=' . ( $kit_id ? (int) $kit_id : 0 ) . "\n";
