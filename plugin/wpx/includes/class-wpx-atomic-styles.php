<?php
/**
 * WPX Atomic Styles
 *
 * Pure library for reading and writing Elementor 4 (atomic/V4) element
 * styles. Elementor's classic model gates a style behind a flat settings
 * key (`typography_font_size`, `typography_font_size_tablet`, ...). V4
 * throws that away: a style is a *class*, stored as an entry in the
 * element's own `styles` array, made of one or more "variants" (one per
 * breakpoint/state combination), each holding typed `props`.
 *
 * Ground truth for every shape below was read from Elementor 4.2.4
 * (`modules/atomic-widgets/styles/`, `modules/atomic-widgets/parsers/`,
 * `modules/atomic-widgets/prop-types/`, `core/breakpoints/manager.php`):
 *
 * - A style definition:
 *     [ 'id' => 'e-abc123-local', 'type' => 'class', 'label' => '...',
 *       'variants' => [ Variant, ... ] ]
 *   (`Style_Definition::build()`).
 *
 * - A variant:
 *     [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ],
 *       'props' => [ 'font-size' => Typed_Value, ... ] ]
 *   (`Style_Variant::build()`). `meta.state` is one of the constants in
 *   `Style_States` (hover, active, focus, focus-visible, checked,
 *   e--selected, e--disabled) or null for the base state. `meta.breakpoint`
 *   is a breakpoint key from `Core\Breakpoints\Manager` (desktop, mobile,
 *   mobile_extra, tablet, tablet_extra, laptop, widescreen) - this is the
 *   V4 replacement for the classic `_tablet`/`_mobile` settings-key suffix.
 *   `desktop` is the base/default breakpoint and renders with no media
 *   query (`Atomic_Styles_Manager::DEFAULT_BREAKPOINT`); every other key
 *   wraps the declaration in `@media(max-width:...)` or `(min-width:...)`
 *   per `Styles_Renderer::wrap_with_media_query()`.
 *
 * - Every prop value uses the same typed envelope as element props:
 *   `{ '$$type' => <prop's own key>, 'value' => <payload> }`
 *   (`Has_Transformable_Validation::is_transformable()`). Composite props
 *   (size, dimensions, border-radius-v2, border-width-v2, background,
 *   flex, layout-direction, ...) nest further envelopes inside `value`.
 *
 * - The element references a local style by id through its own
 *   `settings.classes` prop: `{ '$$type' => 'classes', 'value' => [ id,
 *   ... ] }` (`Classes_Prop_Type`). Style ids elsewhere in core are
 *   generated with `Utils::generate_id( "e-{$element_id}-", $existing )`
 *   (see `import-export/modifiers/styles-ids-modifier.php`); this class
 *   mirrors that prefix but keeps the id fully deterministic (see
 *   `get_local_style_id()`) so that `set_style()` is naturally idempotent
 *   without a search step.
 *
 * - Local (element-scoped) styles live inline, in the element's own
 *   `styles` array inside `_elementor_data` (read back in
 *   `Atomic_Widget_Styles::parse_element_style()` as
 *   `$element_data['styles'] ?? []`). This is the only thing this class
 *   manages. Global/reusable classes are a different, heavier subsystem:
 *   each one is its own `e_global_class` custom-post-type post
 *   (`Global_Class_Post_Type::CPT`), addressed through
 *   `Global_Classes_Repository` (`modules/global-classes/`), and CSS for
 *   both kinds is produced by the same `Styles_Renderer`, just fed from
 *   different sources (`Atomic_Widget_Styles` for local, an equivalent
 *   registration for global classes) and written to separate files under
 *   `wp-content/uploads/elementor/css/` by `CSS_Files_Manager`.
 *
 * This class deliberately keeps one local style ("class") per element,
 * identified by a deterministic id derived from the element's own id.
 * An agent can still express both responsive and state-based styling
 * (the two things the CLI actually needs) by writing multiple variants
 * onto that one class - it just never has to manage multiple local
 * classes per element.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Atomic_Styles {

    /**
     * The breakpoint key that renders with no media query.
     * Mirrors `Atomic_Styles_Manager::DEFAULT_BREAKPOINT`.
     */
    const DEFAULT_BREAKPOINT = 'desktop';

    /**
     * Style prop keys `wrap_style_value()` knows how to build, kept in
     * sync by hand with the branches implemented below. Exposed through
     * `describe_style_schema()` so an agent can tell, per prop, whether
     * it is safe to hand a raw CLI value to `wrap_style_value()` or
     * whether it must build the typed envelope itself.
     *
     * @var string[]
     */
    const SUPPORTED_WRAP_PROPS = [
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'inset-block-start', 'inset-inline-end', 'inset-block-end', 'inset-inline-start',
        'font-size', 'letter-spacing', 'word-spacing', 'line-height', 'font-family',
        'opacity', 'outline-width', 'outline-offset', 'scroll-margin-top',
        'z-index', 'column-count', 'order',
        'color', 'border-color', 'outline-color',
        'overflow', 'object-fit', 'position', 'display', 'flex-direction', 'flex-wrap',
        'text-align', 'font-style', 'text-decoration', 'text-transform', 'direction',
        'border-style', 'outline-style', 'justify-content', 'justify-items',
        'align-content', 'align-items', 'align-self', 'mix-blend-mode', 'appearance',
        'cursor', 'all', 'grid-auto-flow', 'content', 'clip-path', 'aspect-ratio',
        'padding', 'margin', 'border-radius', 'border-width', 'gap', 'background', 'flex',
    ];

    /**
     * Best-effort classic-key -> V4 prop-key map. Every entry here is
     * sourced from a real classic control field in Elementor 4.2.4; see
     * the inline comments. Nothing is guessed: a classic key that has no
     * verified V4 equivalent is left out on purpose (see
     * `classic_to_atomic_key()`'s docblock for the refused list).
     *
     * @var array<string, string>
     */
    const CLASSIC_KEY_MAP = [
        // Group_Control_Typography fields.
        // Source: elementor/includes/controls/groups/typography.php (init_fields()).
        'typography_font_family'     => 'font-family',
        'typography_font_size'       => 'font-size',
        'typography_font_weight'     => 'font-weight',
        'typography_font_style'      => 'font-style',
        'typography_text_transform'  => 'text-transform',
        'typography_text_decoration' => 'text-decoration',
        'typography_line_height'     => 'line-height',
        'typography_letter_spacing'  => 'letter-spacing',
        'typography_word_spacing'    => 'word-spacing',

        // Group_Control_Border fields.
        // Source: elementor/includes/controls/groups/border.php (init_fields()).
        // The classic 'border' field is a style *keyword* (solid/dashed/...),
        // which is exactly V4's `border-style` enum - not a structural toggle,
        // safe to map directly.
        'border_border' => 'border-style',
        'border_width'  => 'border-width',
        'border_color'  => 'border-color',

        // Group_Control_Background 'color' field (solid fill only).
        // Source: elementor/includes/controls/groups/background.php (init_fields(),
        // field 'color', active when background_background === 'classic').
        // V4's `background` prop has its own top-level `color` shape field
        // (Background_Prop_Type::define_shape()) for exactly this case, so a
        // solid background color has an honest, lossless V4 equivalent.
        'background_color' => 'background',

        // Common "Advanced" tab controls, added to (almost) every widget.
        // Source: elementor/includes/widgets/common-base.php, add_common_style_sections()
        // (`_margin` / `_padding` are DIMENSIONS controls, `_z_index` is a NUMBER control).
        '_margin'  => 'margin',
        '_padding' => 'padding',
        '_z_index' => 'z-index',
    ];

    /**
     * Cached `Style_Schema::get()` result (an array of live Elementor
     * `Prop_Type` objects), or `[]` when Elementor's atomic-widgets
     * classes are not loaded.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $schema_cache = null;

    /**
     * Get an element's V4 style definitions in a readable, agent-friendly
     * form.
     *
     * @param array $element The raw Elementor element node (has `id`,
     *                        `elType`, `settings`, `styles`, `elements`).
     * @return array List of style definitions:
     *               [ [ 'id', 'label', 'type', 'variants' => [
     *                     [ 'breakpoint', 'state', 'props' (raw typed
     *                       envelopes), 'props_readable' (flattened
     *                       human values) ]
     *               ] ] ].
     */
    public function get_styles( array $element ): array {
        $styles = $element['styles'] ?? [];

        if ( ! is_array( $styles ) ) {
            return [];
        }

        $result = [];

        foreach ( $styles as $style_id => $style ) {
            if ( ! is_array( $style ) ) {
                continue;
            }

            $variants = [];

            foreach ( ( $style['variants'] ?? [] ) as $variant ) {
                if ( ! is_array( $variant ) ) {
                    continue;
                }

                $props = is_array( $variant['props'] ?? null ) ? $variant['props'] : [];

                $variants[] = [
                    'breakpoint'     => $variant['meta']['breakpoint'] ?? self::DEFAULT_BREAKPOINT,
                    'state'          => $variant['meta']['state'] ?? null,
                    'props'          => $props,
                    'props_readable' => $this->readable_props( $props ),
                ];
            }

            $result[] = [
                'id'       => $style['id'] ?? (string) $style_id,
                'label'    => $style['label'] ?? (string) $style_id,
                'type'     => $style['type'] ?? 'class',
                'variants' => $variants,
            ];
        }

        return $result;
    }

    /**
     * Describe what a variant may contain: valid breakpoints, valid
     * states, and the supported style props - the lookup table an agent
     * needs so it stops guessing.
     *
     * When Elementor's atomic-widgets classes are loaded, the prop list
     * is read live from `Style_Schema::get()` so it can never drift from
     * what the V4 editor itself accepts.
     *
     * @return array [ 'breakpoints' => [...], 'states' => [...], 'props' => [...] ].
     */
    public function describe_style_schema(): array {
        return [
            'breakpoints' => $this->describe_breakpoints(),
            'states'      => $this->describe_states(),
            'props'       => $this->describe_props(),
        ];
    }

    /**
     * Return the element with `props` applied to the variant identified
     * by `$meta` (breakpoint + state), creating the style definition, the
     * variant, and the `classes` reference if they do not exist yet.
     *
     * Idempotent: calling this twice with the same `$props`/`$meta`
     * merges onto the same variant of the same (deterministically-id'd)
     * style and leaves the `classes` reference untouched the second time.
     *
     * @param array $element The raw Elementor element node.
     * @param array $props   Prop key => raw CLI value (e.g. `'font-size' =>
     *                       '72px'`) or an already-wrapped `{'$$type':...,
     *                       'value':...}` envelope, which is passed through
     *                       unchanged. Values that `wrap_style_value()`
     *                       cannot honestly build are dropped rather than
     *                       written malformed.
     * @param array $meta    Optional `[ 'breakpoint' => ..., 'state' => ... ]`.
     *                       Defaults to `[ 'breakpoint' => 'desktop', 'state' => null ]`.
     * @return array The updated element.
     */
    public function set_style( array $element, array $props, array $meta = [] ): array {
        $meta     = $this->normalize_meta( $meta );
        $style_id = $this->get_local_style_id( $element );

        $styles = is_array( $element['styles'] ?? null ) ? $element['styles'] : [];

        if ( ! isset( $styles[ $style_id ] ) || ! is_array( $styles[ $style_id ] ) ) {
            $styles[ $style_id ] = [
                'id'       => $style_id,
                'type'     => 'class',
                'label'    => $style_id,
                'variants' => [],
            ];
        }

        $wrapped_props = [];

        foreach ( $props as $prop_key => $raw_value ) {
            $wrapped = $this->is_already_wrapped( $raw_value )
                ? $raw_value
                : $this->wrap_style_value( (string) $prop_key, $raw_value );

            if ( null !== $wrapped ) {
                $wrapped_props[ $prop_key ] = $wrapped;
            }
        }

        $variants       = is_array( $styles[ $style_id ]['variants'] ?? null ) ? $styles[ $style_id ]['variants'] : [];
        $variant_index  = $this->find_variant_index( $variants, $meta );

        if ( null === $variant_index ) {
            $variants[] = [
                'meta'  => $meta,
                'props' => $wrapped_props,
            ];
        } else {
            $existing_props                    = is_array( $variants[ $variant_index ]['props'] ?? null ) ? $variants[ $variant_index ]['props'] : [];
            $variants[ $variant_index ]['props'] = array_merge( $existing_props, $wrapped_props );
            $variants[ $variant_index ]['meta']  = $meta;
        }

        $styles[ $style_id ]['variants'] = $variants;
        $element['styles']               = $styles;

        return $this->ensure_class_reference( $element, $style_id );
    }

    /**
     * Drop specific props from a variant, or the whole variant when
     * `$prop_keys` is null. Cleans up after itself: an emptied-out
     * variant is removed, and an emptied-out style definition also drops
     * its `classes` reference.
     *
     * @param array      $element   The raw Elementor element node.
     * @param array      $meta      Optional `[ 'breakpoint' => ..., 'state' => ... ]`,
     *                              same defaulting as `set_style()`.
     * @param array|null $prop_keys Prop keys to drop, or null to drop the
     *                              whole variant.
     * @return array The updated element.
     */
    public function remove_style( array $element, array $meta = [], ?array $prop_keys = null ): array {
        $meta     = $this->normalize_meta( $meta );
        $style_id = $this->get_local_style_id( $element );

        if ( ! isset( $element['styles'][ $style_id ] ) || ! is_array( $element['styles'][ $style_id ] ) ) {
            return $element;
        }

        $variants      = is_array( $element['styles'][ $style_id ]['variants'] ?? null ) ? $element['styles'][ $style_id ]['variants'] : [];
        $variant_index = $this->find_variant_index( $variants, $meta );

        if ( null === $variant_index ) {
            return $element;
        }

        if ( null === $prop_keys ) {
            array_splice( $variants, $variant_index, 1 );
        } else {
            $props = is_array( $variants[ $variant_index ]['props'] ?? null ) ? $variants[ $variant_index ]['props'] : [];

            foreach ( $prop_keys as $prop_key ) {
                unset( $props[ $prop_key ] );
            }

            if ( empty( $props ) ) {
                array_splice( $variants, $variant_index, 1 );
            } else {
                $variants[ $variant_index ]['props'] = $props;
            }
        }

        if ( empty( $variants ) ) {
            unset( $element['styles'][ $style_id ] );

            return $this->remove_class_reference( $element, $style_id );
        }

        $element['styles'][ $style_id ]['variants'] = array_values( $variants );

        return $element;
    }

    /**
     * Turn CLI-friendly input into the exact typed-envelope structure a
     * V4 style prop expects (`{'$$type':...,'value':...}`, nested as
     * needed for composite props).
     *
     * When Elementor's atomic-widgets classes are loaded, plain scalar
     * props (size/color/string/number kinds) are resolved dynamically
     * against the live `Style_Schema`, so support for new enum values or
     * new plain props never goes stale. A handful of composite props
     * (padding, margin, border-radius, border-width, gap, background,
     * flex) are handled explicitly, because building their nested shape
     * from a shorthand string needs prop-specific knowledge no amount of
     * introspection replaces.
     *
     * Returns null when the prop is unsupported (see
     * `describe_style_schema()['props'][$prop_key]['wrap_style_value_support']`)
     * or the raw input could not be honestly parsed - never a guess.
     *
     * @param string $prop_key The V4 style prop key (e.g. `'font-size'`).
     * @param mixed  $raw      CLI-friendly input: `"72px"`, `"50%"`, `"auto"`,
     *                         a bare number, a hex/rgb/keyword color string,
     *                         a CSS shorthand string (`"10px 20px"`), or an
     *                         already-typed envelope (returned unchanged).
     * @return mixed The typed envelope, or null if it could not be built.
     */
    public function wrap_style_value( string $prop_key, mixed $raw ): mixed {
        if ( $this->is_already_wrapped( $raw ) ) {
            return $raw;
        }

        switch ( $prop_key ) {
            case 'padding':
            case 'margin':
                return $this->wrap_box_shorthand(
                    $raw,
                    'dimensions',
                    [ 'block-start', 'inline-end', 'block-end', 'inline-start' ]
                );

            case 'border-width':
                return $this->wrap_box_shorthand(
                    $raw,
                    'border-width-v2',
                    [ 'block-start', 'inline-end', 'block-end', 'inline-start' ]
                );

            case 'border-radius':
                return $this->wrap_box_shorthand(
                    $raw,
                    'border-radius-v2',
                    [ 'start-start', 'start-end', 'end-end', 'end-start' ]
                );

            case 'gap':
                return $this->wrap_gap( $raw );

            case 'background':
                return $this->wrap_background( $raw );

            case 'flex':
                return $this->wrap_flex( $raw );
        }

        $prop_type = $this->resolve_concrete_prop_type( $this->resolve_prop_type( $prop_key ) );

        if ( $prop_type && class_exists( '\Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type' ) ) {
            if ( $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type ) {
                $settings     = method_exists( $prop_type, 'get_settings' ) ? $prop_type->get_settings() : [];
                $default_unit = $settings['default_unit'] ?? null;

                return [
                    '$$type' => 'size',
                    'value'  => $this->parse_size_token( $raw, $default_unit ),
                ];
            }

            if ( $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Number_Prop_Type ) {
                if ( ! is_numeric( $raw ) ) {
                    return null;
                }

                return [
                    '$$type' => 'number',
                    'value'  => $raw + 0,
                ];
            }

            if ( $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type ) {
                // Covers Color_Prop_Type, Font_Family_Prop_Type, and every
                // plain/enum String_Prop_Type key in the schema.
                return [
                    '$$type' => $prop_type::get_key(),
                    'value'  => (string) $raw,
                ];
            }

            // A composite/union prop this class does not build (box-shadow,
            // filter, backdrop-filter, transform, transition, stroke,
            // grid-template-*, object-position, ...). Refuse rather than guess.
            return null;
        }

        // Elementor not loaded: a small, defensive fallback so this class
        // still degrades gracefully instead of fataling.
        return $this->wrap_style_value_offline_fallback( $prop_key, $raw );
    }

    /**
     * Run a `styles` array through Elementor's own `Style_Parser` rather
     * than reimplementing validation - this is the gate that stops us
     * writing a document the V4 editor cannot open.
     *
     * @param array $styles An element's `styles` array (id => definition).
     * @return array [ 'ok' => bool, 'errors' => string[] ].
     */
    public function validate_styles( array $styles ): array {
        if (
            ! class_exists( '\Elementor\Modules\AtomicWidgets\Parsers\Style_Parser' ) ||
            ! class_exists( '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema' )
        ) {
            return [
                'ok'     => false,
                'errors' => [ 'Elementor atomic-widgets classes are not loaded; cannot validate.' ],
            ];
        }

        $parser = \Elementor\Modules\AtomicWidgets\Parsers\Style_Parser::make(
            \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get()
        );

        $errors = [];

        foreach ( $styles as $style_id => $style ) {
            if ( ! is_array( $style ) ) {
                $errors[] = "{$style_id}: not_an_array";
                continue;
            }

            $result = $parser->parse( $style );

            if ( ! $result->is_valid() ) {
                $errors[] = "{$style_id}: " . $result->errors()->to_string();
            }
        }

        return [
            'ok'     => empty( $errors ),
            'errors' => $errors,
        ];
    }

    /**
     * Best-effort mapping from a classic Elementor settings key
     * (`typography_font_size`) to its V4 style prop key (`font-size`).
     *
     * Every mapping here is sourced from a real classic control field -
     * see `CLASSIC_KEY_MAP`'s inline comments for exact file references.
     * Deliberately refused (returns null) rather than guessed:
     *
     * - Widget-specific color/typography fields (e.g. `title_color`,
     *   `text_color`): the field name is not shared across widgets, so
     *   there is no single classic key to map from.
     * - `align`: means text-align on some widgets, flex/icon alignment on
     *   others - ambiguous without per-widget knowledge this class does
     *   not have.
     * - `background_background`: a structural type toggle
     *   (classic/gradient/video), not a CSS value - no V4 style prop
     *   corresponds to it.
     * - `box_shadow_box_shadow` / `box_shadow_*`, `text_shadow_*`,
     *   `css_filters_*`: their V4 equivalents (`Box_Shadow_Prop_Type`,
     *   `Filter_Prop_Type`, ...) have nested shapes this class has not
     *   verified against the classic group-control field values closely
     *   enough to map honestly.
     *
     * @param string $classic_key The classic settings key, e.g. `'typography_font_size'`.
     * @return string|null The V4 style prop key, or null when there is no
     *                      verified equivalent.
     */
    public function classic_to_atomic_key( string $classic_key ): ?string {
        return self::CLASSIC_KEY_MAP[ $classic_key ] ?? null;
    }

    // -----------------------------------------------------------------
    // Local-style identity & class-reference bookkeeping.
    // -----------------------------------------------------------------

    /**
     * The deterministic id of this element's single local style class.
     *
     * Real Elementor code generates style ids randomly
     * (`Utils::generate_id( "e-{$element_id}-", $existing )`); this class
     * keeps the same prefix convention but makes the suffix deterministic
     * so that `set_style()`/`remove_style()` can find "the" local style
     * for an element with a plain array-key lookup instead of a search,
     * which is what makes repeated calls naturally idempotent.
     *
     * @param array $element The raw Elementor element node.
     * @return string The style id, e.g. `'e-a1b2c3d-local'`.
     */
    private function get_local_style_id( array $element ): string {
        $element_id = is_string( $element['id'] ?? null ) && '' !== $element['id'] ? $element['id'] : 'root';

        return "e-{$element_id}-local";
    }

    /**
     * Fill in defaults for a `$meta` argument: breakpoint defaults to
     * `desktop` (the base breakpoint), state defaults to null (no pseudo
     * state/class).
     *
     * @param array $meta Partial meta, e.g. `[ 'breakpoint' => 'mobile' ]`.
     * @return array Complete `[ 'breakpoint' => string, 'state' => string|null ]`.
     */
    private function normalize_meta( array $meta ): array {
        return [
            'breakpoint' => is_string( $meta['breakpoint'] ?? null ) && '' !== $meta['breakpoint']
                ? $meta['breakpoint']
                : self::DEFAULT_BREAKPOINT,
            'state' => array_key_exists( 'state', $meta ) && '' !== $meta['state'] ? $meta['state'] : null,
        ];
    }

    /**
     * Find the index of the variant matching a normalized `$meta` in a
     * variants list.
     *
     * @param array $variants A style definition's `variants` array.
     * @param array $meta     Normalized meta from `normalize_meta()`.
     * @return int|null The matching index, or null if none matches.
     */
    private function find_variant_index( array $variants, array $meta ): ?int {
        foreach ( $variants as $index => $variant ) {
            $variant_meta = is_array( $variant['meta'] ?? null ) ? $variant['meta'] : [];
            $breakpoint   = $variant_meta['breakpoint'] ?? self::DEFAULT_BREAKPOINT;
            $state        = $variant_meta['state'] ?? null;

            if ( $breakpoint === $meta['breakpoint'] && $state === $meta['state'] ) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Add a style id to the element's `settings.classes` prop if it is
     * not already referenced there. No-op (idempotent) if it already is.
     *
     * @param array  $element  The raw Elementor element node.
     * @param string $style_id The style id to reference.
     * @return array The updated element.
     */
    private function ensure_class_reference( array $element, string $style_id ): array {
        $settings    = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
        $classes_env = is_array( $settings['classes'] ?? null ) ? $settings['classes'] : [ '$$type' => 'classes', 'value' => [] ];
        $list        = is_array( $classes_env['value'] ?? null ) ? $classes_env['value'] : [];

        if ( ! in_array( $style_id, $list, true ) ) {
            $list[] = $style_id;
        }

        $settings['classes'] = [
            '$$type' => 'classes',
            'value'  => array_values( $list ),
        ];

        $element['settings'] = $settings;

        return $element;
    }

    /**
     * Remove a style id from the element's `settings.classes` prop.
     *
     * @param array  $element  The raw Elementor element node.
     * @param string $style_id The style id to drop.
     * @return array The updated element.
     */
    private function remove_class_reference( array $element, string $style_id ): array {
        $settings    = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
        $classes_env = is_array( $settings['classes'] ?? null ) ? $settings['classes'] : null;

        if ( null === $classes_env || ! is_array( $classes_env['value'] ?? null ) ) {
            return $element;
        }

        $list = array_values( array_filter(
            $classes_env['value'],
            fn( $id ) => $id !== $style_id
        ) );

        $settings['classes'] = [
            '$$type' => 'classes',
            'value'  => $list,
        ];

        $element['settings'] = $settings;

        return $element;
    }

    // -----------------------------------------------------------------
    // Schema introspection (describe_style_schema / validate_styles support).
    // -----------------------------------------------------------------

    /**
     * `Style_Schema::get()`, cached, or `[]` when Elementor's
     * atomic-widgets classes are not loaded.
     *
     * @return array<string, mixed>
     */
    private function get_prop_schema(): array {
        if ( null !== self::$schema_cache ) {
            return self::$schema_cache;
        }

        if ( class_exists( '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema' ) ) {
            self::$schema_cache = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
        } else {
            self::$schema_cache = [];
        }

        return self::$schema_cache;
    }

    /**
     * @param string $prop_key
     * @return mixed The live `Prop_Type` object for `$prop_key`, or null.
     */
    private function resolve_prop_type( string $prop_key ) {
        return $this->get_prop_schema()[ $prop_key ] ?? null;
    }

    /**
     * When Elementor's "Variables" (design tokens) module is active, it
     * hooks `elementor/atomic-widgets/styles/schema` and rewrites nearly
     * every `Size_Prop_Type`/`Color_Prop_Type`/font-family `String_Prop_Type`
     * entry into a `Union_Prop_Type` of the original type plus a
     * `global-*-variable` reference alternative (see
     * `modules/variables/classes/style-schema.php` and
     * `size-style-schema.php`, `->augment()`). A prop this class expected
     * to resolve as a plain `Size_Prop_Type` therefore actually resolves
     * as a `Union_Prop_Type` at runtime.
     *
     * This unwraps that: for a `Union_Prop_Type`, it returns the first
     * member whose own key does not start with `global-` (the original,
     * concrete member - `Style_Schema`'s augmenter always inserts it
     * before appending the `-variable` alternative, so this is never
     * ambiguous). Any other prop type is returned unchanged.
     *
     * @param mixed $prop_type
     * @return mixed The concrete (non-variable-reference) `Prop_Type`, or null.
     */
    private function resolve_concrete_prop_type( mixed $prop_type ): mixed {
        if ( null === $prop_type ) {
            return null;
        }

        if (
            class_exists( '\Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type' )
            && $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type
        ) {
            foreach ( $prop_type->get_prop_types() as $member_key => $member ) {
                if ( ! str_starts_with( (string) $member_key, 'global-' ) ) {
                    return $member;
                }
            }

            return null;
        }

        return $prop_type;
    }

    /**
     * @return array<string, array{direction: string, value: int, media_query: string|null}>
     */
    private function describe_breakpoints(): array {
        $breakpoints = [
            self::DEFAULT_BREAKPOINT => [
                'direction'   => null,
                'value'       => null,
                'media_query' => null,
            ],
        ];

        if ( class_exists( '\Elementor\Core\Breakpoints\Manager' ) ) {
            foreach ( \Elementor\Core\Breakpoints\Manager::get_default_config() as $key => $config ) {
                $breakpoints[ $key ] = [
                    'direction'   => $config['direction'],
                    'value'       => $config['default_value'],
                    'media_query' => '(' . ( 'min' === $config['direction'] ? 'min-width' : 'max-width' ) . ':' . $config['default_value'] . 'px)',
                ];
            }

            return $breakpoints;
        }

        // Offline fallback: Elementor\Core\Breakpoints\Manager::get_default_config() as of 4.2.4.
        return $breakpoints + [
            'mobile'        => [ 'direction' => 'max', 'value' => 767, 'media_query' => '(max-width:767px)' ],
            'mobile_extra'  => [ 'direction' => 'max', 'value' => 880, 'media_query' => '(max-width:880px)' ],
            'tablet'        => [ 'direction' => 'max', 'value' => 1024, 'media_query' => '(max-width:1024px)' ],
            'tablet_extra'  => [ 'direction' => 'max', 'value' => 1200, 'media_query' => '(max-width:1200px)' ],
            'laptop'        => [ 'direction' => 'max', 'value' => 1366, 'media_query' => '(max-width:1366px)' ],
            'widescreen'    => [ 'direction' => 'min', 'value' => 2400, 'media_query' => '(min-width:2400px)' ],
        ];
    }

    /**
     * @return array<int, string|null>
     */
    private function describe_states(): array {
        if ( class_exists( '\Elementor\Modules\AtomicWidgets\Styles\Style_States' ) ) {
            return \Elementor\Modules\AtomicWidgets\Styles\Style_States::get_valid_states();
        }

        // Offline fallback, mirrors Style_States::get_valid_states() as of 4.2.4.
        return [ 'hover', 'active', 'focus', 'checked', 'e--selected', 'e--disabled', null ];
    }

    /**
     * @return array<string, array{atomic_type: string|null, description: string,
     *               enum: string[]|null, units: string[]|null, union_members: string[]|null,
     *               wrap_style_value_support: bool}>
     */
    private function describe_props(): array {
        $schema = $this->get_prop_schema();
        $result = [];

        if ( empty( $schema ) ) {
            // Offline fallback: just report what wrap_style_value() supports.
            foreach ( self::SUPPORTED_WRAP_PROPS as $key ) {
                $result[ $key ] = [
                    'atomic_type'              => null,
                    'description'              => '',
                    'enum'                     => null,
                    'units'                    => null,
                    'union_members'            => null,
                    'wrap_style_value_support' => true,
                ];
            }

            return $result;
        }

        foreach ( $schema as $key => $prop_type ) {
            if ( ! is_object( $prop_type ) || ! method_exists( $prop_type, 'get_key' ) ) {
                continue;
            }

            $union_members = null;

            if ( class_exists( '\Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type' )
                && $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type
            ) {
                $union_members = array_keys( $prop_type->get_prop_types() );
            }

            // Describe the concrete member (see resolve_concrete_prop_type()'s
            // docblock): when Elementor's Variables module has wrapped this
            // prop in a Union to add a "global-*-variable" alternative, an
            // agent needs the *original* type's enum/units, not the union's.
            $described = $this->resolve_concrete_prop_type( $prop_type ) ?? $prop_type;
            $meta      = method_exists( $described, 'get_meta' ) ? $described->get_meta() : [];
            $settings  = method_exists( $described, 'get_settings' ) ? $described->get_settings() : [];

            $result[ $key ] = [
                'atomic_type'              => $prop_type::get_key(),
                'description'              => $meta['description'] ?? '',
                'enum'                     => $settings['enum'] ?? null,
                'units'                    => $settings['available_units'] ?? null,
                'union_members'            => $union_members,
                'wrap_style_value_support' => in_array( $key, self::SUPPORTED_WRAP_PROPS, true ),
            ];
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Value wrapping.
    // -----------------------------------------------------------------

    /**
     * @param mixed $value
     */
    private function is_already_wrapped( mixed $value ): bool {
        return is_array( $value )
            && array_key_exists( '$$type', $value )
            && array_key_exists( 'value', $value );
    }

    /**
     * Parse a single CSS size token ("72px", "50%", "auto", a bare
     * number, or an unrecognized string treated as a CSS `custom` value
     * such as `calc(100% - 20px)`) into the shape `Size_Prop_Type` wants:
     * `[ 'size' => number|string|null, 'unit' => string ]`.
     *
     * @param mixed       $token        Raw CLI input.
     * @param string|null $default_unit Unit to apply to a bare number. Falls
     *                                  back to `px` (`Size_Constants::DEFAULT_UNIT`).
     * @return array{size: mixed, unit: string}
     */
    private function parse_size_token( mixed $token, ?string $default_unit = null ): array {
        $default_unit = $default_unit ?: 'px';

        if ( is_array( $token ) && array_key_exists( 'size', $token ) && array_key_exists( 'unit', $token ) ) {
            return $token;
        }

        if ( is_int( $token ) || is_float( $token ) ) {
            return [ 'size' => $token, 'unit' => $default_unit ];
        }

        $token = trim( (string) $token );

        if ( '' === $token ) {
            return [ 'size' => null, 'unit' => 'auto' ];
        }

        if ( 0 === strcasecmp( $token, 'auto' ) ) {
            return [ 'size' => null, 'unit' => 'auto' ];
        }

        if ( is_numeric( $token ) ) {
            return [ 'size' => $token + 0, 'unit' => $default_unit ];
        }

        if ( preg_match( '/^(-?[0-9]*\.?[0-9]+)(px|%|em|rem|vw|vh|ch|vmin|vmax|deg|rad|grad|turn|s|ms|fr)$/i', $token, $m ) ) {
            return [ 'size' => $m[1] + 0, 'unit' => strtolower( $m[2] ) ];
        }

        // Not a recognizable "<number><unit>" - treat as a raw CSS custom value.
        return [ 'size' => $token, 'unit' => 'custom' ];
    }

    /**
     * Expand 1-4 CSS shorthand tokens into 4, following the standard CSS
     * box-shorthand expansion rules (used by margin/padding/border-width/
     * border-radius alike - only the target side/corner names differ):
     * 1 token -> all four; 2 -> (1st,2nd,1st,2nd); 3 -> (1st,2nd,3rd,2nd);
     * 4 -> as given.
     *
     * @param array $tokens 1-4 raw tokens.
     * @return array Exactly 4 tokens.
     */
    private function expand_shorthand_4( array $tokens ): array {
        $tokens = array_values( $tokens );

        return match ( count( $tokens ) ) {
            1 => [ $tokens[0], $tokens[0], $tokens[0], $tokens[0] ],
            2 => [ $tokens[0], $tokens[1], $tokens[0], $tokens[1] ],
            3 => [ $tokens[0], $tokens[1], $tokens[2], $tokens[1] ],
            default => array_slice( $tokens, 0, 4 ),
        };
    }

    /**
     * Build a 4-side/corner composite prop value (padding, margin,
     * border-width, border-radius) from CSS-shorthand-ish CLI input.
     *
     * Accepts:
     *  - a single scalar/string ("10px") -> all four sides that value;
     *  - a CSS shorthand string with 2-4 space-separated tokens
     *    ("10px 20px", "10px 20px 5px 15px", in CSS T R B L / TL TR BR BL order);
     *  - an assoc array using physical names (`top`,`right`,`bottom`,`left`
     *    for padding/margin/border-width, or `top-left`, `top-right`,
     *    `bottom-right`, `bottom-left` for border-radius);
     *  - an assoc array already using the target logical side/corner keys.
     *
     * @param mixed    $raw               CLI input.
     * @param string   $type_key          The composite prop's own `$$type`
     *                                    ('dimensions', 'border-width-v2', 'border-radius-v2').
     * @param string[] $ordered_side_keys The 4 logical keys, in the same
     *                                    order as the CSS shorthand (T,R,B,L
     *                                    for padding/margin/border-width;
     *                                    TL,TR,BR,BL for border-radius).
     * @return array|null
     */
    private function wrap_box_shorthand( mixed $raw, string $type_key, array $ordered_side_keys ): ?array {
        $physical_order = [ 'top', 'right', 'bottom', 'left' ];
        $corner_order   = [ 'top-left', 'top-right', 'bottom-right', 'bottom-left' ];

        if ( is_array( $raw ) ) {
            // Already using the logical keys this prop expects.
            if ( array_key_exists( $ordered_side_keys[0], $raw ) ) {
                $tokens = array_map( fn( $key ) => $raw[ $key ] ?? '0px', $ordered_side_keys );
            } elseif ( array_key_exists( 'top', $raw ) ) {
                $tokens = array_map( fn( $key ) => $raw[ $key ] ?? '0px', $physical_order );
            } elseif ( array_key_exists( 'top-left', $raw ) ) {
                $tokens = array_map( fn( $key ) => $raw[ $key ] ?? '0px', $corner_order );
            } else {
                $tokens = $this->expand_shorthand_4( array_values( $raw ) );
            }
        } else {
            $parts  = preg_split( '/\s+/', trim( (string) $raw ) );
            $tokens = $this->expand_shorthand_4( $parts );
        }

        $value = [];

        foreach ( $ordered_side_keys as $index => $side_key ) {
            $value[ $side_key ] = [
                '$$type' => 'size',
                'value'  => $this->parse_size_token( $tokens[ $index ] ?? '0px' ),
            ];
        }

        return [ '$$type' => $type_key, 'value' => $value ];
    }

    /**
     * Build a `gap` value: a single scalar wraps as the plain `size`
     * union member (applies to both axes); a two-part value ("row column"
     * string, or `['row' => ..., 'column' => ...]`) wraps as the
     * `layout-direction` union member.
     *
     * @param mixed $raw
     * @return array|null
     */
    private function wrap_gap( mixed $raw ): ?array {
        if ( is_array( $raw ) && ( array_key_exists( 'row', $raw ) || array_key_exists( 'column', $raw ) ) ) {
            return [
                '$$type' => 'layout-direction',
                'value'  => [
                    'row'    => [ '$$type' => 'size', 'value' => $this->parse_size_token( $raw['row'] ?? '0px' ) ],
                    'column' => [ '$$type' => 'size', 'value' => $this->parse_size_token( $raw['column'] ?? '0px' ) ],
                ],
            ];
        }

        if ( is_string( $raw ) && preg_match( '/\s+/', trim( $raw ) ) ) {
            [ $row, $column ] = preg_split( '/\s+/', trim( $raw ), 2 );

            return [
                '$$type' => 'layout-direction',
                'value'  => [
                    'row'    => [ '$$type' => 'size', 'value' => $this->parse_size_token( $row ) ],
                    'column' => [ '$$type' => 'size', 'value' => $this->parse_size_token( $column ) ],
                ],
            ];
        }

        return [ '$$type' => 'size', 'value' => $this->parse_size_token( $raw ) ];
    }

    /**
     * Build a `background` value. Only the solid-fill case is supported:
     * a plain color string (hex/rgb/hsl/keyword) becomes
     * `background.color`, matching `Background_Prop_Type`'s own top-level
     * `color` shape field. Layered backgrounds (image/gradient overlays)
     * are out of scope - pass an already-wrapped envelope for those.
     *
     * @param mixed $raw
     * @return array|null
     */
    private function wrap_background( mixed $raw ): ?array {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return null;
        }

        return [
            '$$type' => 'background',
            'value'  => [
                'color' => [ '$$type' => 'color', 'value' => $raw ],
            ],
        ];
    }

    /**
     * Build a `flex` value from a "<grow> <shrink> <basis>" shorthand
     * string or `['grow' => ..., 'shrink' => ..., 'basis' => ...]`,
     * matching `Flex_Prop_Type::define_shape()` (`flexGrow`, `flexShrink`,
     * `flexBasis`).
     *
     * @param mixed $raw
     * @return array|null
     */
    private function wrap_flex( mixed $raw ): ?array {
        if ( is_array( $raw ) ) {
            $grow   = $raw['grow'] ?? 0;
            $shrink = $raw['shrink'] ?? 1;
            $basis  = $raw['basis'] ?? 'auto';
        } else {
            $parts  = preg_split( '/\s+/', trim( (string) $raw ) );
            $grow   = $parts[0] ?? 0;
            $shrink = $parts[1] ?? 1;
            $basis  = $parts[2] ?? 'auto';
        }

        if ( ! is_numeric( $grow ) || ! is_numeric( $shrink ) ) {
            return null;
        }

        return [
            '$$type' => 'flex',
            'value'  => [
                'flexGrow'   => [ '$$type' => 'number', 'value' => $grow + 0 ],
                'flexShrink' => [ '$$type' => 'number', 'value' => $shrink + 0 ],
                'flexBasis'  => [ '$$type' => 'size', 'value' => $this->parse_size_token( $basis ) ],
            ],
        ];
    }

    /**
     * Small, curated fallback used only when Elementor's atomic-widgets
     * classes are not loaded, so this class degrades instead of fataling.
     * Not exhaustive by design - covers the highest-traffic plain props.
     *
     * @param string $prop_key
     * @param mixed  $raw
     * @return array|null
     */
    private function wrap_style_value_offline_fallback( string $prop_key, mixed $raw ): ?array {
        $size_keys = [
            'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'inset-block-start', 'inset-inline-end', 'inset-block-end', 'inset-inline-start',
            'font-size', 'letter-spacing', 'word-spacing', 'line-height',
            'opacity', 'outline-width', 'outline-offset', 'scroll-margin-top',
        ];
        $color_keys = [ 'color', 'border-color', 'outline-color' ];
        $number_keys = [ 'z-index', 'column-count', 'order' ];
        $string_keys = [
            'overflow', 'object-fit', 'position', 'display', 'flex-direction', 'flex-wrap',
            'text-align', 'font-style', 'text-decoration', 'text-transform', 'direction',
            'border-style', 'outline-style', 'justify-content', 'justify-items',
            'align-content', 'align-items', 'align-self', 'mix-blend-mode', 'appearance',
            'cursor', 'all', 'grid-auto-flow', 'content', 'clip-path', 'aspect-ratio',
        ];

        if ( in_array( $prop_key, $size_keys, true ) ) {
            return [ '$$type' => 'size', 'value' => $this->parse_size_token( $raw ) ];
        }

        if ( in_array( $prop_key, $color_keys, true ) ) {
            return [ '$$type' => 'color', 'value' => (string) $raw ];
        }

        if ( 'font-family' === $prop_key ) {
            return [ '$$type' => 'font-family', 'value' => (string) $raw ];
        }

        if ( in_array( $prop_key, $number_keys, true ) ) {
            return is_numeric( $raw ) ? [ '$$type' => 'number', 'value' => $raw + 0 ] : null;
        }

        if ( in_array( $prop_key, $string_keys, true ) ) {
            return [ '$$type' => 'string', 'value' => (string) $raw ];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Readable output helpers (get_styles()).
    // -----------------------------------------------------------------

    /**
     * Flatten a variant's typed-envelope props into a human-readable
     * `prop => value` map, for display only. The raw envelopes remain
     * available under `props` in `get_styles()`'s output - this is purely
     * a convenience view, never used for writes.
     *
     * @param array $props Raw typed-envelope props.
     * @return array<string, mixed>
     */
    private function readable_props( array $props ): array {
        $readable = [];

        foreach ( $props as $key => $value ) {
            $readable[ $key ] = $this->readable_value( $value );
        }

        return $readable;
    }

    /**
     * @param mixed $value A typed-envelope value (or already-plain value).
     * @return mixed
     */
    private function readable_value( mixed $value ): mixed {
        if ( ! $this->is_already_wrapped( $value ) ) {
            return $value;
        }

        $type    = $value['$$type'];
        $payload = $value['value'];

        switch ( $type ) {
            case 'size':
                if ( ! is_array( $payload ) ) {
                    return $payload;
                }

                if ( 'auto' === ( $payload['unit'] ?? null ) ) {
                    return 'auto';
                }

                return ( $payload['size'] ?? '' ) . ( $payload['unit'] ?? '' );

            case 'dimensions':
            case 'border-radius-v2':
            case 'border-width-v2':
            case 'layout-direction':
            case 'flex':
            case 'background':
                if ( ! is_array( $payload ) ) {
                    return $payload;
                }

                return array_map( fn( $side_value ) => $this->readable_value( $side_value ), $payload );

            default:
                return $payload;
        }
    }
}
