<?php
/**
 * WPX Elementor Globals
 *
 * Manages Elementor global styles: colors, typography, and site settings.
 *
 * Reads go through the kit *document*, not raw post meta. The kit's
 * `_elementor_page_settings` meta is an empty string until the user first
 * customises global styles, while the kit document merges saved meta over the
 * control defaults — so a raw meta read returns nothing on most sites even
 * though the site plainly has four system colours. See WPX_Elementor_Compat.
 *
 * Writes must therefore materialise the full merged settings before applying a
 * partial change: writing only the keys we touched over an empty meta value
 * would silently drop every setting the user never edited.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Elementor_Globals {

    /**
     * Get the active kit post ID.
     *
     * @return int|null The kit post ID, or null if not found.
     */
    public function get_kit_id(): ?int {
        return WPX_Elementor_Compat::kit_id();
    }

    /**
     * Get all kit settings.
     *
     * @return array The kit settings array.
     */
    public function get_kit_settings(): array {
        return WPX_Elementor_Compat::kit_settings();
    }

    /**
     * Get all global colors (system + custom).
     *
     * @return array Array of color definitions.
     */
    public function get_colors(): array {
        $settings = $this->get_kit_settings();

        $colors = [];

        // System colors (primary, secondary, text, accent)
        if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
            foreach ( $settings['system_colors'] as $color ) {
                $colors[] = [
                    'id'     => $color['_id'] ?? '',
                    'title'  => $color['title'] ?? '',
                    'color'  => $color['color'] ?? '',
                    'type'   => 'system',
                ];
            }
        }

        // Custom colors
        if ( ! empty( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ) {
            foreach ( $settings['custom_colors'] as $color ) {
                $colors[] = [
                    'id'     => $color['_id'] ?? '',
                    'title'  => $color['title'] ?? '',
                    'color'  => $color['color'] ?? '',
                    'type'   => 'custom',
                ];
            }
        }

        return $colors;
    }

    /**
     * Get all global typography definitions.
     *
     * @return array Array of typography definitions.
     */
    public function get_typography(): array {
        $settings = $this->get_kit_settings();

        $typography = [];

        // System typography
        if ( ! empty( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ) {
            foreach ( $settings['system_typography'] as $typo ) {
                $typography[] = $this->format_typography( $typo, 'system' );
            }
        }

        // Custom typography
        if ( ! empty( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] ) ) {
            foreach ( $settings['custom_typography'] as $typo ) {
                $typography[] = $this->format_typography( $typo, 'custom' );
            }
        }

        return $typography;
    }

    /**
     * Set a global color value.
     *
     * @param string $color_id  The color ID (e.g., 'primary', 'accent').
     * @param string $hex_value The hex color value (e.g., '#FF5C35').
     * @param bool   $dry_run   If true, show diff without applying.
     * @return array Result array.
     */
    public function set_color( string $color_id, string $hex_value, bool $dry_run = false ): array {
        $supported = WPX_Elementor_Compat::assert_supported();
        if ( ! $supported['ok'] ) {
            return [
                'success' => false,
                'message' => $supported['reason'],
            ];
        }

        // Validate hex color
        if ( ! preg_match( '/^#[0-9A-Fa-f]{3,8}$/', $hex_value ) ) {
            return [
                'success' => false,
                'message' => "Invalid color value: '{$hex_value}'. Expected hex format (#RGB, #RRGGBB, or #RRGGBBAA).",
            ];
        }

        $kit_id = $this->get_kit_id();
        if ( ! $kit_id ) {
            return [
                'success' => false,
                'message' => 'Elementor active kit not found.',
            ];
        }

        $settings = $this->get_kit_settings();

        // Snapshot the fully merged settings *before* mutation. This is what
        // undo restores, and it is deliberately the whole kit rather than the
        // single colour: a partial restore over an unmaterialised meta value
        // would drop every setting the user never edited.
        $snapshot = $settings;

        $found    = false;
        $old_value = null;

        // Search in system colors
        if ( ! empty( $settings['system_colors'] ) ) {
            foreach ( $settings['system_colors'] as &$color ) {
                if ( ( $color['_id'] ?? '' ) === $color_id ) {
                    $old_value     = $color['color'] ?? '';
                    $color['color'] = $hex_value;
                    $found          = true;
                    break;
                }
            }
            unset( $color );
        }

        // Search in custom colors if not found
        if ( ! $found && ! empty( $settings['custom_colors'] ) ) {
            foreach ( $settings['custom_colors'] as &$color ) {
                if ( ( $color['_id'] ?? '' ) === $color_id ) {
                    $old_value     = $color['color'] ?? '';
                    $color['color'] = $hex_value;
                    $found          = true;
                    break;
                }
            }
            unset( $color );
        }

        if ( ! $found ) {
            return [
                'success' => false,
                'message' => "Color '{$color_id}' not found in global colors.",
            ];
        }

        $diff = [
            [
                'key' => "color.{$color_id}",
                'old' => $old_value,
                'new' => $hex_value,
            ],
        ];

        if ( $dry_run ) {
            return [
                'success' => true,
                'dry_run' => true,
                'diff'    => $diff,
                'message' => 'No changes applied (dry run).',
            ];
        }

        // Record the intent, with the pre-write snapshot, BEFORE touching the
        // site. If anything below fatals, the row is still there in 'pending'
        // with the snapshot needed to reconcile it.
        $operation_id = WPX_Operation_History::begin( [
            'command'       => "elementor globals set-color {$color_id} {$hex_value}",
            'target_type'   => 'kit',
            'post_id'       => $kit_id,
            'page_snapshot' => $snapshot,
            'before_state'  => [ 'color' => $old_value ],
        ] );

        if ( null === $operation_id ) {
            return [
                'success' => false,
                'message' => 'Refusing to write: the operation could not be recorded (' . ( WPX_Operation_History::last_error() ?? 'unknown error' ) . '). Undo would not be possible.',
            ];
        }

        try {
            update_post_meta( $kit_id, '_elementor_page_settings', $settings );
            $this->regenerate_kit_css( $kit_id );
        } catch ( \Throwable $e ) {
            WPX_Operation_History::fail( $operation_id, $e->getMessage() );
            return [
                'success'      => false,
                'operation_id' => $operation_id,
                'message'      => 'Write failed: ' . $e->getMessage() . " (snapshot kept under {$operation_id}).",
            ];
        }

        WPX_Operation_History::complete( $operation_id, [ 'color' => $hex_value ] );

        return [
            'success'      => true,
            'dry_run'      => false,
            'diff'         => $diff,
            'operation_id' => $operation_id,
            'message'      => "Global color '{$color_id}' updated: {$old_value} → {$hex_value}.",
        ];
    }

    /**
     * Get site settings from the Elementor kit.
     *
     * @return array Relevant site settings.
     */
    public function get_site_settings(): array {
        $settings = $this->get_kit_settings();

        // Extract site-relevant settings
        $site_settings = [];
        $relevant_keys = [
            'container_width',
            'viewport_md',
            'viewport_lg',
            'body_color',
            'body_background_color',
            'link_normal_color',
            'link_hover_color',
            'page_title_selector',
            'stretched_section_container',
            'lightbox_enable_counter',
            'lightbox_enable_fullscreen',
            'lightbox_enable_zoom',
            'lightbox_enable_share',
        ];

        foreach ( $relevant_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $site_settings[ $key ] = $settings[ $key ];
            }
        }

        return $site_settings;
    }

    /**
     * Format a typography entry for CLI output.
     *
     * @param array  $typo The raw typography data.
     * @param string $type 'system' or 'custom'.
     * @return array Formatted typography data.
     */
    private function format_typography( array $typo, string $type ): array {
        $formatted = [
            'id'          => $typo['_id'] ?? '',
            'title'       => $typo['title'] ?? '',
            'type'        => $type,
            'font_family' => $typo['typography_font_family'] ?? '',
            'font_weight' => $typo['typography_font_weight'] ?? '',
        ];

        // Font size (may be a complex value)
        if ( ! empty( $typo['typography_font_size'] ) ) {
            $size = $typo['typography_font_size'];
            if ( is_array( $size ) ) {
                $formatted['font_size'] = ( $size['size'] ?? '' ) . ( $size['unit'] ?? 'px' );
            } else {
                $formatted['font_size'] = $size;
            }
        }

        // Line height
        if ( ! empty( $typo['typography_line_height'] ) ) {
            $lh = $typo['typography_line_height'];
            if ( is_array( $lh ) ) {
                $formatted['line_height'] = ( $lh['size'] ?? '' ) . ( $lh['unit'] ?? '' );
            } else {
                $formatted['line_height'] = $lh;
            }
        }

        // Letter spacing
        if ( ! empty( $typo['typography_letter_spacing'] ) ) {
            $ls = $typo['typography_letter_spacing'];
            if ( is_array( $ls ) ) {
                $formatted['letter_spacing'] = ( $ls['size'] ?? '' ) . ( $ls['unit'] ?? 'px' );
            } else {
                $formatted['letter_spacing'] = $ls;
            }
        }

        return $formatted;
    }

    /**
     * Regenerate the global/kit CSS file.
     *
     * @param int $kit_id The kit post ID.
     */
    private function regenerate_kit_css( int $kit_id ): void {
        WPX_Elementor_Compat::regenerate_kit_css( $kit_id );
    }
}
