<?php
/**
 * WPX Elementor Save Orchestrator
 *
 * Handles the safe persistence of Elementor data modifications.
 *
 * Every write follows the same shape:
 *
 *   guard → snapshot → record intent (pending) → mutate → persist → complete
 *
 * The order matters. The operation is recorded with a full pre-write snapshot
 * *before* the site is touched, so that a fatal error partway through leaves a
 * row that still says what the page looked like beforehand. A write that
 * cannot be recorded is refused outright rather than applied unrecoverably.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Elementor_Save {

    /**
     * @var WPX_Elementor_Bridge
     */
    private WPX_Elementor_Bridge $bridge;

    /**
     * @var WPX_Atomic_Bridge|null
     */
    private ?WPX_Atomic_Bridge $atomic = null;

    /**
     * @var WPX_Atomic_Styles|null
     */
    private ?WPX_Atomic_Styles $atomic_styles = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->bridge = new WPX_Elementor_Bridge();
    }

    /**
     * The atomic (V4) document bridge, built on first use.
     *
     * @return WPX_Atomic_Bridge
     */
    private function atomic(): WPX_Atomic_Bridge {
        if ( null === $this->atomic ) {
            $this->atomic = new WPX_Atomic_Bridge();
        }
        return $this->atomic;
    }

    /**
     * The atomic (V4) style manager, built on first use.
     *
     * @return WPX_Atomic_Styles
     */
    private function atomic_styles(): WPX_Atomic_Styles {
        if ( null === $this->atomic_styles ) {
            $this->atomic_styles = new WPX_Atomic_Styles();
        }
        return $this->atomic_styles;
    }

    /**
     * Whether a post uses the V4 (atomic) element model.
     *
     * @param int $post_id The post ID.
     * @return bool True for an atomic document.
     */
    private function is_atomic( int $post_id ): bool {
        return 'atomic' === WPX_Elementor_Compat::document_model( $post_id );
    }

    /**
     * The bridge that speaks this document's model.
     *
     * Both bridges expose the same structural API — find, move, delete,
     * collect ids — so structural operations need no further branching.
     *
     * @param int $post_id The post ID.
     * @return WPX_Elementor_Bridge|WPX_Atomic_Bridge
     */
    private function bridge_for( int $post_id ) {
        return $this->is_atomic( $post_id ) ? $this->atomic() : $this->bridge;
    }

    /**
     * Replace one element node in a tree, in place.
     *
     * Integration glue: the classic bridge merges settings for us, but the
     * atomic paths build a whole replacement node (new props, or new styles)
     * and just need it put back where it came from.
     *
     * @param array  $elements   The elements data, by reference.
     * @param string $element_id The element to replace.
     * @param array  $node       The replacement node.
     * @return bool True when the element was found and replaced.
     */
    private function replace_node( array &$elements, string $element_id, array $node ): bool {
        foreach ( $elements as $index => $element ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                $elements[ $index ] = $node;
                return true;
            }

            if ( ! empty( $element['elements'] ) ) {
                $children = $element['elements'];
                if ( $this->replace_node( $children, $element_id, $node ) ) {
                    $elements[ $index ]['elements'] = $children;
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Refuse an operation that only the classic model supports.
     *
     * @param int    $post_id   The post ID.
     * @param string $operation Human-readable operation name.
     * @return array|null An error result, or null when the write may proceed.
     */
    private function guard_classic_only( int $post_id, string $operation ): ?array {
        if ( $this->is_atomic( $post_id ) ) {
            return $this->error(
                sprintf(
                    'Post %d uses the Elementor V4 (atomic) element model, which wpx cannot %s yet. ' .
                    'Reading, setting props, styling, deleting and moving are supported on V4 pages.',
                    $post_id,
                    $operation
                )
            );
        }

        return null;
    }

    /**
     * Update an element's settings and save the document.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID.
     * @param array  $changes    Settings to merge.
     * @param bool   $dry_run    If true, return diff without saving.
     * @return array Result with 'success', 'diff', 'operation_id' keys.
     */
    public function update_element(
        int $post_id,
        string $element_id,
        array $changes,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        if ( $this->is_atomic( $post_id ) ) {
            return $this->update_atomic_element( $post_id, $element_id, $changes, $dry_run );
        }

        $elements = $this->bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $before_element = $this->bridge->find_element( $post_id, $element_id );
        if ( null === $before_element ) {
            return $this->error( "Element '{$element_id}' not found." );
        }

        $before_settings = $before_element['settings'] ?? [];

        // Warn about any key not found in this element's own control schema,
        // *before* resolve_toggles() below adds its own (legitimate) toggle
        // keys to $changes - those aren't part of what the caller wrote and
        // must not be flagged as unknown themselves.
        $unknown_warnings = $this->unknown_setting_warnings(
            $changes,
            $before_element['elType'] ?? 'widget',
            $before_element['widgetType'] ?? null
        );

        // Add whatever group-control toggles these changes need in order to
        // render. Without them Elementor stores the value and ignores it: a
        // typography_font_size with no typography_typography produces no CSS
        // at all, which is silent and extremely confusing.
        $toggled = $this->resolve_toggles( $changes, $before_element );
        $changes = $toggled['changes'];
        $warnings = array_merge( $toggled['warnings'], $unknown_warnings );

        $modified = $elements;
        if ( ! $this->bridge->update_element_settings( $modified, $element_id, $changes ) ) {
            return $this->error( "Failed to update element '{$element_id}'." );
        }

        $diff = $this->calculate_diff( $before_settings, $changes );

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'      => $post_id,
                'element_id'   => $element_id,
                'element_type' => $before_element['elType'] ?? 'unknown',
                'widget_type'  => $before_element['widgetType'] ?? null,
                'diff'         => $diff,
                'toggles'      => $toggled['toggles'],
                'warnings'     => $warnings,
            ] );
        }

        return $this->apply(
            $post_id,
            $modified,
            [
                'command'    => "elementor set {$post_id} {$element_id}",
                'element_id' => $element_id,
                'after'      => $this->bridge->merge_settings( $before_settings, $changes ),
            ],
            [
                'element_id'   => $element_id,
                'element_type' => $before_element['elType'] ?? 'unknown',
                'widget_type'  => $before_element['widgetType'] ?? null,
                'diff'         => $diff,
                'toggles'      => $toggled['toggles'],
                'warnings'     => $warnings,
                'message'      => 'Changes applied successfully.',
            ]
        );
    }

    /**
     * Update responsive styles for an element.
     *
     * Desktop is the unsuffixed key; every other breakpoint appends
     * `_<breakpoint>`. Group-control toggles are resolved from the *base*
     * key, since the toggle itself is not a responsive control.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID.
     * @param array  $desktop    Desktop style changes.
     * @param array  $tablet     Tablet style changes.
     * @param array  $mobile     Mobile style changes.
     * @param bool   $dry_run    If true, return diff without saving.
     * @return array Result array.
     */
    public function update_responsive_styles(
        int $post_id,
        string $element_id,
        array $desktop = [],
        array $tablet = [],
        array $mobile = [],
        bool $dry_run = false,
        bool $force = false
    ): array {
        $by_breakpoint = [
            'desktop' => $desktop,
            'tablet'  => $tablet,
            'mobile'  => $mobile,
        ];

        if ( $this->is_atomic( $post_id ) ) {
            return $this->update_atomic_styles( $post_id, $element_id, $by_breakpoint, $dry_run, $force );
        }

        $changes = [];

        foreach ( $by_breakpoint as $breakpoint => $values ) {
            foreach ( $values as $key => $value ) {
                $target             = WPX_Elementor_Controls::responsive_key( $key, $breakpoint );
                $changes[ $target ] = $this->parse_style_value( $key, $value );
            }
        }

        if ( empty( $changes ) ) {
            return $this->error( 'No style changes provided.' );
        }

        return $this->update_element( $post_id, $element_id, $changes, $dry_run, $force );
    }

    /**
     * Delete an element from the page.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID to delete.
     * @param bool   $dry_run    If true, show what would be deleted.
     * @return array Result array.
     */
    public function delete_element(
        int $post_id,
        string $element_id,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $bridge   = $this->bridge_for( $post_id );
        $elements = $bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $target = $bridge->find_element( $post_id, $element_id );
        if ( null === $target ) {
            return $this->error( "Element '{$element_id}' not found." );
        }

        $descendants = count( $bridge->collect_ids( $target['elements'] ?? [] ) );

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'      => $post_id,
                'element_id'   => $element_id,
                'element_type' => $target['elType'] ?? 'unknown',
                'widget_type'  => $target['widgetType'] ?? null,
                'descendants'  => $descendants,
                'message'      => "Would delete element '{$element_id}' and {$descendants} descendant(s).",
            ] );
        }

        $modified = $elements;
        if ( null === $bridge->delete_element( $modified, $element_id ) ) {
            return $this->error( "Failed to delete element '{$element_id}'." );
        }

        return $this->apply(
            $post_id,
            $modified,
            [
                'command'    => "elementor delete {$post_id} {$element_id}",
                'element_id' => $element_id,
                'after'      => null,
            ],
            [
                'element_id' => $element_id,
                'message'    => "Element '{$element_id}' deleted successfully.",
            ]
        );
    }

    /**
     * Add a new widget to the page.
     *
     * @param int         $post_id     The post ID.
     * @param string      $widget_type Widget type (e.g., 'heading', 'button').
     * @param array       $settings    Widget settings.
     * @param string|null $parent_id   Parent element ID (null for root).
     * @param string|null $after_id    Insert after this element.
     * @param int|null    $position    Position index.
     * @param bool        $dry_run     If true, show what would be added.
     * @return array Result array.
     */
    public function add_widget(
        int $post_id,
        string $widget_type,
        array $settings = [],
        ?string $parent_id = null,
        ?string $after_id = null,
        ?int $position = null,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $elements = $this->bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $classic = $this->guard_classic_only( $post_id, 'add widgets to' );
        if ( null !== $classic ) {
            return $classic;
        }

        $type_check = $this->validate_widget_type( $widget_type );
        if ( null !== $type_check['error'] ) {
            return $this->error( $type_check['error'] );
        }

        $new_widget = $this->bridge->create_widget( $widget_type, $settings );

        // Same check update_element() makes, against the settings the caller
        // passed rather than the ones resolve_toggles() derives below.
        $unknown_warnings = $this->unknown_setting_warnings( $settings, 'widget', $widget_type );

        $toggled                  = $this->resolve_toggles( $settings, $new_widget );
        $new_widget['settings']   = $toggled['changes'];
        $warnings                 = array_merge( $type_check['warnings'], $toggled['warnings'], $unknown_warnings );

        // Rehearse the insertion so a dry run reports the truth rather than an
        // optimistic guess. A widget parent, or a missing parent, fails here.
        $rehearsal = $elements;
        $would_add = $this->bridge->add_element( $rehearsal, $parent_id, $new_widget, $position, $after_id );

        if ( ! $would_add ) {
            return $this->error(
                sprintf(
                    "Cannot add widget: parent '%s' is missing or cannot hold children.",
                    $parent_id ?? 'root'
                )
            );
        }

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'     => $post_id,
                'widget_type' => $widget_type,
                'new_id'      => $new_widget['id'],
                'parent_id'   => $parent_id ?? 'root',
                'settings'    => $new_widget['settings'],
                'toggles'     => $toggled['toggles'],
                'warnings'    => $warnings,
                'message'     => "Would add '{$widget_type}' widget.",
            ] );
        }

        return $this->apply(
            $post_id,
            $rehearsal,
            [
                'command'    => "elementor add-widget {$post_id} --type {$widget_type}",
                'element_id' => $new_widget['id'],
                'after'      => $new_widget,
            ],
            [
                'element_id'  => $new_widget['id'],
                'widget_type' => $widget_type,
                'toggles'     => $toggled['toggles'],
                'warnings'    => $warnings,
                'message'     => "Widget '{$widget_type}' added with ID '{$new_widget['id']}'.",
            ]
        );
    }

    /**
     * Move an element to a new position.
     *
     * @param int         $post_id    The post ID.
     * @param string      $element_id Element to move.
     * @param string|null $new_parent New parent ID.
     * @param int|null    $position   Position in new parent.
     * @param bool        $dry_run    If true, show what would change.
     * @return array Result array.
     */
    public function move_element(
        int $post_id,
        string $element_id,
        ?string $new_parent,
        ?int $position = null,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $bridge   = $this->bridge_for( $post_id );
        $elements = $bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        // Validate before reporting anything. A dry run that green-lights an
        // impossible move is worse than no dry run at all, because the agent
        // is told the operation is safe and then applies it for real.
        $validation = $bridge->validate_move( $elements, $element_id, $new_parent );
        if ( ! $validation['ok'] ) {
            return $this->error( $validation['reason'] );
        }

        $origin = $bridge->find_parent_and_index( $elements, $element_id );

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'     => $post_id,
                'element_id'  => $element_id,
                'from_parent' => $origin['parent_id'] ?? 'root',
                'from_index'  => $origin['index'] ?? null,
                'new_parent'  => $new_parent ?? 'root',
                'position'    => $position,
                'message'     => sprintf(
                    "Would move '%s' from %s to %s at position %s.",
                    $element_id,
                    $origin['parent_id'] ?? 'root',
                    $new_parent ?? 'root',
                    null === $position ? 'end' : (string) $position
                ),
            ] );
        }

        $modified = $elements;
        $result   = $bridge->move_element_with_reason( $modified, $element_id, $new_parent, $position );

        if ( ! $result['ok'] ) {
            return $this->error( $result['reason'] );
        }

        return $this->apply(
            $post_id,
            $modified,
            [
                'command'    => "elementor move {$post_id} {$element_id} --into " . ( $new_parent ?? 'root' ),
                'element_id' => $element_id,
                'after'      => null,
            ],
            [
                'element_id' => $element_id,
                'message'    => "Element '{$element_id}' moved successfully.",
            ]
        );
    }

    /**
     * Undo an operation by restoring its pre-write snapshot.
     *
     * There is deliberately no per-command undo logic here. Replaying an
     * inverse operation cannot express where a deleted element used to sit,
     * cannot remove settings keys that did not exist before the change, and
     * gets the ordering wrong on moves. Restoring the whole document as it
     * was is correct for every command by construction.
     *
     * @param string $operation_id The operation ID to undo.
     * @return array Result array.
     */
    public function undo( string $operation_id ): array {
        $operation = WPX_Operation_History::get( $operation_id );

        if ( null === $operation ) {
            return $this->error( "Operation '{$operation_id}' not found." );
        }

        if ( 'undone' === $operation->status ) {
            return $this->error( "Operation '{$operation_id}' has already been undone." );
        }

        $snapshot = WPX_Operation_History::get_snapshot( $operation_id );

        if ( null === $snapshot || '' === $snapshot ) {
            return $this->error(
                "Operation '{$operation_id}' has no snapshot and cannot be undone. " .
                'It predates snapshot-based history; restore from a WordPress revision instead.'
            );
        }

        $post_id = (int) $operation->post_id;

        if ( 'kit' === $operation->target_type ) {
            $settings = json_decode( $snapshot, true );
            if ( ! is_array( $settings ) ) {
                return $this->error( "Snapshot for '{$operation_id}' is not valid kit settings." );
            }

            update_post_meta( $post_id, '_elementor_page_settings', $settings );
            WPX_Elementor_Compat::regenerate_kit_css( $post_id );
            WPX_Operation_History::mark_undone( $operation_id );

            return [
                'success'      => true,
                'operation_id' => $operation_id,
                'command'      => $operation->command,
                'message'      => "Operation '{$operation_id}' undone; kit settings restored.",
            ];
        }

        $elements = $this->decode_snapshot( $snapshot );
        if ( null === $elements ) {
            return $this->error( "Snapshot for '{$operation_id}' is not valid Elementor data." );
        }

        try {
            $this->persist( $post_id, $elements );
        } catch ( \Throwable $e ) {
            return $this->error( 'Undo failed: ' . $this->diagnose_write_error( $e ) );
        }

        WPX_Operation_History::mark_undone( $operation_id );

        return [
            'success'      => true,
            'operation_id' => $operation_id,
            'command'      => $operation->command,
            'post_id'      => $post_id,
            'message'      => "Operation '{$operation_id}' undone; page restored to its pre-write state.",
        ];
    }

    /**
     * Duplicate an element and everything under it.
     *
     * The copy lands as a sibling immediately after the original unless a
     * position is given, and every id in the copied subtree is regenerated so
     * the page never carries two elements with the same id.
     *
     * @param int      $post_id    The post ID.
     * @param string   $element_id The element to duplicate.
     * @param int|null $position   Position among the siblings; null appends after the original.
     * @param bool     $dry_run    If true, report without saving.
     * @param bool     $force      Write even when the page is locked in the editor.
     * @return array Result array.
     */
    public function duplicate_element(
        int $post_id,
        string $element_id,
        ?int $position = null,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $elements = $this->bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $classic = $this->guard_classic_only( $post_id, 'duplicate elements on' );
        if ( null !== $classic ) {
            return $classic;
        }

        // Rehearse on a copy so a dry run reports what would really happen
        // rather than an optimistic guess.
        $rehearsal = $elements;
        $copy      = $this->bridge->duplicate_element( $rehearsal, $element_id, $position );

        if ( null === $copy ) {
            return $this->error( "Element '{$element_id}' not found." );
        }

        $descendants = count( $this->bridge->collect_ids( $copy['elements'] ?? [] ) );

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'           => $post_id,
                'source_element_id' => $element_id,
                'new_element_id'    => $copy['id'],
                'element_id'        => $copy['id'],
                'descendants'       => $descendants,
                'message'           => "Would duplicate '{$element_id}' and {$descendants} descendant(s).",
            ] );
        }

        return $this->apply(
            $post_id,
            $rehearsal,
            [
                'command'    => "elementor duplicate {$post_id} {$element_id}",
                'element_id' => $copy['id'],
                'after'      => $copy,
            ],
            [
                'source_element_id' => $element_id,
                'new_element_id'    => $copy['id'],
                'element_id'        => $copy['id'],
                'descendants'       => $descendants,
                'message'           => "Element '{$element_id}' duplicated as '{$copy['id']}'.",
            ]
        );
    }

    /**
     * Create a container on the page.
     *
     * @param int         $post_id   The post ID.
     * @param string|null $parent_id Parent element ID; null or 'root' for the page root.
     * @param array       $layout    Layout options (type, direction, gap, justify, align, columns, responsive).
     * @param array       $settings  Raw settings merged in last; can override anything $layout produced.
     * @param int|null    $position  Position within the parent.
     * @param string|null $after_id  Insert after this element instead.
     * @param bool        $dry_run   If true, report without saving.
     * @param bool        $force     Write even when the page is locked in the editor.
     * @return array Result array.
     */
    public function create_container(
        int $post_id,
        ?string $parent_id = null,
        array $layout = [],
        array $settings = [],
        ?int $position = null,
        ?string $after_id = null,
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $elements = $this->bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $classic = $this->guard_classic_only( $post_id, 'create containers on' );
        if ( null !== $classic ) {
            return $classic;
        }

        $is_inner  = null !== $parent_id && 'root' !== $parent_id;
        $direction = $layout['direction'] ?? 'column';
        $container = $this->bridge->create_container( $direction, $settings, $is_inner, $layout );

        $rehearsal = $elements;
        if ( ! $this->bridge->add_element( $rehearsal, $parent_id, $container, $position, $after_id ) ) {
            return $this->error(
                sprintf(
                    "Cannot create container: parent '%s' is missing or cannot hold children.",
                    $parent_id ?? 'root'
                )
            );
        }

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'      => $post_id,
                'container_id' => $container['id'],
                'element_id'   => $container['id'],
                'parent_id'    => $parent_id ?? 'root',
                'position'     => $position,
                'settings'     => $container['settings'],
                'message'      => 'Would create container.',
            ] );
        }

        return $this->apply(
            $post_id,
            $rehearsal,
            [
                'command'    => "elementor container create {$post_id}",
                'element_id' => $container['id'],
                'after'      => $container,
            ],
            [
                'container_id' => $container['id'],
                'element_id'   => $container['id'],
                'parent_id'    => $parent_id ?? 'root',
                'settings'     => $container['settings'],
                'message'      => "Container '{$container['id']}' created.",
            ]
        );
    }

    /**
     * Wrap a set of sibling elements in a new container.
     *
     * Done as one operation rather than a create followed by N moves, so a
     * failure partway through cannot leave the page half-rearranged.
     *
     * @param int      $post_id     The post ID.
     * @param string[] $element_ids The sibling elements to wrap, in any order.
     * @param array    $layout      Layout options for the new container.
     * @param array    $settings    Raw settings merged in last.
     * @param bool     $dry_run     If true, report without saving.
     * @param bool     $force       Write even when the page is locked in the editor.
     * @return array Result array.
     */
    public function wrap_in_container(
        int $post_id,
        array $element_ids,
        array $layout = [],
        array $settings = [],
        bool $dry_run = false,
        bool $force = false
    ): array {
        $guard = $this->guard( $post_id, $force );
        if ( null !== $guard ) {
            return $guard;
        }

        $classic = $this->guard_classic_only( $post_id, 'wrap elements on' );
        if ( null !== $classic ) {
            return $classic;
        }

        if ( count( $element_ids ) < 1 ) {
            return $this->error( 'No elements given to wrap.' );
        }

        $elements = $this->bridge->get_elements_data( $post_id );
        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        // Build the container's settings through create_container() so layout
        // options resolve to the same real control keys either route produces.
        $template = $this->bridge->create_container(
            $layout['direction'] ?? 'column',
            $settings,
            true,
            $layout
        );

        $rehearsal = $elements;
        $container = $this->bridge->wrap_in_container( $rehearsal, $element_ids, $template['settings'] );

        if ( null === $container ) {
            return $this->error(
                'Cannot wrap: the elements must all exist and share the same parent (' .
                implode( ', ', $element_ids ) . ').'
            );
        }

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'      => $post_id,
                'container_id' => $container['id'],
                'element_id'   => $container['id'],
                'elements'     => $element_ids,
                'settings'     => $container['settings'],
                'message'      => sprintf( 'Would wrap %d element(s) in a new container.', count( $element_ids ) ),
            ] );
        }

        return $this->apply(
            $post_id,
            $rehearsal,
            [
                'command'    => "elementor wrap {$post_id} " . implode( ',', $element_ids ),
                'element_id' => $container['id'],
                'after'      => $container,
            ],
            [
                'container_id' => $container['id'],
                'element_id'   => $container['id'],
                'elements'     => $element_ids,
                'settings'     => $container['settings'],
                'message'      => sprintf(
                    "Wrapped %d element(s) in container '%s'.",
                    count( $element_ids ),
                    $container['id']
                ),
            ]
        );
    }

    /**
     * Set props on a V4 (atomic) element.
     *
     * Two things differ from the classic path. Values arrive from the CLI as
     * plain scalars and have to be wrapped in the `{"$$type": ..., "value": ...}`
     * envelope the prop's own schema declares. And the result is run through
     * Elementor's own props parser before anything is written: an atomic
     * document that fails validation is one the V4 editor cannot open, so
     * refusing is the only safe answer.
     *
     * Group-control toggles have no analogue here — V4 has no popover gates.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID.
     * @param array  $changes    Prop changes, as plain values.
     * @param bool   $dry_run    If true, report without saving.
     * @return array Result array.
     */
    private function update_atomic_element(
        int $post_id,
        string $element_id,
        array $changes,
        bool $dry_run
    ): array {
        $atomic   = $this->atomic();
        $elements = $atomic->get_elements_data( $post_id );

        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $element = $atomic->find_element( $post_id, $element_id );
        if ( null === $element ) {
            return $this->error( "Element '{$element_id}' not found." );
        }

        $element_type = $element['elType'] ?? '';
        $widget_type  = $element['widgetType'] ?? null;
        $before       = $element['settings'] ?? [];

        $wrapped = [];
        foreach ( $changes as $key => $value ) {
            $wrapped[ $key ] = $atomic->wrap_prop( $element_type, $widget_type, $key, $value );
        }

        $merged = $atomic->merge_props( $before, $wrapped );

        $validation = $atomic->validate_props( $element_type, $widget_type, $merged );
        if ( ! $validation['ok'] ) {
            return $this->error(
                'Refusing to write: Elementor rejected these props — ' .
                implode( '; ', $validation['errors'] ) .
                '. Writing them would produce a document the V4 editor cannot open.'
            );
        }

        $diff = $this->calculate_diff(
            array_map( [ $atomic, 'unwrap_prop' ], $before ),
            array_map( [ $atomic, 'unwrap_prop' ], $wrapped )
        );

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'      => $post_id,
                'element_id'   => $element_id,
                'element_type' => $element_type,
                'widget_type'  => $widget_type,
                'model'        => 'atomic',
                'diff'         => $diff,
            ] );
        }

        $element['settings'] = $merged;

        $modified = $elements;
        if ( ! $this->replace_node( $modified, $element_id, $element ) ) {
            return $this->error( "Failed to update element '{$element_id}'." );
        }

        return $this->apply(
            $post_id,
            $modified,
            [
                'command'    => "elementor set {$post_id} {$element_id}",
                'element_id' => $element_id,
                'after'      => $merged,
            ],
            [
                'element_id'   => $element_id,
                'element_type' => $element_type,
                'widget_type'  => $widget_type,
                'model'        => 'atomic',
                'diff'         => $diff,
                'message'      => 'Changes applied successfully.',
            ]
        );
    }

    /**
     * Set styles on a V4 (atomic) element.
     *
     * V4 has no responsive key suffixes: a style is a class whose variants are
     * keyed by breakpoint and state. Classic keys an agent already knows are
     * translated where an honest equivalent exists, and refused where one does
     * not — a wrong guess here silently restyles somebody's page.
     *
     * @param int    $post_id       The post ID.
     * @param string $element_id    The element ID.
     * @param array  $by_breakpoint Style changes keyed by breakpoint name.
     * @param bool   $dry_run       If true, report without saving.
     * @param bool   $force         Unused here; kept for signature symmetry.
     * @return array Result array.
     */
    private function update_atomic_styles(
        int $post_id,
        string $element_id,
        array $by_breakpoint,
        bool $dry_run,
        bool $force
    ): array {
        $atomic   = $this->atomic();
        $styles   = $this->atomic_styles();
        $elements = $atomic->get_elements_data( $post_id );

        if ( null === $elements ) {
            return $this->error( 'Post is not built with Elementor.' );
        }

        $element = $atomic->find_element( $post_id, $element_id );
        if ( null === $element ) {
            return $this->error( "Element '{$element_id}' not found." );
        }

        $applied   = [];
        $unmapped  = [];
        $updated   = $element;

        foreach ( $by_breakpoint as $breakpoint => $values ) {
            if ( empty( $values ) ) {
                continue;
            }

            $props = [];

            foreach ( $values as $key => $value ) {
                $prop_key = $styles->classic_to_atomic_key( $key ) ?? $key;

                // A classic key with no V4 equivalent is reported, never guessed.
                if ( str_contains( $prop_key, '_' ) && null === $styles->classic_to_atomic_key( $key ) && $key === $prop_key ) {
                    $unmapped[] = $key;
                    continue;
                }

                $props[ $prop_key ] = $styles->wrap_style_value( $prop_key, $value );
                $applied[]          = "{$breakpoint}:{$prop_key}";
            }

            if ( empty( $props ) ) {
                continue;
            }

            $updated = $styles->set_style( $updated, $props, [ 'breakpoint' => $breakpoint ] );
        }

        if ( ! empty( $unmapped ) ) {
            return $this->error(
                'No V4 equivalent for: ' . implode( ', ', $unmapped ) .
                '. Pass the V4 prop name directly (see `wpx elementor style-schema`) rather than a classic key.'
            );
        }

        if ( empty( $applied ) ) {
            return $this->error( 'No style changes provided.' );
        }

        $validation = $styles->validate_styles( $updated['styles'] ?? [] );
        if ( ! $validation['ok'] ) {
            return $this->error(
                'Refusing to write: Elementor rejected these styles — ' .
                implode( '; ', $validation['errors'] ) . '.'
            );
        }

        if ( $dry_run ) {
            return $this->dry_run_result( [
                'post_id'    => $post_id,
                'element_id' => $element_id,
                'model'      => 'atomic',
                'styles'     => $applied,
                'message'    => 'No changes applied (dry run).',
            ] );
        }

        $modified = $elements;
        if ( ! $this->replace_node( $modified, $element_id, $updated ) ) {
            return $this->error( "Failed to update element '{$element_id}'." );
        }

        return $this->apply(
            $post_id,
            $modified,
            [
                'command'    => "elementor style {$post_id} {$element_id}",
                'element_id' => $element_id,
                'after'      => $updated['styles'] ?? [],
            ],
            [
                'element_id' => $element_id,
                'model'      => 'atomic',
                'styles'     => $applied,
                'message'    => 'Styles applied successfully.',
            ]
        );
    }

    /**
     * Refuse to write when the environment is not one we can write safely to.
     *
     * @param int $post_id The post ID.
     * @return array|null An error result, or null when the write may proceed.
     */
    private function guard( int $post_id, bool $force = false ): ?array {
        $supported = WPX_Elementor_Compat::assert_supported();
        if ( ! $supported['ok'] ) {
            return $this->error( $supported['reason'] );
        }

        // Refuse to write over a page somebody has open in the Elementor
        // editor. Their browser holds the whole document and will post it back
        // wholesale on save, silently discarding anything written underneath.
        if ( class_exists( 'WPX_Lock' ) ) {
            $lock     = new WPX_Lock();
            $writable = $lock->is_writable( $post_id, $force );
            if ( ! $writable['ok'] ) {
                return $this->error( $writable['reason'] . ' Pass --force to write anyway.' );
            }
        }

        // A mixed document has classic and atomic elements side by side, so
        // there is no single model to write in and picking either one silently
        // corrupts the other half. Atomic-only documents are handled natively.
        if ( 'mixed' === WPX_Elementor_Compat::document_model( $post_id ) ) {
            return $this->error(
                sprintf(
                    'Post %d mixes classic and V4 (atomic) elements. wpx will not write to a mixed document, ' .
                    'because either model\'s conventions would corrupt the other half.',
                    $post_id
                )
            );
        }

        return null;
    }

    /**
     * Validate a widget type against Elementor's own widget registry before
     * accepting it, so `add_widget()` refuses outright rather than silently
     * persisting a dead element the editor can never render — confirmed on
     * a live site: `--type=uydurmawidget` was accepted, written into the
     * page, and even `--dry-run` reported it as something that would be
     * added.
     *
     * Degrades to a warning, not a refusal, when the widgets manager isn't
     * available: Elementor being present but not fully booted is not proof
     * the type is bad, only that this check couldn't run.
     *
     * @param string $widget_type The widget type to validate.
     * @return array{error:string|null,warnings:string[]} 'error' is set
     *         (and the write must be refused) only when the registry was
     *         actually consulted and came back empty for this type.
     */
    private function validate_widget_type( string $widget_type ): array {
        if (
            ! class_exists( '\Elementor\Plugin' )
            || ! did_action( 'elementor/loaded' )
            || ! isset( \Elementor\Plugin::$instance )
            || ! isset( \Elementor\Plugin::$instance->widgets_manager )
        ) {
            return [
                'error'    => null,
                'warnings' => [
                    "Could not verify widget type '{$widget_type}' against Elementor's registry " .
                    '(the widgets manager was unavailable); it was written unverified.',
                ],
            ];
        }

        $registered = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );

        if ( null === $registered ) {
            return [
                'error'    => "Unknown widget type '{$widget_type}': it is not registered with Elementor and " .
                    'would be written as a dead element the editor cannot render.',
                'warnings' => [],
            ];
        }

        return [ 'error' => null, 'warnings' => [] ];
    }

    /**
     * Warn about settings keys that don't exist in the element's own
     * Elementor control schema, so a typo or a made-up key (e.g.
     * `uydurma_ayar`) is surfaced instead of silently accepted - confirmed
     * on a live site: such a write returned `success: true` with an empty
     * `warnings` array and showed up in the diff like any normal change.
     *
     * Deliberately conservative: WPX_Elementor_Controls::describe_element()
     * returns an empty schema whenever it can't resolve one (Elementor not
     * loaded, or - common on this project's install, full of lazily- or
     * never-registered `pxl_*` theme widgets - the widget type just isn't
     * resolvable in a WP-CLI context). When that happens this returns no
     * warnings at all rather than flagging every key: an unreliable warning
     * on every single write is worse than none.
     *
     * @param array       $changes      The settings keys the caller wrote.
     * @param string      $element_type 'widget', 'container', 'section', 'column', etc.
     * @param string|null $widget_type  Required alongside $element_type when it's 'widget'.
     * @return string[] Warning messages, one per unrecognized key.
     */
    private function unknown_setting_warnings( array $changes, string $element_type, ?string $widget_type ): array {
        if ( ! class_exists( 'WPX_Elementor_Controls' ) ) {
            return [];
        }

        $schema = WPX_Elementor_Controls::describe_element( $element_type, $widget_type );

        if ( empty( $schema ) ) {
            return [];
        }

        $warnings = [];

        foreach ( $changes as $key => $value ) {
            if ( ! array_key_exists( $key, $schema ) ) {
                $warnings[] = sprintf(
                    "Setting '%s' was not found in this element's control schema and may be ignored by Elementor.",
                    $key
                );
            }
        }

        return $warnings;
    }

    /**
     * Resolve the group-control toggles a set of changes needs.
     *
     * @param array $changes The settings being written.
     * @param array $element The element they are being written to.
     * @return array With 'changes', 'toggles' and 'warnings' keys.
     */
    private function resolve_toggles( array $changes, array $element ): array {
        if ( ! class_exists( 'WPX_Elementor_Controls' ) ) {
            return [ 'changes' => $changes, 'toggles' => [], 'warnings' => [] ];
        }

        $resolved = WPX_Elementor_Controls::apply_group_toggles_verbose(
            $changes,
            $element['settings'] ?? [],
            $element['elType'] ?? null,
            $element['widgetType'] ?? null
        );

        $warnings = [];
        foreach ( $resolved['toggles'] as $key => $info ) {
            if ( 'heuristic' === ( $info['source'] ?? '' ) ) {
                $warnings[] = sprintf(
                    "Toggle '%s' was inferred from the key name, not read from Elementor's control schema. Verify the result.",
                    $key
                );
            }
        }

        return [
            'changes'  => $resolved['changes'],
            'toggles'  => $resolved['toggles'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Record, apply and confirm a write to a post's Elementor data.
     *
     * @param int   $post_id  The post ID.
     * @param array $elements The new elements data.
     * @param array $op       Operation metadata: 'command', 'element_id', 'after'.
     * @param array $result   Extra fields to merge into the success result.
     * @return array Result array.
     */
    private function apply( int $post_id, array $elements, array $op, array $result ): array {
        $snapshot = get_post_meta( $post_id, '_elementor_data', true );

        $operation_id = WPX_Operation_History::begin( [
            'command'       => $op['command'],
            'target_type'   => 'post',
            'post_id'       => $post_id,
            'element_id'    => $op['element_id'] ?? null,
            'page_snapshot' => is_string( $snapshot ) ? $snapshot : wp_json_encode( $snapshot ),
            'revision_id'   => $this->create_revision( $post_id ),
        ] );

        if ( null === $operation_id ) {
            return $this->error(
                'Refusing to write: the operation could not be recorded (' .
                ( WPX_Operation_History::last_error() ?? 'unknown error' ) .
                '). Undo would not be possible.'
            );
        }

        try {
            $this->persist( $post_id, $elements );
        } catch ( \Throwable $e ) {
            $diagnosis = $this->diagnose_write_error( $e );

            // persist() writes `_elementor_data` FIRST and only then
            // regenerates CSS, so a throw from the CSS step leaves the new,
            // unconfirmed data already saved underneath us. Telling the
            // caller the write "failed" while the page has in fact changed
            // is the one outcome an agent caller cannot safely act on, so
            // put the post back the way it was before reporting failure.
            $rollback_error = $this->rollback_after_failed_write( $post_id, is_string( $snapshot ) ? $snapshot : null );

            if ( null !== $rollback_error ) {
                WPX_Operation_History::fail( $operation_id, $diagnosis . ' | rollback also failed: ' . $rollback_error );

                return [
                    'success'      => false,
                    'operation_id' => $operation_id,
                    'message'      => 'Write failed: ' . $diagnosis .
                        ". The page was left in a MODIFIED state — automatic rollback also failed ({$rollback_error}). " .
                        "Run `wpx elementor undo {$operation_id}` to restore its pre-write state.",
                ];
            }

            WPX_Operation_History::fail( $operation_id, $diagnosis );

            return [
                'success'      => false,
                'operation_id' => $operation_id,
                'message'      => 'Write failed: ' . $diagnosis .
                    " (snapshot kept under {$operation_id}; the page has been rolled back to its pre-write state).",
            ];
        }

        WPX_Operation_History::complete( $operation_id, $op['after'] ?? null );

        return array_merge(
            [
                'success'      => true,
                'dry_run'      => false,
                'post_id'      => $post_id,
                'operation_id' => $operation_id,
            ],
            $result
        );
    }

    /**
     * Write elements data to the database and refresh everything downstream.
     *
     * @param int   $post_id  The post ID.
     * @param array $elements The elements data.
     * @throws \RuntimeException When the data cannot be encoded.
     */
    private function persist( int $post_id, array $elements ): void {
        $json = wp_json_encode( $elements );

        if ( false === $json ) {
            throw new \RuntimeException( 'Elementor data could not be JSON encoded.' );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }

        // Bump post_modified. Elementor data lives in meta, so without this the
        // post looks untouched to every cache, CDN and feed keyed on modified
        // time, and the change never reaches visitors.
        wp_update_post( [ 'ID' => $post_id ] );

        WPX_Elementor_Compat::regenerate_post_css( $post_id );
    }

    /**
     * Decode a raw pre-write snapshot (as captured by apply() via
     * get_post_meta(), or retrieved via WPX_Operation_History::get_snapshot())
     * into an elements array ready to hand to persist(). Shared by undo()
     * and apply()'s own failure-path rollback, so both validate a snapshot
     * exactly the same way.
     *
     * @param string $snapshot Raw JSON.
     * @return array|null The decoded elements, or null if the snapshot is
     *                     not valid Elementor data.
     */
    private function decode_snapshot( string $snapshot ): ?array {
        $elements = json_decode( $snapshot, true );

        return is_array( $elements ) ? $elements : null;
    }

    /**
     * Put a post's `_elementor_data` back to its pre-write snapshot after
     * persist() throws partway through apply(). See the comment at the
     * apply() call site for why this matters: persist() writes
     * `_elementor_data` before it regenerates CSS, so a throw from the CSS
     * step alone leaves the new, unconfirmed data already saved.
     *
     * Only `_elementor_data` is reverted here — not the `_elementor_edit_mode`
     * / `_elementor_version` meta or the `post_modified` bump persist() also
     * makes. Those either don't change across a wpx write (edit mode,
     * version) or don't change what the page renders (post_modified);
     * `_elementor_data` is the only one of persist()'s writes that actually
     * describes the page's content, so it's the only one whose mismatch
     * would mean the write didn't really fail.
     *
     * CSS is re-synced with the reverted data on a best-effort basis: a
     * throw there does not leave the page in a modified state — content is
     * already back to its pre-write value — so it is not treated as a
     * rollback failure, only swallowed.
     *
     * @param int         $post_id  The post ID.
     * @param string|null $snapshot The pre-write snapshot captured by
     *                               apply() (unslashed, as get_post_meta()
     *                               returns it), or null/empty when the post
     *                               had no `_elementor_data` before the write.
     * @return string|null Null once the revert is verified in place;
     *                      otherwise a message describing why the rollback
     *                      itself failed, so the caller can be told the page
     *                      is still in a modified state.
     */
    private function rollback_after_failed_write( int $post_id, ?string $snapshot ): ?string {
        if ( null === $snapshot || '' === $snapshot ) {
            $elements = [];
        } else {
            $elements = $this->decode_snapshot( $snapshot );

            if ( null === $elements ) {
                return 'the pre-write snapshot could not be decoded';
            }
        }

        $json = wp_json_encode( $elements );

        if ( false === $json ) {
            return 'the pre-write snapshot could not be re-encoded';
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

        // update_post_meta()'s boolean return is not a reliable success
        // signal here (it also returns false when the new value equals the
        // old one), so the revert is verified by reading the meta back
        // instead of trusting the return value.
        if ( get_post_meta( $post_id, '_elementor_data', true ) !== $json ) {
            return 'update_post_meta() could not be verified to have restored the previous _elementor_data';
        }

        try {
            WPX_Elementor_Compat::regenerate_post_css( $post_id );
        } catch ( \Throwable $e ) {
            // Deliberately ignored - see method doc comment.
        }

        return null;
    }

    /**
     * Recognize Elementor's CSS-regeneration failure when WP_Filesystem has
     * silently fallen back to the `ftp` method with no credentials
     * configured, and translate the resulting PHP TypeError into an
     * actionable message.
     *
     * Root cause (confirmed against a real Plesk site): running wpx/WP-CLI
     * as a different OS user than the one that owns the site's files makes
     * WP_Filesystem reject the `direct` method and fall back to `ftp`,
     * whose internal calls then all receive a null connection - e.g.
     * `ftp_nlist(): Argument #1 ($ftp) must be of type FTP\Connection, null
     * given`. That raw message gives no hint the real cause is a system-user
     * mismatch rather than a filesystem or FTP configuration problem, so it
     * is translated here rather than left for the caller to puzzle out. The
     * original message is always kept, never hidden.
     *
     * @param \Throwable $e The exception caught around a persist() call.
     * @return string The (possibly translated) error message.
     */
    private function diagnose_write_error( \Throwable $e ): string {
        $message = $e->getMessage();

        if ( preg_match( '/ftp_\w+\(\):.*must be of type FTP\\\\Connection/', $message ) ) {
            return 'WordPress could not write site files because WP-CLI is running as a different system user ' .
                'than the one that owns the site files, so WP_Filesystem silently fell back to the FTP method ' .
                'with no credentials configured instead of writing directly. Run wpx/WP-CLI as the site\'s own ' .
                "system user instead. Original error: {$message}";
        }

        return $message;
    }

    /**
     * Create a WordPress revision before a write, when the post type supports it.
     *
     * This is a convenience for humans — it makes the change visible in the
     * post's revision history — not the mechanism wpx itself undoes through.
     *
     * @param int $post_id The post ID.
     * @return int|null The revision ID, or null when none was created.
     */
    private function create_revision( int $post_id ): ?int {
        if ( ! function_exists( 'wp_save_post_revision' ) ) {
            return null;
        }

        $revision_id = wp_save_post_revision( $post_id );

        return is_int( $revision_id ) && $revision_id > 0 ? $revision_id : null;
    }

    /**
     * Build a dry-run result.
     *
     * @param array $fields Fields describing what would happen.
     * @return array Result array.
     */
    private function dry_run_result( array $fields ): array {
        return array_merge(
            [
                'success' => true,
                'dry_run' => true,
                'message' => 'No changes applied (dry run).',
            ],
            $fields
        );
    }

    /**
     * Calculate a diff between old settings and new changes.
     *
     * @param array $before  Old settings.
     * @param array $changes New changes being applied.
     * @return array Array of diff entries.
     */
    private function calculate_diff( array $before, array $changes ): array {
        $diff = [];

        foreach ( $changes as $key => $new_value ) {
            $old_value = $before[ $key ] ?? null;

            if ( $old_value !== $new_value ) {
                $diff[] = [
                    'key' => $key,
                    'old' => $old_value,
                    'new' => $new_value,
                ];
            }
        }

        return $diff;
    }

    /**
     * Parse a style value string into the appropriate Elementor format.
     *
     * Handles unit values like "32px", "2em", "50%" and four-part dimension
     * values like "20px 15px 20px 15px". Anything else is passed through
     * untouched, including values already in Elementor's array form.
     *
     * @param string $key   The style key (unused; kept for call-site clarity).
     * @param mixed  $value The value to parse.
     * @return mixed The parsed value.
     */
    private function parse_style_value( string $key, mixed $value ): mixed {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        if ( preg_match( '/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)$/', $value, $matches ) ) {
            return [
                'unit'  => $matches[2],
                'size'  => (float) $matches[1],
                'sizes' => [],
            ];
        }

        if ( preg_match( '/^(-?\d+)(px|em|rem|%) (-?\d+)(px|em|rem|%) (-?\d+)(px|em|rem|%) (-?\d+)(px|em|rem|%)$/', $value, $m ) ) {
            return [
                'unit'     => $m[2],
                'top'      => $m[1],
                'right'    => $m[3],
                'bottom'   => $m[5],
                'left'     => $m[7],
                'isLinked' => false,
            ];
        }

        return $value;
    }

    /**
     * Create an error response.
     *
     * @param string $message Error message.
     * @return array Error response array.
     */
    private function error( string $message ): array {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
