<?php
/**
 * WPX Elementor Compatibility Shim
 *
 * wpx (WPX_Elementor_Bridge / WPX_Elementor_Save) was written against
 * Elementor 3.x's classic document model. Elementor 4.x removed APIs
 * wpx depended on and introduced a second, structurally different
 * document model ("atomic widgets") that can coexist with classic
 * pages on the same site. This class is the single place that knows
 * about that gap: version gating, document-model detection, and safe
 * CSS regeneration for both a single post and the active kit.
 *
 * Every public method is safe to call whether or not Elementor is
 * active, and regardless of which major version is active - every
 * touch of an Elementor class is guarded with class_exists() so this
 * file always loads cleanly even without Elementor present.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Elementor_Compat {

    /**
     * Element type identifiers ("elType") belonging to Elementor 4's
     * atomic-widgets document model.
     *
     * Enumerated by reading every get_element_type() (and, for the Pro
     * upsell "promotion" placeholders, get_type()) return value under
     * elementor/modules/atomic-widgets/elements/ in Elementor 4.2.4:
     *
     * - div-block/div-block.php                          -> e-div-block
     * - flexbox/flexbox.php                               -> e-flexbox
     * - grid/grid.php                                      -> e-grid
     * - atomic-heading/atomic-heading.php                  -> e-heading
     * - atomic-paragraph/atomic-paragraph.php              -> e-paragraph
     * - atomic-image/atomic-image.php                      -> e-image
     * - atomic-button/atomic-button.php                    -> e-button
     * - atomic-divider/atomic-divider.php                  -> e-divider
     * - atomic-svg/atomic-svg.php                          -> e-svg
     * - atomic-youtube/atomic-youtube.php                  -> e-youtube
     * - atomic-self-hosted-video/atomic-self-hosted-video.php
     *                                                       -> e-self-hosted-video
     * - atomic-tabs/atomic-tabs/atomic-tabs.php             -> e-tabs
     * - atomic-tabs/atomic-tab/atomic-tab.php               -> e-tab
     * - atomic-tabs/atomic-tab-content/atomic-tab-content.php
     *                                                       -> e-tab-content
     * - atomic-tabs/atomic-tabs-menu/atomic-tabs-menu.php   -> e-tabs-menu
     * - atomic-tabs/atomic-tabs-content-area/atomic-tabs-content-area.php
     *                                                       -> e-tabs-content-area
     * - atomic-form/atomic-form.php                         -> e-form
     * - atomic-form/form-success-message/form-success-message.php
     *                                                       -> e-form-success-message
     * - atomic-form/form-error-message/form-error-message.php
     *                                                       -> e-form-error-message
     * - atomic-collection-loop/collection-loop-promotion.php
     *                                                       -> e-collection-loop
     *
     * Promotion/placeholder elements are included because they still
     * occupy a real elType slot in `_elementor_data` on sites without
     * Elementor Pro.
     *
     * @var string[]
     */
    private const ATOMIC_ELEMENT_TYPES = [
        'e-div-block',
        'e-flexbox',
        'e-grid',
        'e-heading',
        'e-paragraph',
        'e-image',
        'e-button',
        'e-divider',
        'e-svg',
        'e-youtube',
        'e-self-hosted-video',
        'e-tabs',
        'e-tab',
        'e-tab-content',
        'e-tabs-menu',
        'e-tabs-content-area',
        'e-form',
        'e-form-success-message',
        'e-form-error-message',
        'e-collection-loop',
    ];

    /**
     * Element type identifiers belonging to Elementor's classic
     * (pre-atomic) document model.
     *
     * @var string[]
     */
    private const CLASSIC_ELEMENT_TYPES = [
        'section',
        'column',
        'container',
        'widget',
    ];

    /**
     * Get the active Elementor version string.
     *
     * @return string|null The version (e.g. "4.2.4"), or null if
     *                      Elementor is not loaded.
     */
    public static function version(): ?string {
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return null;
        }

        $version = ELEMENTOR_VERSION;

        return ( is_string( $version ) && '' !== $version ) ? $version : null;
    }

    /**
     * Get the active Elementor major version number.
     *
     * @return int The major version (e.g. 4), or 0 if Elementor is not
     *              loaded or the version string could not be parsed.
     */
    public static function major(): int {
        $version = self::version();

        if ( null === $version ) {
            return 0;
        }

        $parts = explode( '.', $version );

        return (int) ( $parts[0] ?? 0 );
    }

    /**
     * Gate wpx write paths on a known-good Elementor version range.
     *
     * Supported range: 3.0.0 <= version < 5.0.0.
     *
     * wpx's element tree read/write code was authored against, and has
     * only ever been exercised against, Elementor's *classic* document
     * model - the model shared by 3.x and (still, for now) 4.x. This
     * class exists to patch the specific 4.x breakages found by
     * testing against 4.2.4 (removal of Core\Files\CSS\Global_CSS,
     * empty-string kit-meta reads, the atomic-widgets document model
     * coexisting with classic pages). Nothing below 3.0 has been
     * audited and is refused outright. Nothing at or above 5.0 is
     * refused too - not because 5.x is known to be broken, but because
     * a future major version can introduce breaking changes the same
     * way 4.0 did, and must be audited the same way before wpx trusts
     * it. This is a version *ceiling*, not a claim that every 4.x
     * install is safe to write to: callers must still call
     * document_model() before any per-post write, because even inside
     * the supported range a given page can be built with the atomic
     * model, which wpx's classic-only writers do not understand and
     * would corrupt.
     *
     * @return array{ok: bool, version: string|null, reason: string}
     */
    public static function assert_supported(): array {
        $version = self::version();

        if ( null === $version ) {
            return [
                'ok'      => false,
                'version' => null,
                'reason'  => 'Elementor is not active.',
            ];
        }

        if ( version_compare( $version, '3.0.0', '<' ) ) {
            return [
                'ok'      => false,
                'version' => $version,
                'reason'  => "Elementor {$version} predates wpx's supported floor (3.0.0) and has not been audited.",
            ];
        }

        if ( version_compare( $version, '5.0.0', '>=' ) ) {
            return [
                'ok'      => false,
                'version' => $version,
                'reason'  => "Elementor {$version} is at or beyond wpx's supported ceiling (5.0.0) and has not been audited.",
            ];
        }

        return [
            'ok'      => true,
            'version' => $version,
            'reason'  => "Elementor {$version} is within the supported range (>=3.0.0, <5.0.0).",
        ];
    }

    /**
     * Classify a post's Elementor document model.
     *
     * Walks the decoded `_elementor_data` tree and looks, per element,
     * for classic signals (elType in {section,column,container,widget}
     * with a plain `settings` array) versus atomic signals (elType in
     * ATOMIC_ELEMENT_TYPES, or the presence of a `props` array, a
     * `styles` array, a `version` key, or a `{"$$type":...,"value":...}`
     * envelope inside settings/props). A page can legitimately contain
     * both - e_opt_in_v4_page defaults to active, so classic and atomic
     * pages coexist on the same site, and a single page can even mix
     * element types during migration - hence 'mixed'.
     *
     * This is the gate wpx must check before any structural write:
     * WPX_Elementor_Bridge/WPX_Elementor_Save only understand the
     * classic shape, and blindly writing `settings` onto an atomic
     * element (which expects `props`/`styles`) would corrupt the page.
     *
     * @param int $post_id The post ID.
     * @return string One of 'classic', 'atomic', 'mixed', 'empty',
     *                 'unknown'.
     */
    public static function document_model( int $post_id ): string {
        $raw = get_post_meta( $post_id, '_elementor_data', true );

        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return 'empty';
        }

        $elements = json_decode( $raw, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $elements ) ) {
            return 'unknown';
        }

        if ( [] === $elements ) {
            return 'empty';
        }

        $signals = [
            'classic' => false,
            'atomic'  => false,
        ];

        self::classify_elements( $elements, $signals );

        if ( $signals['classic'] && $signals['atomic'] ) {
            return 'mixed';
        }

        if ( $signals['classic'] ) {
            return 'classic';
        }

        if ( $signals['atomic'] ) {
            return 'atomic';
        }

        return 'unknown';
    }

    /**
     * Recursively classify elements, flipping $signals['classic'] and/or
     * $signals['atomic'] as evidence is found.
     *
     * @param array $elements The elements array to walk.
     * @param array &$signals Accumulator with 'classic' and 'atomic' keys.
     */
    private static function classify_elements( array $elements, array &$signals ): void {
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }

            $el_type = $element['elType'] ?? null;

            $is_atomic = ( is_string( $el_type ) && in_array( $el_type, self::ATOMIC_ELEMENT_TYPES, true ) )
                || ( isset( $element['props'] ) && is_array( $element['props'] ) )
                || ( isset( $element['styles'] ) && is_array( $element['styles'] ) )
                || array_key_exists( 'version', $element )
                || self::has_dollar_type_envelope( is_array( $element['settings'] ?? null ) ? $element['settings'] : [] )
                || self::has_dollar_type_envelope( is_array( $element['props'] ?? null ) ? $element['props'] : [] );

            if ( $is_atomic ) {
                $signals['atomic'] = true;
            } elseif ( is_string( $el_type ) && in_array( $el_type, self::CLASSIC_ELEMENT_TYPES, true ) ) {
                $signals['classic'] = true;
            }

            $children = $element['elements'] ?? [];
            if ( is_array( $children ) && ! empty( $children ) ) {
                self::classify_elements( $children, $signals );
            }
        }
    }

    /**
     * Check whether any top-level value in an array is an Elementor 4
     * atomic prop-type envelope: `[ '$$type' => ..., 'value' => ... ]`.
     *
     * @param array $values A settings or props array.
     * @return bool True if an envelope was found.
     */
    private static function has_dollar_type_envelope( array $values ): bool {
        foreach ( $values as $value ) {
            if ( is_array( $value ) && array_key_exists( '$$type', $value ) && array_key_exists( 'value', $value ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the live `\Elementor\Plugin` instance, if Elementor is
     * loaded and has bootstrapped.
     *
     * @return object|null The plugin singleton, or null.
     */
    private static function plugin_instance(): ?object {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return null;
        }

        return \Elementor\Plugin::$instance ?? null;
    }

    /**
     * Regenerate the CSS file for a single post, and only that post.
     *
     * CRITICAL: this method must never call
     * `Plugin::$instance->files_manager->clear_cache()`
     * (core/files/manager.php). That method globs *every* file in
     * wp-content/uploads/elementor/css/ and unlinks them, then wipes
     * the Post CSS meta (`delete_post_meta_by_key( Post::META_KEY )`)
     * and the element-cache meta for *every post in the database*, not
     * just the one being edited. This was empirically confirmed on the
     * test site: editing page 7 through the old (uncompensated) call
     * path deleted `uploads/elementor/css/post-8.css`, an unrelated
     * page, as a side effect. This method instead touches only
     * $post_id's own CSS file and per-post caches, via the same
     * primitives Elementor 4 itself uses to regenerate a single post's
     * CSS (see Core\Files\CSS\Post::update(), and
     * Core\Kits\Documents\Kit::add_repeater_row() for the "drop the
     * cached Document, recreate the CSS file object" pattern mirrored
     * below).
     *
     * Also clears, for this post only:
     * - `_elementor_css` (Core\Files\CSS\Post::META_KEY) - the per-post
     *   parsed-CSS bookkeeping meta (status/hash/timestamp). update()
     *   below rewrites it regardless, but it is cleared first so a
     *   stale/empty status can never short-circuit the rebuild.
     * - `_elementor_element_cache` (Core\Base\Document::CACHE_META_KEY)
     *   - the per-post rendered-element cache used for dynamic-tag/
     *   render caching. It carries no CSS itself, but left stale it can
     *   serve old markup alongside freshly regenerated CSS, so it is
     *   invalidated for this post only (never via
     *   `delete_post_meta_by_key()`, which would hit every post).
     *
     * @param int $post_id The post ID.
     */
    public static function regenerate_post_css( int $post_id ): void {
        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return;
        }

        $plugin = self::plugin_instance();

        // Drop this post's cached Document instance so the CSS file
        // object below rebuilds from the current `_elementor_data`
        // rather than a stale in-memory copy.
        if ( null !== $plugin && isset( $plugin->documents ) ) {
            $plugin->documents->get( $post_id, false );
        }

        delete_post_meta( $post_id, \Elementor\Core\Files\CSS\Post::META_KEY );

        if ( class_exists( '\Elementor\Core\Base\Document' ) ) {
            delete_post_meta( $post_id, \Elementor\Core\Base\Document::CACHE_META_KEY );
        }

        $css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
        $css_file->update();

        // Elementor 4 renders V4 (atomic) element styles into their own files
        // (local-<id>-frontend-<breakpoint>.css) behind a cache-validity node
        // that the classic Post CSS file knows nothing about. Without this the
        // data updates and the page keeps serving the previous stylesheet —
        // the exact silent-staleness failure this tool exists to avoid.
        // Path shape mirrors Atomic_Widget_Styles::invalidate_cache().
        do_action( 'elementor/atomic-widgets/styles/clear', [ 'local', $post_id ] );
    }

    /**
     * Regenerate CSS for the active kit (global colors, typography,
     * site-identity styles, etc).
     *
     * Elementor 4.2.4 has NO `Core\Files\CSS\Global_CSS` class - it was
     * removed. A kit's own CSS is generated exactly the way a page's
     * is: the kit post is itself an Elementor document
     * (`Core\Kits\Documents\Kit extends DocumentTypes\PageBase`) and
     * its stylesheet is a `Core\Files\CSS\Post` file keyed on the kit's
     * post ID (see the `Post as Post_CSS` import and its use throughout
     * core/kits/documents/kit.php). So the first thing this method does
     * is exactly what regenerate_post_css() does, for the kit's own
     * post ID.
     *
     * ### Why this method is ALSO, unavoidably, site-wide
     *
     * Global colors/typography are emitted as CSS custom properties
     * (e.g. `--e-global-color-primary`) that are *referenced from every
     * other post's already-generated CSS file*, not defined there. If
     * a global value changes or is removed, every other post's CSS
     * still references the old/missing variable - regenerating only
     * the kit's own file does nothing to fix that. This is not a
     * guess; it is Elementor's own stated reason for a full flush.
     * `Core\Kits\Documents\Kit::save()` ends with this comment and call
     * (core/kits/documents/kit.php):
     *
     *   // When deleting a global color or typo, the css variable still
     *   // exists in the frontend but without any value and it makes
     *   // the element to be un styled even if there is a default style
     *   // for the base element, for that reason this method removes
     *   // css files of the entire site.
     *   Plugin::instance()->files_manager->clear_cache();
     *
     * i.e. Elementor's own kit-save path pays exactly the same
     * site-wide cost this method does - there is no narrower, correct
     * primitive in 4.2.4 for "regenerate just the pages that reference
     * this global"; Elementor does not keep that reverse index. Call
     * this method sparingly (every post's CSS is rebuilt lazily on its
     * next request afterward) and only after an actual kit/global
     * write.
     *
     * @param int $kit_id The kit post ID.
     */
    public static function regenerate_kit_css( int $kit_id ): void {
        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return;
        }

        self::regenerate_post_css( $kit_id );

        $plugin = self::plugin_instance();

        if ( null !== $plugin && isset( $plugin->files_manager ) ) {
            $plugin->files_manager->clear_cache();
        }
    }

    /**
     * Get the active kit's post ID.
     *
     * @return int|null The kit post ID, or null if Elementor's kits
     *                   manager is unavailable or no kit is active.
     */
    public static function kit_id(): ?int {
        $plugin = self::plugin_instance();

        if ( null === $plugin || ! isset( $plugin->kits_manager ) ) {
            return null;
        }

        $id = $plugin->kits_manager->get_active_id();

        return ( is_numeric( $id ) && (int) $id > 0 ) ? (int) $id : null;
    }

    /**
     * Get the active kit's settings, with control defaults materialised.
     *
     * Reads through
     * `Plugin::$instance->kits_manager->get_active_kit()->get_settings()`
     * rather than the `_elementor_page_settings` post meta directly,
     * because that meta only holds whatever the user has explicitly
     * saved. On a kit nobody has customised, the meta can be an empty
     * string - confirmed on the test site's default state - even
     * though Elementor has real, non-empty defaults for every control
     * it renders (e.g. 4 default `system_colors`). Only
     * `Controls_Stack::get_settings()`, reached via the kit Document,
     * merges saved values over those control defaults; a raw meta read
     * would report zero colors on a stock, never-customised site.
     *
     * Falls back to a raw `_elementor_page_settings` meta read only if
     * the kits manager itself is unavailable (e.g. Elementor deactivated
     * mid-request) - in that degraded case defaults cannot be
     * materialised at all, so the result may be incomplete or empty.
     *
     * @return array The materialised kit settings, or [] if unavailable.
     */
    public static function kit_settings(): array {
        $plugin = self::plugin_instance();

        if ( null !== $plugin && isset( $plugin->kits_manager ) ) {
            $kit = $plugin->kits_manager->get_active_kit();

            if ( is_object( $kit ) && method_exists( $kit, 'get_settings' ) ) {
                $settings = $kit->get_settings();

                if ( is_array( $settings ) ) {
                    return $settings;
                }
            }
        }

        $kit_id = self::kit_id();

        if ( null === $kit_id ) {
            return [];
        }

        $meta = get_post_meta( $kit_id, '_elementor_page_settings', true );

        return is_array( $meta ) ? $meta : [];
    }

    /**
     * Whether the kit's `_elementor_page_settings` meta already holds a
     * materialised settings array, as opposed to being empty/unsaved.
     *
     * A partial update (`update_post_meta()` with a shallow-merged
     * array) is only safe once this is true. The control defaults that
     * `kit_settings()` returns (e.g. the 4 default system colors) live
     * in Elementor's control definitions, not in this meta row, until
     * something actually saves them into it - merging one changed key
     * onto an empty/unset row and writing that back would wipe every
     * default the kit was rendering from a moment ago. Callers must
     * call kit_settings() and write the full, materialised result
     * (merged with their change) instead of a bare partial update when
     * this returns false.
     *
     * @return bool True if the active kit's `_elementor_page_settings`
     *              is already an array.
     */
    public static function kit_settings_are_materialised(): bool {
        $kit_id = self::kit_id();

        if ( null === $kit_id ) {
            return false;
        }

        return is_array( get_post_meta( $kit_id, '_elementor_page_settings', true ) );
    }
}
