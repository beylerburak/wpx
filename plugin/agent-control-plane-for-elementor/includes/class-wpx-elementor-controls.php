<?php
/**
 * WPX Elementor Controls
 *
 * Elementor "group controls" (Typography, Border, Background, Box Shadow, ...)
 * only render CSS for their child controls once a gating "toggle" control is
 * set to an activating value. The Elementor editor UI sets this toggle
 * automatically the first time a user opens a popover / picks a type, but
 * when settings are written directly (e.g. from a CLI), the toggle is never
 * set and the child controls silently produce zero CSS.
 *
 * This class provides a declarative map of every group control that gates
 * its children this way, and a helper that augments a set of proposed
 * setting changes with whatever toggle keys are required to make those
 * changes actually render.
 *
 * Every rule below was verified by reading the Elementor 4.2.4 source at
 * `wp-content/plugins/elementor/includes/controls/groups/*.php` (and the
 * shared base class `groups/base.php`), not from memory. See the
 * `// verified against ...` comment on each map entry.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Elementor_Controls {

    /**
     * All breakpoint keys Elementor knows about, in the constant order
     * declared by `Elementor\Core\Breakpoints\Manager`
     * (BREAKPOINT_KEY_MOBILE / MOBILE_EXTRA / TABLET / TABLET_EXTRA / LAPTOP
     * / DESKTOP / WIDESCREEN). `desktop` is not a real Breakpoint object
     * (there is no "max-width" rule for it) but is always implicitly present
     * — `Manager::get_active_devices_list()` inserts it explicitly.
     *
     * verified against elementor/core/breakpoints/manager.php (class consts,
     * lines 17-23, and get_default_config()).
     *
     * @var string[]
     */
    private const ALL_BREAKPOINTS = [
        'mobile',
        'mobile_extra',
        'tablet',
        'tablet_extra',
        'laptop',
        'desktop',
        'widescreen',
    ];

    /**
     * Non-desktop breakpoint suffixes, ordered longest-first so that
     * `_mobile_extra` is stripped before the shorter `_mobile` would
     * otherwise match part of it, etc. Used to recognize/strip a responsive
     * suffix off a settings key before toggle matching.
     *
     * verified against elementor/includes/base/controls-stack.php,
     * `add_responsive_control()` (~line 970): the per-device control id is
     * built as `$id . ( 'desktop' === $device_name ? '' : '_' . $device_name )`
     * — i.e. desktop is unsuffixed and every other device is a plain
     * `_<device>` suffix on the base control id.
     *
     * @var string[]
     */
    private const RESPONSIVE_SUFFIXES_BY_LENGTH = [
        'mobile_extra',
        'tablet_extra',
        'widescreen',
        'desktop',
        'mobile',
        'tablet',
        'laptop',
    ];

    /**
     * Background sub-type leaf suffixes, used only to decide *which value*
     * the `background` toggle needs (see apply_group_toggles()). Background
     * is excluded from the generic GROUPS map below because, unlike every
     * other group control, its toggle is a single control
     * (Controls_Manager::CHOOSE, `background`) whose activating value is not
     * constant — it depends on which kind of background field is being set.
     *
     * verified against elementor/includes/controls/groups/background.php,
     * init_fields() (lines 153-762): every child field's `condition` array
     * keys off `background` with one of 'classic' | 'gradient' | 'video' |
     * 'slideshow'.
     *
     * @var array<string,string[]>
     */
    private const BACKGROUND_VARIANT_SUFFIXES = [
        'gradient' => [
            'color_stop',
            'color_b',
            'color_b_stop',
            'gradient_type',
            'gradient_angle',
            'gradient_position',
        ],
        'video' => [
            'video_link',
            'video_start',
            'video_end',
            'play_once',
            'play_on_mobile',
            'privacy_mode',
            'video_fallback',
        ],
        'slideshow' => [
            'slideshow_gallery',
            'slideshow_loop',
            'slideshow_slide_duration',
            'slideshow_slide_transition',
            'slideshow_transition_duration',
            'slideshow_background_size',
            'slideshow_background_position',
            'slideshow_lazyload',
            'slideshow_ken_burns',
            'slideshow_ken_burns_zoom_direction',
        ],
        // 'classic' is the fallback for every other background_* leaf
        // (color, image, position, xpos, ypos, attachment, repeat, size,
        // bg_width) - 'color' is technically shared by classic/gradient/video
        // but Elementor defaults the type CHOOSE control itself to 'classic',
        // so a bare background_color write is treated as a classic-background
        // write unless a gradient/video/slideshow sibling key says otherwise.
    ];

    /**
     * Get the declarative toggle map for every Elementor group control that
     * requires a "toggle" control to be set before its children render CSS.
     *
     * Each entry is keyed by an internal group id and has:
     *  - toggle_suffix: the control-id suffix (relative to the group's
     *    prefix) of the control that gates the others.
     *  - toggle_value:  the value that activates rendering for the *other*
     *    groups; not meaningful for 'background', which is value-dependent
     *    and handled separately in apply_group_toggles().
     *  - child_suffixes: the control-id suffixes (relative to the group's
     *    prefix) of controls gated by the toggle.
     *  - own_keys: token(s) this group's prefix conventionally contains
     *    (e.g. a `title_typography_` prefix contains 'typography'). Used
     *    only to disambiguate a settings key that could plausibly belong to
     *    more than one group (e.g. a bare `_width` or `_color` suffix is a
     *    leaf of more than one group control).
     *  - self_gated: true when the toggle control is not a separate popover
     *    control injected by Group_Control_Base (popover === false) but is
     *    itself one of the group's own fields (e.g. Border's `border` type
     *    select, or Image Size's `size` select).
     *  - source: the file this entry was verified against.
     *
     * Background is intentionally NOT in this map - see
     * BACKGROUND_VARIANT_SUFFIXES and apply_group_toggles() for why.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function toggle_map(): array {
        return [

            // verified against elementor/includes/controls/groups/typography.php
            // (init_fields(), lines 105-272) and groups/base.php
            // (get_default_options()/init_options(), lines 375-385: default
            // popover starter_value is 'custom'). Typography overrides only
            // starter_name => 'typography' (typography.php lines 432-446);
            // it inherits the 'custom' starter_value from the base class.
            'typography' => [
                'toggle_suffix'   => 'typography',
                'toggle_value'    => 'custom',
                'child_suffixes'  => [
                    'font_family',
                    'font_size',
                    'font_weight',
                    'text_transform',
                    'font_style',
                    'text_decoration',
                    'line_height',
                    'letter_spacing',
                    'word_spacing',
                    // Variable-font fields, only present when the active font
                    // declares variable axes (typography.php add_font_variables_fields()).
                    'weight',
                    'width',
                ],
                'own_keys'        => [ 'typography' ],
                'self_gated'      => false,
                'source'          => 'includes/controls/groups/typography.php + groups/base.php',
            ],

            // verified against elementor/includes/controls/groups/text-shadow.php
            // (get_default_options(), lines 82-93): starter_name =>
            // 'text_shadow_type', starter_value => 'yes'.
            'text_shadow' => [
                'toggle_suffix'   => 'text_shadow_type',
                'toggle_value'    => 'yes',
                'child_suffixes'  => [ 'text_shadow' ],
                'own_keys'        => [ 'text_shadow' ],
                'self_gated'      => false,
                'source'          => 'includes/controls/groups/text-shadow.php',
            ],

            // verified against elementor/includes/controls/groups/box-shadow.php
            // (get_default_options(), lines 93-104): starter_name =>
            // 'box_shadow_type', starter_value => 'yes'.
            'box_shadow' => [
                'toggle_suffix'   => 'box_shadow_type',
                'toggle_value'    => 'yes',
                'child_suffixes'  => [ 'box_shadow', 'box_shadow_position' ],
                'own_keys'        => [ 'box_shadow' ],
                'self_gated'      => false,
                'source'          => 'includes/controls/groups/box-shadow.php',
            ],

            // verified against elementor/includes/controls/groups/text-stroke.php
            // (get_default_options(), lines 108-119): starter_name =>
            // 'text_stroke_type', starter_value => 'yes'.
            'text_stroke' => [
                'toggle_suffix'   => 'text_stroke_type',
                'toggle_value'    => 'yes',
                'child_suffixes'  => [ 'text_stroke', 'stroke_color' ],
                'own_keys'        => [ 'text_stroke' ],
                'self_gated'      => false,
                'source'          => 'includes/controls/groups/text-stroke.php',
            ],

            // verified against elementor/includes/controls/groups/css-filter.php
            // (get_default_options(), lines 158-168): starter_name =>
            // 'css_filter', no starter_value override, so it inherits the
            // base class default of 'custom' (groups/base.php line 379).
            'css_filter' => [
                'toggle_suffix'   => 'css_filter',
                'toggle_value'    => 'custom',
                'child_suffixes'  => [ 'blur', 'brightness', 'contrast', 'saturate', 'hue' ],
                'own_keys'        => [ 'css_filter' ],
                'self_gated'      => false,
                'source'          => 'includes/controls/groups/css-filter.php + groups/base.php',
            ],

            // verified against elementor/includes/controls/groups/border.php:
            // get_default_options() (lines 115-119) sets 'popover' => false,
            // so there is NO separate popover_toggle control here. Instead,
            // the group's own `border` SELECT control (border type) is a
            // normal, always-visible control, and `width`/`color` carry
            // `'condition' => [ 'border!' => [ '', 'none' ] ]` (lines 83-84,
            // 96-98) — i.e. any non-empty, non-"none" border type activates
            // them. 'solid' is used as the activating value we write.
            'border' => [
                'toggle_suffix'   => 'border',
                'toggle_value'    => 'solid',
                'child_suffixes'  => [ 'width', 'color' ],
                'own_keys'        => [ 'border' ],
                'self_gated'      => true,
                'source'          => 'includes/controls/groups/border.php',
            ],

            // verified against elementor/includes/controls/groups/image-size.php:
            // get_default_options() sets 'popover' => false. The group's own
            // `size` SELECT control gates `custom_dimension` via
            // `'condition' => [ 'size' => 'custom' ]`.
            'image_size' => [
                'toggle_suffix'   => 'size',
                'toggle_value'    => 'custom',
                'child_suffixes'  => [ 'custom_dimension' ],
                'own_keys'        => [ 'image_size', 'size', 'thumbnail', 'image' ],
                'self_gated'      => true,
                'source'          => 'includes/controls/groups/image-size.php',
            ],

            // verified against elementor/includes/controls/groups/flex-item.php:
            // get_default_options() sets 'popover' => false. The group's own
            // `basis_type` SELECT control gates `basis` via
            // `'condition' => [ 'basis_type' => 'custom' ]`.
            'flex_item' => [
                'toggle_suffix'   => 'basis_type',
                'toggle_value'    => 'custom',
                'child_suffixes'  => [ 'basis' ],
                'own_keys'        => [ 'flex_item', 'basis' ],
                'self_gated'      => true,
                'source'          => 'includes/controls/groups/flex-item.php',
            ],

            // Not included, verified but no toggle applies:
            // - flex-container.php: 'popover' => false, and its only
            //   `condition`-gated fields (`_is_row`/`_is_column`) are
            //   Elementor-internal HIDDEN prefix-class helpers keyed off
            //   `direction`, not user-facing style controls; every real
            //   style child (justify_content, align_items, gap, ...) renders
            //   unconditionally. No toggle needed.
            // - grid-container.php: 'popover' => false. `justify_content`
            //   and `align_content` ARE gated, but on a nested array key
            //   (`columns_grid[unit] === 'custom'` / `rows_grid[unit] ===
            //   'custom'`, lines ~219, ~259) rather than a simple scalar
            //   toggle - out of scope for this map's flat key model.
            //   Flagging for future extension rather than guessing.
        ];
    }

    /**
     * Given a set of proposed Elementor settings changes, return those
     * changes plus whatever group-control "toggle" keys are required so the
     * changes actually produce CSS.
     *
     * This is a thin wrapper around apply_group_toggles_verbose() that drops
     * the per-toggle provenance ('authoritative' vs 'heuristic') and returns
     * just the augmented settings array. Use apply_group_toggles_verbose()
     * directly when the caller needs to know (and surface, e.g. in a
     * --dry-run) which toggles were guessed rather than looked up from
     * Elementor's own control stack.
     *
     * @param array       $changes          Proposed 'settings' key => value changes.
     * @param array       $current_settings The element's current 'settings' array,
     *                                      used to avoid re-toggling something the
     *                                      element already has active.
     * @param string|null $element_type     Optional. 'widget', 'container', 'section',
     *                                      'column', etc. When given together with
     *                                      $widget_type (for 'widget') and Elementor is
     *                                      loaded, toggles are resolved authoritatively
     *                                      from that element's live control stack instead
     *                                      of the offline heuristic.
     * @param string|null $widget_type      Optional. Required alongside $element_type
     *                                      when $element_type is 'widget' (e.g. 'heading').
     * @return array $changes plus any required toggle keys.
     */
    public static function apply_group_toggles( array $changes, array $current_settings = [], ?string $element_type = null, ?string $widget_type = null ): array {
        return self::apply_group_toggles_verbose( $changes, $current_settings, $element_type, $widget_type )['changes'];
    }

    /**
     * Same contract as apply_group_toggles(), but also reports where each
     * added toggle came from - so a caller (the CLI's --dry-run output, in
     * particular) can show a human when a toggle was looked up authoritatively
     * from Elementor's own control stack versus guessed by the offline
     * suffix-matching heuristic in match_toggle_for_key().
     *
     * Resolution strategy:
     *  - When $element_type is given AND Elementor is loaded AND that element
     *    type (plus, for widgets, $widget_type) actually resolves to a real
     *    registered element, EVERY changed key in this call is resolved via
     *    resolve_toggle() against that element's real control stack. A key
     *    resolve_toggle() can't find (because that widget genuinely doesn't
     *    gate it, or the key is misspelled) is trusted and simply produces no
     *    toggle - it is deliberately NOT heuristic-guessed as a fallback,
     *    since doing so would silently reintroduce the exact wrong-write risk
     *    this authoritative path exists to remove.
     *  - Otherwise (no context given, or Elementor isn't loaded, or the
     *    element/widget type is unknown to Elementor) every changed key falls
     *    back to the offline heuristic from match_toggle_for_key(), and every
     *    toggle produced this way is tagged 'source' => 'heuristic'.
     *
     * The choice is made once for the whole call, not per key - a call either
     * has ground truth available or it doesn't.
     *
     * @param array       $changes
     * @param array       $current_settings
     * @param string|null $element_type
     * @param string|null $widget_type
     * @return array{changes:array,toggles:array<string,array{value:mixed,source:string}>}
     */
    public static function apply_group_toggles_verbose( array $changes, array $current_settings = [], ?string $element_type = null, ?string $widget_type = null ): array {
        $element = null;

        if ( null !== $element_type && self::elementor_ready() ) {
            $element = self::get_element_instance( $element_type, $widget_type );
        }

        $authoritative = null !== $element;

        // toggle_key => [ 'value' => ..., 'variant' => 'classic'|'gradient'|'video'|'slideshow'|null, 'source' => ... ].
        // Built up across all changed keys before being applied, so that e.g.
        // two background_* keys in the same call can jointly decide the right
        // background type.
        $needed = [];

        if ( $authoritative ) {
            $controls = self::get_merged_control_stack( $element );

            foreach ( $changes as $key => $value ) {
                $base_key = self::strip_responsive_suffix( $key );

                $match = self::resolve_toggle_from_stack( $base_key, $controls );

                if ( null === $match ) {
                    continue;
                }

                self::merge_needed_toggle( $needed, $match['toggle_key'], $match['value'], $match['variant'], 'authoritative' );
            }
        } else {
            $groups = self::toggle_map();

            foreach ( $changes as $key => $value ) {
                $base_key = self::strip_responsive_suffix( $key );

                $match = self::match_toggle_for_key( $base_key, $groups );

                if ( null === $match ) {
                    continue;
                }

                [ $toggle_key, $desired_value, $variant ] = $match;

                self::merge_needed_toggle( $needed, $toggle_key, $desired_value, $variant, 'heuristic' );
            }
        }

        $result_changes = $changes;
        $toggles_applied = [];

        foreach ( $needed as $toggle_key => $info ) {
            // Never override a toggle the caller explicitly passed.
            if ( array_key_exists( $toggle_key, $changes ) ) {
                continue;
            }

            // Never override a toggle already truthy on the element.
            if ( ! empty( $current_settings[ $toggle_key ] ) ) {
                continue;
            }

            $result_changes[ $toggle_key ] = $info['value'];

            $toggles_applied[ $toggle_key ] = [
                'value'  => $info['value'],
                'source' => $info['source'],
            ];
        }

        return [
            'changes' => $result_changes,
            'toggles' => $toggles_applied,
        ];
    }

    /**
     * Merge a single (toggle_key => value) resolution into the accumulator,
     * applying the same "more specific background variant wins" priority
     * rule regardless of whether the resolution came from the authoritative
     * or the heuristic path.
     *
     * @param array       $needed     Accumulator, by reference.
     * @param string      $toggle_key
     * @param mixed       $value
     * @param string|null $variant    One of 'classic'|'gradient'|'video'|'slideshow' for a
     *                                background toggle, null for every other group.
     * @param string      $source     'authoritative' or 'heuristic'.
     * @return void
     */
    private static function merge_needed_toggle( array &$needed, string $toggle_key, $value, ?string $variant, string $source ): void {
        if ( ! isset( $needed[ $toggle_key ] ) ) {
            $needed[ $toggle_key ] = [
                'value'   => $value,
                'variant' => $variant,
                'source'  => $source,
            ];

            return;
        }

        if ( null !== $variant && self::background_variant_priority( $variant ) > self::background_variant_priority( $needed[ $toggle_key ]['variant'] ?? 'classic' ) ) {
            $needed[ $toggle_key ] = [
                'value'   => $value,
                'variant' => $variant,
                'source'  => $source,
            ];
        }
    }

    /**
     * The authoritative counterpart to match_toggle_for_key(): given a single
     * settings key (any responsive suffix already stripped by the caller) and
     * an element type, look up EXACTLY which toggle - if any - Elementor
     * itself registered as gating that control, by reading the control's own
     * `condition` array off its live control stack. No suffix-guessing, no
     * prefix-boundary guessing: the condition key IS the real, already-prefixed
     * toggle control name, because Elementor computed it itself when the
     * widget/element registered its controls (see
     * includes/controls/groups/base.php, add_condition_prefix() /
     * prepare_fields(), and includes/base/controls-stack.php,
     * start_popover()).
     *
     * Returns null - never a guess - when: Elementor isn't loaded, the
     * element/widget type doesn't resolve to a real registered element, the
     * key doesn't exist in that element's control stack at all, or the key
     * exists but isn't gated by anything this class recognizes as a group
     * toggle (e.g. a plain control like `title`, or a control gated by
     * something other than a group toggle, like `background_video_link`'s
     * secondary `attachment=fixed` condition).
     *
     * @param string $key          The settings key to resolve, e.g. "content_border_width"
     *                             or "typography_font_size_tablet".
     * @param string $element_type 'widget', 'container', 'section', 'column', etc.
     * @param string|null $widget_type Required when $element_type is 'widget'.
     * @return array{toggle_key:string,value:mixed}|null
     */
    public static function resolve_toggle( string $key, string $element_type, ?string $widget_type = null ): ?array {
        if ( ! self::elementor_ready() ) {
            return null;
        }

        $element = self::get_element_instance( $element_type, $widget_type );

        if ( null === $element ) {
            return null;
        }

        $controls = self::get_merged_control_stack( $element );

        $base_key = self::strip_responsive_suffix( $key );

        $match = self::resolve_toggle_from_stack( $base_key, $controls );

        if ( null === $match ) {
            return null;
        }

        return [
            'toggle_key' => $match['toggle_key'],
            'value'      => $match['value'],
        ];
    }

    /**
     * The flattened control schema for an element type: every control's
     * name, type, default, whether it's a responsive control, and which
     * toggle key (if any) gates it - straight from Elementor's own live
     * control stack, the same source resolve_toggle() reads. Meant as the
     * lookup table an agent uses to find real control names before writing
     * settings, instead of guessing them.
     *
     * Pure read: touches nothing but Elementor's already-registered control
     * definitions.
     *
     * @param string      $element_type 'widget', 'container', 'section', 'column', etc.
     * @param string|null $widget_type  Required when $element_type is 'widget'.
     * @return array<string,array{type:string|null,default:mixed,is_responsive:bool,gated_by:string|null}>
     *         Empty array when Elementor isn't loaded or the type is unknown.
     */
    public static function describe_element( string $element_type, ?string $widget_type = null ): array {
        $element = self::get_element_instance( $element_type, $widget_type );

        if ( null === $element ) {
            return [];
        }

        $controls = self::get_merged_control_stack( $element );

        $schema = [];

        foreach ( $controls as $name => $control ) {
            $match = self::resolve_toggle_from_stack( self::strip_responsive_suffix( $name ), $controls );

            $schema[ $name ] = [
                'type'          => $control['type'] ?? null,
                'default'       => $control['default'] ?? null,
                'is_responsive' => isset( $control['responsive'] ) || ! empty( $control['is_responsive'] ),
                'gated_by'      => $match['toggle_key'] ?? null,
            ];
        }

        return $schema;
    }

    /**
     * Whether Elementor is loaded and ready to query (has finished its own
     * 'elementor/loaded' bootstrap and set up its Plugin singleton). Every
     * method that touches live Elementor objects checks this first so the
     * class stays safe to load - and its heuristic path stays fully
     * functional - when Elementor is absent entirely.
     *
     * @return bool
     */
    private static function elementor_ready(): bool {
        return class_exists( '\Elementor\Plugin' )
            && did_action( 'elementor/loaded' )
            && isset( \Elementor\Plugin::$instance )
            && isset( \Elementor\Plugin::$instance->elements_manager )
            && isset( \Elementor\Plugin::$instance->widgets_manager );
    }

    /**
     * Resolve a live Elementor element/widget type instance, the same way
     * Elementor's own `Elements_Manager::get_element()` does (that's exactly
     * what this delegates to): for 'widget' it goes through
     * `widgets_manager->get_widget_types( $widget_type )`, for anything else
     * (`container`, `section`, `column`, ...) through
     * `elements_manager->get_element_types( $element_type )`.
     *
     * verified against elementor/includes/managers/elements.php,
     * Elements_Manager::get_element() (~line 101) and get_element_types()
     * (~line 206); elementor/includes/managers/widgets.php,
     * Widgets_Manager::get_widget_types() (~line 356).
     *
     * @param string      $element_type
     * @param string|null $widget_type
     * @return \Elementor\Controls_Stack|null
     */
    private static function get_element_instance( string $element_type, ?string $widget_type ) {
        if ( ! self::elementor_ready() ) {
            return null;
        }

        // get_widget_types( null ) returns the full widget registry array
        // rather than a single instance - guard against that explicitly
        // rather than let a truthy-but-wrong value flow into get_stack().
        if ( 'widget' === $element_type && empty( $widget_type ) ) {
            return null;
        }

        try {
            $element = \Elementor\Plugin::$instance->elements_manager->get_element( $element_type, $widget_type );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_object( $element ) ? $element : null;
    }

    /**
     * Get an element/widget's full, flat control array - both the 'controls'
     * bucket and the 'style_controls' bucket - keyed by control name.
     *
     * Elementor splits registered controls across these two buckets and only
     * merges 'style_controls' into `get_controls()`'s return value when
     * `Performance::is_use_style_controls()` is true (opted into only inside
     * the editor/preview/REST request lifecycle - see
     * elementor/core/frontend/performance.php). Outside that lifecycle
     * (exactly the CLI/WP-CLI context this class is written for),
     * `get_controls()` alone silently omits every group-controlled style
     * field (typography, border, box-shadow, css-filter, text-shadow,
     * text-stroke, ...) - confirmed live against this project's install,
     * where none of those appear via `get_controls()` but all of them appear
     * under `get_stack()['style_controls']`. Reading `get_stack()` directly
     * and merging both buckets ourselves sidesteps that editor-only gate
     * entirely, without mutating Elementor's global Performance state.
     *
     * verified against elementor/includes/base/controls-stack.php,
     * get_controls() (~line 314) and get_stack() (~line 566); confirmed live
     * via `wp eval` against a real widget's get_stack() output.
     *
     * @param \Elementor\Controls_Stack $element
     * @return array<string,array>
     */
    private static function get_merged_control_stack( $element ): array {
        try {
            $stack = $element->get_stack();
        } catch ( \Throwable $e ) {
            return [];
        }

        $controls       = is_array( $stack['controls'] ?? null ) ? $stack['controls'] : [];
        $style_controls = is_array( $stack['style_controls'] ?? null ) ? $stack['style_controls'] : [];

        // '+' keeps the 'controls' bucket's entry on the rare case of a name
        // collision between the two buckets; none is expected in practice.
        return $controls + $style_controls;
    }

    /**
     * Given a single control's already-resolved definition (found by
     * $base_key in $controls), read its `condition` array to find the group
     * toggle that gates it, and work out the value that would activate it.
     *
     * Every `condition` array entry is inspected (not just the first) because
     * a field can carry more than one condition, and for a field that already
     * had its own condition before the group-toggle one was appended (e.g.
     * typography's variable-font fields, see typography.php
     * add_font_variables_fields()), the group toggle isn't necessarily first
     * - confirmed live: on this project's install,
     * `border_width`'s condition is `{"border_border!":["","none"]}` and
     * `background_color`'s is `{"background_background":["classic","gradient","video"]}`,
     * both single-entry with the toggle first, but Elementor's own condition
     * merging order isn't guaranteed in general, so every entry is checked.
     *
     * An entry only counts as a group toggle when its key (stripped of a
     * trailing `!` negation marker) ends with one of this class's known
     * toggle_suffix tokens (see known_toggle_suffixes()) - e.g.
     * "border_border" ends with "border", "css_filters_css_filter" ends with
     * "css_filter". This is a suffix check too, but a much lower-risk one
     * than match_toggle_for_key()'s: it runs against a live, already-prefixed
     * control name Elementor itself produced (so the match is confirmed to
     * exist), against a small set of distinctive multi-word tokens, not
     * against generic single-word leaf names.
     *
     * @param string $base_key The control name to resolve (already stripped
     *                         of any responsive suffix).
     * @param array  $controls The element's full merged control stack.
     * @return array{toggle_key:string,value:mixed,variant:string|null}|null
     */
    private static function resolve_toggle_from_stack( string $base_key, array $controls ): ?array {
        if ( ! isset( $controls[ $base_key ] ) ) {
            return null;
        }

        $condition = $controls[ $base_key ]['condition'] ?? null;

        if ( empty( $condition ) || ! is_array( $condition ) ) {
            return null;
        }

        $known_suffixes = self::known_toggle_suffixes();

        foreach ( $condition as $cond_key => $cond_value ) {
            $bare_key = str_ends_with( $cond_key, '!' ) ? substr( $cond_key, 0, -1 ) : $cond_key;

            foreach ( $known_suffixes as $suffix ) {
                if ( self::str_ends_with_token( $bare_key, $suffix ) ) {
                    return self::build_authoritative_toggle_result( $bare_key, $suffix, $cond_value, $controls );
                }
            }
        }

        return null;
    }

    /**
     * Build the [toggle_key, value, variant] result once resolve_toggle_from_stack()
     * has identified which condition entry is the group toggle.
     *
     * The value to write is sourced with a clear preference order:
     *  1. If the toggle control itself is a Controls_Manager::POPOVER_TOGGLE
     *     (typography, text_shadow, box_shadow, text_stroke, css_filter),
     *     read its own `return_value` directly - confirmed live, e.g.
     *     `typography_typography`'s return_value is "custom",
     *     `box_shadow_box_shadow_type`'s is "yes" - exactly Elementor's own
     *     answer, not a curated guess.
     *  2. If it's the 'background' CHOOSE control, the activating value is
     *     read from the condition's own allow-list (e.g.
     *     `{"background_background":["gradient"]}` -> 'gradient'); when that
     *     allow-list has more than one acceptable type (background_color is
     *     shared by classic/gradient/video), it can't be resolved from this
     *     one key alone, so it defaults to the lowest-priority 'classic' and
     *     is tagged with that variant so apply_group_toggles_verbose()'s
     *     batch merge can still let a less ambiguous sibling key in the same
     *     call (e.g. a gradient-only key) win.
     *  3. Otherwise (border, image_size/size, flex_item/basis_type - none of
     *     which are popovers, so there is no single canonical
     *     `return_value`) fall back to this class's curated activating value
     *     for that toggle_suffix from toggle_map().
     *
     * @param string $toggle_key The already-prefixed control name (e.g. "border_border").
     * @param string $suffix     The matched toggle_suffix token (e.g. "border").
     * @param mixed  $cond_value The condition's value for this entry.
     * @param array  $controls   The element's full merged control stack.
     * @return array{toggle_key:string,value:mixed,variant:string|null}
     */
    private static function build_authoritative_toggle_result( string $toggle_key, string $suffix, $cond_value, array $controls ): array {
        if ( 'background' === $suffix ) {
            $allowed      = is_array( $cond_value ) ? array_values( $cond_value ) : [ $cond_value ];
            $bg_types     = [ 'classic', 'gradient', 'video', 'slideshow' ];
            $matched_type = array_values( array_intersect( $allowed, $bg_types ) );

            $variant = 1 === count( $matched_type ) ? $matched_type[0] : 'classic';

            return [
                'toggle_key' => $toggle_key,
                'value'      => $variant,
                'variant'    => $variant,
            ];
        }

        $toggle_control = $controls[ $toggle_key ] ?? null;

        if (
            is_array( $toggle_control )
            && ( $toggle_control['type'] ?? null ) === 'popover_toggle'
            && array_key_exists( 'return_value', $toggle_control )
        ) {
            return [
                'toggle_key' => $toggle_key,
                'value'      => $toggle_control['return_value'],
                'variant'    => null,
            ];
        }

        $group_def = self::toggle_suffix_index()[ $suffix ] ?? null;

        return [
            'toggle_key' => $toggle_key,
            'value'      => $group_def['toggle_value'] ?? '',
            'variant'    => null,
        ];
    }

    /**
     * The toggle_suffix tokens this class recognizes (from toggle_map(),
     * plus the literal 'background' since that group is intentionally not in
     * toggle_map()), longest-first so a more specific/distinctive token
     * (e.g. "text_shadow_type") is preferred over a shorter one that could in
     * principle also match ("border").
     *
     * @return string[]
     */
    private static function known_toggle_suffixes(): array {
        $suffixes = array_column( self::toggle_map(), 'toggle_suffix' );
        $suffixes[] = 'background';

        usort( $suffixes, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

        return $suffixes;
    }

    /**
     * toggle_map(), reindexed by toggle_suffix for O(1) lookup of a group's
     * curated activating value once resolve_toggle_from_stack() has already
     * identified the toggle_suffix from a live condition key.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function toggle_suffix_index(): array {
        $index = [];

        foreach ( self::toggle_map() as $group_def ) {
            $index[ $group_def['toggle_suffix'] ] = $group_def;
        }

        return $index;
    }

    /**
     * Map of background variant => the value written to the `background`
     * CHOOSE control to activate that variant's fields.
     *
     * verified against elementor/includes/controls/groups/background.php,
     * get_default_background_types() (lines 92-111): the CHOOSE control's
     * option keys are literally 'classic' | 'gradient' | 'video' | 'slideshow'.
     *
     * @var array<string,string>
     */
    private const BACKGROUND_VALUE_FOR_VARIANT = [
        'classic'   => 'classic',
        'gradient'  => 'gradient',
        'video'     => 'video',
        'slideshow' => 'slideshow',
    ];

    /**
     * Relative priority used when a single apply_group_toggles() call
     * touches leaf keys from more than one background variant (e.g. a
     * gradient key alongside a plain classic key) — the more specific,
     * non-default variant wins so the toggle actually matches what the
     * caller is trying to render.
     *
     * @param string $variant
     * @return int
     */
    private static function background_variant_priority( string $variant ): int {
        return 'classic' === $variant ? 0 : 1;
    }

    /**
     * Try to match a (responsive-suffix-stripped) settings key against every
     * group's leaf suffixes - both the generic groups from toggle_map() and
     * background's variant-classified suffixes - and return the single best
     * match.
     *
     * All candidates (generic + background) are pooled together before
     * choosing, rather than checking background first: a generic group's
     * leaf can otherwise be shadowed by an unrelated, shorter background
     * leaf that happens to also match as a trailing substring (e.g.
     * "typography_font_size" ends with "_size", which is also background's
     * Display Size leaf - pooling and preferring the own-name + longest
     * match resolves this in favor of typography's "font_size").
     *
     * @param string $base_key
     * @param array  $groups   The map returned by toggle_map().
     * @return array{0:string,1:string,2:string|null}|null
     *         [ toggle_key, desired_value, background_variant_or_null ] or null.
     */
    private static function match_toggle_for_key( string $base_key, array $groups ): ?array {
        $candidates = [];

        foreach ( $groups as $group_def ) {
            foreach ( $group_def['child_suffixes'] as $suffix ) {
                $prefix = self::strip_suffix( $base_key, $suffix );

                if ( null === $prefix ) {
                    continue;
                }

                $own_match = '' === $prefix;

                if ( ! $own_match ) {
                    foreach ( $group_def['own_keys'] as $own_key ) {
                        if ( $prefix === $own_key || self::str_ends_with_token( $prefix, $own_key ) ) {
                            $own_match = true;
                            break;
                        }
                    }
                }

                $toggle_key = ( '' === $prefix ? '' : $prefix . '_' ) . $group_def['toggle_suffix'];

                $candidates[] = [
                    'suffix'        => $suffix,
                    'own_match'     => $own_match,
                    'toggle_key'    => $toggle_key,
                    'desired_value' => $group_def['toggle_value'],
                    'variant'       => null,
                ];
            }
        }

        $background_suffixes = [
            'classic' => [
                'color',
                'image',
                'position',
                'xpos',
                'ypos',
                'attachment',
                'repeat',
                'size',
                'bg_width',
            ],
        ] + self::BACKGROUND_VARIANT_SUFFIXES;

        foreach ( $background_suffixes as $variant => $suffixes ) {
            foreach ( $suffixes as $suffix ) {
                $prefix = self::strip_suffix( $base_key, $suffix );

                if ( null === $prefix ) {
                    continue;
                }

                $own_match = '' === $prefix
                    || self::str_ends_with_token( $prefix, 'background' )
                    || self::str_ends_with_token( $prefix, 'bg' );

                $toggle_key = ( '' === $prefix ? '' : $prefix . '_' ) . 'background';

                $candidates[] = [
                    'suffix'        => $suffix,
                    'own_match'     => $own_match,
                    'toggle_key'    => $toggle_key,
                    'desired_value' => self::BACKGROUND_VALUE_FOR_VARIANT[ $variant ] ?? 'classic',
                    'variant'       => $variant,
                ];
            }
        }

        if ( empty( $candidates ) ) {
            return null;
        }

        $preferred = array_values( array_filter( $candidates, static fn( $c ) => $c['own_match'] ) );
        $pool      = ! empty( $preferred ) ? $preferred : $candidates;

        usort( $pool, static fn( $a, $b ) => strlen( $b['suffix'] ) <=> strlen( $a['suffix'] ) );

        $best = $pool[0];

        return [ $best['toggle_key'], $best['desired_value'], $best['variant'] ];
    }

    /**
     * If $key ends with "_{$suffix}" (with something before it) or is
     * exactly equal to $suffix (no prefix at all), return the prefix
     * (possibly ''). Otherwise return null.
     *
     * @param string $key
     * @param string $suffix
     * @return string|null
     */
    private static function strip_suffix( string $key, string $suffix ): ?string {
        if ( $key === $suffix ) {
            return '';
        }

        $needle = '_' . $suffix;

        if ( str_ends_with( $key, $needle ) && strlen( $key ) > strlen( $needle ) ) {
            return substr( $key, 0, -strlen( $needle ) );
        }

        return null;
    }

    /**
     * Whether $haystack is exactly $token or ends with "_{$token}" — used to
     * check whether a recovered group prefix "contains" a group's own name,
     * e.g. prefix `content_border` for own key `border`.
     *
     * @param string $haystack
     * @param string $token
     * @return bool
     */
    private static function str_ends_with_token( string $haystack, string $token ): bool {
        return $haystack === $token || str_ends_with( $haystack, '_' . $token );
    }

    /**
     * Strip a trailing responsive breakpoint suffix (e.g. `_tablet`,
     * `_mobile_extra`) off a settings key, if present.
     *
     * verified against elementor/includes/base/controls-stack.php,
     * add_responsive_control() (~line 970): non-desktop control ids are
     * `$id . '_' . $device_name`.
     *
     * @param string $key
     * @return string The key with any responsive suffix removed.
     */
    private static function strip_responsive_suffix( string $key ): string {
        foreach ( self::RESPONSIVE_SUFFIXES_BY_LENGTH as $breakpoint ) {
            if ( 'desktop' === $breakpoint ) {
                continue; // Desktop is never a suffix - it's the bare key.
            }

            $needle = '_' . $breakpoint;

            if ( str_ends_with( $key, $needle ) && strlen( $key ) > strlen( $needle ) ) {
                return substr( $key, 0, -strlen( $needle ) );
            }
        }

        return $key;
    }

    /**
     * Build a responsive settings key for a given breakpoint.
     *
     * verified against elementor/includes/base/controls-stack.php,
     * add_responsive_control() (~line 970): `$id_suffix =
     * Breakpoints_Manager::BREAKPOINT_KEY_DESKTOP === $device_name ? '' :
     * '_' . $device_name;`.
     *
     * @param string $key        The base (desktop) settings key.
     * @param string $breakpoint One of the values from active_breakpoints().
     * @return string The responsive key, e.g. "font_size_tablet".
     */
    public static function responsive_key( string $key, string $breakpoint ): string {
        if ( 'desktop' === $breakpoint ) {
            return $key;
        }

        return $key . '_' . $breakpoint;
    }

    /**
     * Get the breakpoints currently active in the site's Elementor kit, via
     * the live Breakpoints manager when Elementor is loaded, or a sane
     * static fallback (Elementor's own always-on defaults) otherwise.
     * `desktop` is always included.
     *
     * verified against elementor/core/breakpoints/manager.php:
     * `init_breakpoints()` (~line 462) hardcodes mobile and tablet as always
     * enabled; every other breakpoint (mobile_extra, tablet_extra, laptop,
     * widescreen) requires the `additional_custom_breakpoints` experiment
     * plus an explicit kit setting. `get_active_devices_list()` (~line 132)
     * is what actually includes the implicit 'desktop' entry.
     *
     * @return string[]
     */
    public static function active_breakpoints(): array {
        if (
            class_exists( '\Elementor\Plugin' )
            && did_action( 'elementor/loaded' )
            && isset( \Elementor\Plugin::$instance )
            && isset( \Elementor\Plugin::$instance->breakpoints )
        ) {
            $devices = \Elementor\Plugin::$instance->breakpoints->get_active_devices_list();

            if ( is_array( $devices ) && ! empty( $devices ) ) {
                if ( ! in_array( 'desktop', $devices, true ) ) {
                    $devices[] = 'desktop';
                }

                return array_values( $devices );
            }
        }

        // Fallback: Elementor's own hardcoded always-enabled defaults.
        return [ 'mobile', 'tablet', 'desktop' ];
    }

    /**
     * Whether $breakpoint is a name Elementor recognizes at all (regardless
     * of whether it's currently active in the site's kit).
     *
     * @param string $breakpoint
     * @return bool
     */
    public static function is_valid_breakpoint( string $breakpoint ): bool {
        return in_array( $breakpoint, self::ALL_BREAKPOINTS, true );
    }
}
