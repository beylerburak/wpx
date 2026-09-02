<?php
/**
 * WPX Atomic Bridge
 *
 * Read/manipulate half of wpx's support for Elementor 4's "atomic widgets"
 * (V4) document model. Mirrors WPX_Elementor_Bridge's shape (build_tree,
 * find/move/delete/add element, id generation, container guards) so the
 * save layer can treat a classic document and an atomic document alike,
 * plus the prop-envelope handling that has no classic analogue: classic
 * `settings` is a flat key/value bag, while atomic `settings` values are
 * type-enveloped as `{"$$type": "<type>", "value": ...}`.
 *
 * Ground truth for every shape assumed below was read directly out of
 * Elementor 4.2.4 (modules/atomic-widgets/) and confirmed by constructing
 * real atomic elements/prop schemas through wp-cli `eval-file` against a
 * live install, not assumed from documentation:
 *
 * - `elements/base/atomic-widget-base.php` (Atomic_Widget_Base, extends
 *   Widget_Base): heading/paragraph/button/image/divider/svg/youtube/
 *   self-hosted-video. These are stored exactly like classic widgets -
 *   `elType => 'widget'`, `widgetType => '<its element type>'` (e.g.
 *   `e-heading`) - see elements/base/widget-builder.php's `build()`.
 * - `elements/base/atomic-element-base.php` (Atomic_Element_Base, extends
 *   Element_Base): div-block/flexbox/grid/tabs family/form/
 *   collection-loop. These are stored with `elType` set directly to the
 *   element's own type (e.g. `e-flexbox`, no `widgetType`) - see
 *   elements/base/element-builder.php's `build()`.
 * - Both bases add `styles` (array), `version` (string), `editor_settings`
 *   (array) and `interactions` (array) alongside `settings` - `settings`
 *   itself is NOT renamed to `props` at the persisted-JSON level (that
 *   name is only used inside a style variant's `props`, and in in-memory
 *   control/schema plumbing) - confirmed by
 *   `Atomic_Heading::generate()->build()` returning
 *   `{"elType":"widget","widgetType":"e-heading","settings":[],...}`.
 * - Every prop value is enveloped as `{"$$type": "<key>", "value": ...}`
 *   (modules/atomic-widgets/prop-types/concerns/has-generate.php). The
 *   *shape* of `value` depends on the prop type's "kind"
 *   (`Prop_Type::get_type()`), read live off the schema rather than
 *   hard-coded per prop:
 *     - `plain`  (e.g. `string`, `number`, `boolean`, `classes`, `color`,
 *       `size`): `value` is the raw scalar/array as-is.
 *     - `object` (e.g. `html-v3` for heading `title` / button `text` /
 *       paragraph `paragraph`, `link`, `image`, `key-value`): `value` is
 *       an assoc array whose own fields are themselves enveloped per the
 *       prop type's `get_shape()`.
 *     - `array`  (e.g. `attributes`, whose items are `key-value`): `value`
 *       is a list, each item enveloped per `get_item_type()`.
 *     - `union`  (confirmed live: heading's `tag`/`title`/`link`/`_cssid`
 *       are all Union_Prop_Type once `apply_filters(
 *       'elementor/atomic-widgets/props-schema', ... )` runs - the filter
 *       wraps overridable props in a union of {concrete type, `dynamic`,
 *       `overridable`}): `value`'s shape depends on which candidate branch
 *       matches; resolved by trying each branch and keeping the one that
 *       validates.
 *     - `unknown` (e.g. html-v3's `children`): passed through unwrapped,
 *       by design (documented in html-v3-prop-type.php as "Plain array of
 *       child element objects (no prop type wrapping)").
 *
 * Container guard: rather than hard-code which atomic element types can
 * hold children, this class asks Elementor's own live element/widget
 * registry, mirroring exactly how Elementor's own element constructors
 * self-report it. Every Div_Block/Flexbox/Grid/Atomic_Tabs/
 * Atomic_Form(_Promotion)/Collection_Loop_Promotion constructor calls
 * `$this->meta( 'is_container', true )`; every atomic *widget* (heading,
 * button, ...) and the locked internal tab sub-parts (tabs-menu, tab,
 * tabs-content-area, tab-content - structurally nested but
 * `permanently_locked`, not general drop targets) do not set it, so
 * `get_meta_item( 'is_container', false )` on the live instance is exactly
 * the signal Elementor itself uses to decide "does this accept arbitrary
 * children". Confirmed live for every registered atomic element/widget
 * type via wp-cli `eval-file`.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Atomic_Bridge {

    /**
     * Get the parsed Elementor data for a post.
     *
     * Atomic and classic documents share the same `_elementor_data` post
     * meta key and JSON container shape (a list of element nodes, each
     * with `elements` for children) - only the per-node fields differ.
     *
     * @param int $post_id The post ID.
     * @return array|null The decoded elements array, or null if not an
     *                     Elementor page.
     */
    public function get_elements_data( int $post_id ): ?array {
        $raw_json = get_post_meta( $post_id, '_elementor_data', true );

        if ( empty( $raw_json ) ) {
            return null;
        }

        $elements = json_decode( $raw_json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return null;
        }

        return $elements;
    }

    /**
     * Check whether a post's Elementor document contains the atomic (V4)
     * element model, per WPX_Elementor_Compat::document_model().
     *
     * Returns true for both 'atomic' (the whole document is atomic) and
     * 'mixed' (only part of it is, e.g. mid-migration) - a mixed document
     * still contains atomic nodes this bridge must be able to read and
     * manipulate; it is WPX_Elementor_Save's job, not this class's, to
     * decide whether a mixed document is safe to write to as a whole.
     *
     * @param int $post_id The post ID.
     * @return bool True if the document contains the atomic model.
     */
    public function is_atomic_post( int $post_id ): bool {
        if ( ! class_exists( 'WPX_Elementor_Compat' ) ) {
            return false;
        }

        $model = WPX_Elementor_Compat::document_model( $post_id );

        return in_array( $model, [ 'atomic', 'mixed' ], true );
    }

    /**
     * Build an agent-friendly tree representation of the page.
     *
     * Same output contract as WPX_Elementor_Bridge::build_tree(): a
     * top-level `post_id`/`title`/`type`/`nodes`, each node with `id`,
     * `type`, `depth`, `label`, `summary`, `children`.
     *
     * @param int $post_id The post ID.
     * @return array|null The tree structure, or null if not an Elementor
     *                     page.
     */
    public function build_tree( int $post_id ): ?array {
        $elements = $this->get_elements_data( $post_id );

        if ( null === $elements ) {
            return null;
        }

        $post  = get_post( $post_id );
        $title = $post ? $post->post_title : 'Unknown';

        return [
            'post_id' => $post_id,
            'title'   => $title,
            'type'    => 'page',
            'nodes'   => $this->build_tree_nodes( $elements, 0 ),
        ];
    }

    /**
     * Recursively build tree nodes from Elementor elements data.
     *
     * @param array $elements The elements array.
     * @param int   $depth    Current nesting depth.
     * @return array Array of tree nodes.
     */
    private function build_tree_nodes( array $elements, int $depth ): array {
        $nodes = [];

        foreach ( $elements as $element ) {
            $el_type = $element['elType'] ?? 'unknown';

            $node = [
                'id'    => $element['id'] ?? '',
                'type'  => $el_type,
                'depth' => $depth,
            ];

            if ( 'widget' === $el_type ) {
                $node['widget_type'] = $element['widgetType'] ?? 'unknown';
                $node['label']       = $this->get_widget_label( $element );
            } else {
                $node['label'] = $this->get_container_label( $element );
            }

            // Cast to object so an empty summary encodes as {} rather
            // than [] - a field that changes JSON type depending on its
            // contents cannot be decoded by a typed client. (This bit
            // wpx once already, in the classic bridge; same fix here.)
            $node['summary'] = (object) $this->summarize_props( $element );

            $children = $element['elements'] ?? [];
            $node['children'] = ! empty( $children ) ? $this->build_tree_nodes( $children, $depth + 1 ) : [];

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Get a human-readable label for an atomic widget node.
     *
     * Unwraps the widget's `settings` prop envelopes so the widget's
     * actual content (heading text, button label, ...) can be read
     * directly, the same way WPX_Elementor_Bridge::get_widget_label()
     * reads classic `settings` directly.
     *
     * @param array $element The element data.
     * @return string The label.
     */
    private function get_widget_label( array $element ): string {
        $settings    = $this->unwrap_prop( $element['settings'] ?? [] );
        $widget_type = $element['widgetType'] ?? 'unknown';

        return match ( $widget_type ) {
            'e-heading'   => $this->truncate( (string) ( $settings['title']['content'] ?? '' ), 50 ),
            'e-paragraph' => $this->truncate( wp_strip_all_tags( (string) ( $settings['paragraph']['content'] ?? '' ) ), 50 ),
            'e-button'    => (string) ( $settings['text']['content'] ?? '' ),
            'e-image'     => basename( (string) ( $settings['image']['src']['url'] ?? '' ) ),
            'e-svg'       => basename( (string) ( $settings['svg']['url'] ?? '' ) ),
            'e-youtube'   => (string) ( $settings['source'] ?? '' ),
            default       => '',
        };
    }

    /**
     * Get a human-readable label for an atomic container/element node.
     *
     * @param array $element The element data.
     * @return string The label.
     */
    private function get_container_label( array $element ): string {
        $el_type = $element['elType'] ?? '';
        $short   = preg_replace( '/^e-/', '', $el_type );

        $settings = $this->unwrap_prop( $element['settings'] ?? [] );
        $tag      = $settings['tag'] ?? null;

        if ( is_string( $tag ) && '' !== $tag ) {
            return "{$short}({$tag})";
        }

        return $short;
    }

    /**
     * Summarize key props of an element for tree display.
     *
     * @param array $element The element data.
     * @return array Key-value pairs of important, unwrapped props.
     */
    private function summarize_props( array $element ): array {
        $settings = $this->unwrap_prop( $element['settings'] ?? [] );
        $summary  = [];

        if ( ! empty( $settings['classes'] ) && is_array( $settings['classes'] ) ) {
            $summary['classes'] = implode( ' ', $settings['classes'] );
        }

        if ( ! empty( $settings['tag'] ) && is_string( $settings['tag'] ) ) {
            $summary['tag'] = $settings['tag'];
        }

        if ( ! empty( $settings['link']['href'] ) ) {
            $summary['url'] = $settings['link']['href'];
        }

        return $summary;
    }

    /**
     * Find an element by its ID within the page data.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID to find.
     * @return array|null The element data, or null if not found.
     */
    public function find_element( int $post_id, string $element_id ): ?array {
        $elements = $this->get_elements_data( $post_id );

        if ( null === $elements ) {
            return null;
        }

        return $this->find_element_recursive( $elements, $element_id );
    }

    /**
     * Recursively search for an element by ID.
     *
     * @param array  $elements   The elements to search.
     * @param string $element_id The target element ID.
     * @return array|null The found element, or null.
     */
    private function find_element_recursive( array $elements, string $element_id ): ?array {
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                return $element;
            }

            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $found = $this->find_element_recursive( $children, $element_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find the parent ID and index of an element within the page data.
     *
     * @param array  $elements   The elements to search.
     * @param string $element_id The target element ID.
     * @return array|null Array with 'parent_id' (string|null, null when
     *                     the element is at the root) and 'index' (int),
     *                     or null if the element was not found.
     */
    public function find_parent_and_index( array $elements, string $element_id ): ?array {
        return $this->find_parent_and_index_recursive( $elements, null, $element_id );
    }

    /**
     * Recursive helper for find_parent_and_index.
     *
     * @param array       $elements   Elements to search.
     * @param string|null $parent_id  ID of the element that owns $elements (null at root).
     * @param string      $element_id Target ID.
     * @return array|null Array with 'parent_id' and 'index', or null.
     */
    private function find_parent_and_index_recursive( array $elements, ?string $parent_id, string $element_id ): ?array {
        foreach ( $elements as $index => $element ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                return [
                    'parent_id' => $parent_id,
                    'index'     => $index,
                ];
            }

            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $found = $this->find_parent_and_index_recursive( $children, $element['id'] ?? '', $element_id );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Check whether $candidate_id is $ancestor_id or lies anywhere within
     * its subtree.
     *
     * @param array  $elements     The full elements data.
     * @param string $ancestor_id  The potential ancestor's element ID.
     * @param string $candidate_id The element ID to test.
     * @return bool True if $candidate_id is a descendant of $ancestor_id.
     */
    public function is_descendant( array $elements, string $ancestor_id, string $candidate_id ): bool {
        $ancestor = $this->find_element_recursive( $elements, $ancestor_id );

        if ( null === $ancestor ) {
            return false;
        }

        return null !== $this->find_element_recursive( $ancestor['elements'] ?? [], $candidate_id );
    }

    /**
     * Collect every element ID present on the page.
     *
     * @param array $elements The elements to walk.
     * @return array List of element ID strings.
     */
    public function collect_ids( array $elements ): array {
        $ids = [];

        foreach ( $elements as $element ) {
            if ( ! empty( $element['id'] ) ) {
                $ids[] = $element['id'];
            }

            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $ids = array_merge( $ids, $this->collect_ids( $children ) );
            }
        }

        return $ids;
    }

    /**
     * Delete an element from the page data.
     *
     * @param array  &$elements  The full elements data (by reference).
     * @param string $element_id The element ID to delete.
     * @return array|null The removed element data, or null if not found.
     */
    public function delete_element( array &$elements, string $element_id ): ?array {
        $count = count( $elements );

        for ( $index = 0; $index < $count; $index++ ) {
            if ( ( $elements[ $index ]['id'] ?? '' ) === $element_id ) {
                $removed = $elements[ $index ];
                array_splice( $elements, $index, 1 );
                return $removed;
            }

            if ( ! empty( $elements[ $index ]['elements'] ) ) {
                $removed = $this->delete_element( $elements[ $index ]['elements'], $element_id );
                if ( $removed !== null ) {
                    return $removed;
                }
            }
        }

        return null;
    }

    /**
     * Validate whether an element can be moved to a given new parent.
     *
     * Same rejections as the classic bridge: element not found, new
     * parent not found, new parent is the element itself, new parent is
     * within the element's own subtree, or new parent cannot hold
     * children (see can_have_children()).
     *
     * @param array       $elements   The full elements data.
     * @param string      $element_id The element to move.
     * @param string|null $new_parent New parent ID (null/'root' for page root).
     * @return array Array with 'ok' (bool) and 'reason' (string, empty when ok).
     */
    public function validate_move( array $elements, string $element_id, ?string $new_parent ): array {
        $element = $this->find_element_recursive( $elements, $element_id );

        if ( null === $element ) {
            return [
                'ok'     => false,
                'reason' => "Element '{$element_id}' not found.",
            ];
        }

        if ( null !== $new_parent && 'root' !== $new_parent ) {
            if ( $new_parent === $element_id ) {
                return [
                    'ok'     => false,
                    'reason' => "Cannot move element '{$element_id}' into itself.",
                ];
            }

            $parent_element = $this->find_element_recursive( $elements, $new_parent );
            if ( null === $parent_element ) {
                return [
                    'ok'     => false,
                    'reason' => "New parent '{$new_parent}' not found.",
                ];
            }

            if ( ! $this->can_have_children( $parent_element ) ) {
                $parent_type = $parent_element['elType'] ?? 'unknown';
                return [
                    'ok'     => false,
                    'reason' => "New parent '{$new_parent}' is a '{$parent_type}' element and cannot hold children.",
                ];
            }

            if ( $this->is_descendant( $elements, $element_id, $new_parent ) ) {
                return [
                    'ok'     => false,
                    'reason' => "Cannot move element '{$element_id}' into its own descendant '{$new_parent}'.",
                ];
            }
        }

        return [
            'ok'     => true,
            'reason' => '',
        ];
    }

    /**
     * Move an element to a new parent/position, returning the reason for
     * failure when the move cannot be performed.
     *
     * @param array       &$elements    The full elements data (by reference).
     * @param string      $element_id   The element to move.
     * @param string|null $new_parent   New parent ID (null for root).
     * @param int|null    $position     Position in new parent.
     * @return array Array with 'ok' (bool) and 'reason' (string, empty when ok).
     */
    public function move_element_with_reason(
        array &$elements,
        string $element_id,
        ?string $new_parent,
        ?int $position = null
    ): array {
        $validation = $this->validate_move( $elements, $element_id, $new_parent );
        if ( ! $validation['ok'] ) {
            return $validation;
        }

        $removed = $this->delete_element( $elements, $element_id );
        if ( null === $removed ) {
            return [
                'ok'     => false,
                'reason' => "Element '{$element_id}' not found.",
            ];
        }

        if ( ! $this->add_element( $elements, $new_parent, $removed, $position ) ) {
            // Should not happen since validate_move() already confirmed
            // the new parent exists, but avoid silently losing the element.
            $elements[] = $removed;
            return [
                'ok'     => false,
                'reason' => "New parent '{$new_parent}' not found; element restored to root.",
            ];
        }

        return [
            'ok'     => true,
            'reason' => '',
        ];
    }

    /**
     * Add a new element (widget or container) to the page data.
     *
     * Every id in the element being added - its own id and, if it carries
     * a subtree, every descendant's id - is checked against the page's
     * existing ids and regenerated on collision, exactly as
     * WPX_Elementor_Bridge::add_element() does for classic documents.
     *
     * @param array       &$elements    The full elements data (by reference).
     * @param string|null $parent_id    Parent element ID (null for root level).
     * @param array       $new_element  The new element data.
     * @param int|null    $position     Position index (null for append).
     * @param string|null $after_id     Insert after this element ID (overrides position).
     * @return bool True if element was added successfully. False if the
     *              parent could not be found or cannot hold children.
     */
    public function add_element(
        array &$elements,
        ?string $parent_id,
        array $new_element,
        ?int $position = null,
        ?string $after_id = null
    ): bool {
        $existing_ids = $this->collect_ids( $elements );
        $new_element  = $this->ensure_unique_ids( $new_element, $existing_ids );

        if ( null === $parent_id || 'root' === $parent_id ) {
            return $this->insert_at_position( $elements, $new_element, $position, $after_id );
        }

        $parent_element = $this->find_element_recursive( $elements, $parent_id );
        if ( null === $parent_element || ! $this->can_have_children( $parent_element ) ) {
            return false;
        }

        return $this->add_to_parent( $elements, $parent_id, $new_element, $position, $after_id );
    }

    /**
     * Recursively assign a fresh, unique id to $element (and every
     * descendant in its subtree) whenever its current id is missing or
     * already present in $existing_ids.
     *
     * @param array $element       The element (and possibly its subtree) to de-duplicate.
     * @param array &$existing_ids Ids already taken on the page (by reference, grows as ids are assigned).
     * @return array The element with unique ids applied throughout its subtree.
     */
    private function ensure_unique_ids( array $element, array &$existing_ids ): array {
        $id = $element['id'] ?? '';

        if ( '' === $id || in_array( $id, $existing_ids, true ) ) {
            $id = $this->generate_element_id( $existing_ids );
            $element['id'] = $id;
        }

        $existing_ids[] = $id;

        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as &$child ) {
                $child = $this->ensure_unique_ids( $child, $existing_ids );
            }
            unset( $child );
        }

        return $element;
    }

    /**
     * Determine whether an element can legitimately hold children.
     *
     * Resolves the element's live Elementor instance (via the widgets
     * manager for `elType === 'widget'`, or the elements manager
     * otherwise) and reads its `is_container` meta flag - the same flag
     * every container-capable atomic element sets on itself in its own
     * constructor (`$this->meta( 'is_container', true )`). Classic
     * `container`/`section`/`column` nodes are also accepted, since a
     * 'mixed' document can still contain them. Falls back to false
     * (leaf) whenever Elementor, the element type, or the meta flag is
     * unavailable - a document this bridge cannot positively confirm as
     * a container is treated as not one, rather than risking nesting
     * children into something the V4 editor cannot render.
     *
     * @param array $element The element data (needs at least 'elType',
     *                        and 'widgetType' when elType is 'widget').
     * @return bool True if the element type may contain children.
     */
    private function can_have_children( array $element ): bool {
        $el_type = $element['elType'] ?? '';

        if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
            return true;
        }

        $instance = $this->get_element_instance( $el_type, $element['widgetType'] ?? null );

        if ( null === $instance || ! method_exists( $instance, 'get_meta_item' ) ) {
            return false;
        }

        return (bool) $instance->get_meta_item( 'is_container', false );
    }

    /**
     * Resolve the live Elementor element/widget instance for a given
     * elType (+ widgetType, when elType is 'widget').
     *
     * @param string      $el_type     The element's elType.
     * @param string|null $widget_type The element's widgetType, if elType is 'widget'.
     * @return object|null The live instance, or null if unavailable.
     */
    private function get_element_instance( string $el_type, ?string $widget_type ): ?object {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return null;
        }

        $plugin = \Elementor\Plugin::$instance ?? null;

        if ( null === $plugin ) {
            return null;
        }

        if ( 'widget' === $el_type ) {
            if ( null === $widget_type || ! isset( $plugin->widgets_manager ) ) {
                return null;
            }

            $instance = $plugin->widgets_manager->get_widget_types( $widget_type );
        } else {
            if ( ! isset( $plugin->elements_manager ) ) {
                return null;
            }

            $instance = $plugin->elements_manager->get_element_types( $el_type );
        }

        return is_object( $instance ) ? $instance : null;
    }

    /**
     * Generate a unique 7-character element ID (matching Elementor's format).
     *
     * @param array $existing_ids Element IDs already present on the page, to avoid colliding with.
     * @return string The generated ID.
     */
    private function generate_element_id( array $existing_ids = [] ): string {
        do {
            $id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
        } while ( in_array( $id, $existing_ids, true ) );

        return $id;
    }

    /**
     * Insert an element at a specific position in an array.
     *
     * @param array       &$elements   Target array.
     * @param array       $new_element Element to insert.
     * @param int|null    $position    Position index.
     * @param string|null $after_id    Insert after this element ID.
     * @return bool True on success.
     */
    private function insert_at_position(
        array &$elements,
        array $new_element,
        ?int $position,
        ?string $after_id
    ): bool {
        if ( $after_id !== null ) {
            foreach ( $elements as $index => $el ) {
                if ( ( $el['id'] ?? '' ) === $after_id ) {
                    array_splice( $elements, $index + 1, 0, [ $new_element ] );
                    return true;
                }
            }
            return false;
        }

        if ( $position !== null ) {
            array_splice( $elements, $position, 0, [ $new_element ] );
            return true;
        }

        $elements[] = $new_element;
        return true;
    }

    /**
     * Recursively find parent and add element to it.
     *
     * @param array       &$elements   Elements to search.
     * @param string      $parent_id   Target parent ID.
     * @param array       $new_element Element to add.
     * @param int|null    $position    Position index.
     * @param string|null $after_id    Insert after this element ID.
     * @return bool True if parent was found and element added.
     */
    private function add_to_parent(
        array &$elements,
        string $parent_id,
        array $new_element,
        ?int $position,
        ?string $after_id
    ): bool {
        foreach ( $elements as &$element ) {
            if ( ( $element['id'] ?? '' ) === $parent_id ) {
                if ( ! isset( $element['elements'] ) ) {
                    $element['elements'] = [];
                }
                return $this->insert_at_position(
                    $element['elements'],
                    $new_element,
                    $position,
                    $after_id
                );
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( $this->add_to_parent( $element['elements'], $parent_id, $new_element, $position, $after_id ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Truncate a string to a maximum length.
     *
     * @param string $text       The text to truncate.
     * @param int    $max_length Maximum length.
     * @return string The truncated text.
     */
    private function truncate( string $text, int $max_length ): string {
        $text = trim( $text );
        if ( mb_strlen( $text ) <= $max_length ) {
            return $text;
        }
        return mb_substr( $text, 0, $max_length - 3 ) . '...';
    }

    /* ---------------------------------------------------------------
     * Prop envelope handling
     * --------------------------------------------------------------- */

    /**
     * Get an element/widget type's declared prop schema, read live from
     * Elementor (via `static::get_props_schema()`, which itself runs the
     * `elementor/atomic-widgets/props-schema` filter - the same schema
     * Elementor's own editor and Props_Parser validate against).
     *
     * @param string      $element_type The elType (e.g. 'widget', 'e-flexbox').
     * @param string|null $widget_type  The widgetType, when $element_type is 'widget'.
     * @return array<string, object> Map of prop name to Prop_Type instance, or [] if unresolvable.
     */
    public function props_schema( string $element_type, ?string $widget_type = null ): array {
        $instance = $this->get_element_instance( $element_type, $widget_type );

        if ( null === $instance || ! method_exists( $instance, 'get_props_schema' ) ) {
            return [];
        }

        return $instance::get_props_schema();
    }

    /**
     * Turn a plain, CLI-supplied value into the `$$type`-enveloped
     * structure a prop's schema expects.
     *
     * If $raw already looks like a correctly-enveloped value for the
     * target prop type (or, for a union, for one of its candidate
     * branches), it is returned unchanged rather than double-wrapped -
     * this lets a caller round-trip unwrap_prop() output, or hand-craft
     * real Elementor JSON, without it being mangled.
     *
     * If the prop key is not present in the schema, $raw is returned
     * with a best-effort `string` envelope (or passed through unchanged
     * if it already looks enveloped) - there is no schema to wrap
     * against, so validate_props() is what actually gates correctness.
     *
     * @param string      $element_type The elType (e.g. 'widget', 'e-flexbox').
     * @param string|null $widget_type  The widgetType, when $element_type is 'widget'.
     * @param string      $prop_key     The prop's key in the schema.
     * @param mixed       $raw          The plain value to wrap.
     * @return array The enveloped value.
     */
    public function wrap_prop( string $element_type, ?string $widget_type, string $prop_key, $raw ): array {
        $schema = $this->props_schema( $element_type, $widget_type );

        if ( ! isset( $schema[ $prop_key ] ) ) {
            if ( is_array( $raw ) && array_key_exists( '$$type', $raw ) && array_key_exists( 'value', $raw ) ) {
                return $raw;
            }

            return [
                '$$type' => 'string',
                'value'  => $raw,
            ];
        }

        return $this->wrap_value( $schema[ $prop_key ], $raw );
    }

    /**
     * Recursively envelope $raw according to a single Prop_Type's kind.
     *
     * @param object $prop_type The Prop_Type instance from the schema.
     * @param mixed  $raw       The plain (or partially enveloped) value.
     * @return array The enveloped value.
     */
    private function wrap_value( object $prop_type, $raw ): array {
        if ( $this->looks_enveloped_for( $prop_type, $raw ) ) {
            return $raw;
        }

        $kind = method_exists( $prop_type, 'get_type' ) ? $prop_type->get_type() : 'plain';

        switch ( $kind ) {
            case 'union':
                return $this->wrap_union( $prop_type, $raw );

            case 'object':
                return $this->wrap_object( $prop_type, $raw );

            case 'array':
                return $this->wrap_array( $prop_type, $raw );

            case 'unknown':
                // Deliberately not enveloped upstream (see html-v3's
                // `children`) - pass through as-is.
                return is_array( $raw ) ? $raw : [];

            default:
                // 'plain' and every plain-derived kind (string, number,
                // boolean, classes, color, size, ...): value is the raw
                // scalar/array, untouched.
                return [
                    '$$type' => $prop_type::get_key(),
                    'value'  => $raw,
                ];
        }
    }

    /**
     * Whether $raw already carries a `$$type`/`value` envelope that
     * matches $prop_type (or, for a union, one of its branches).
     *
     * @param object $prop_type The target Prop_Type.
     * @param mixed  $raw       The candidate value.
     * @return bool True if $raw should be treated as already-wrapped.
     */
    private function looks_enveloped_for( object $prop_type, $raw ): bool {
        if ( ! is_array( $raw ) || ! array_key_exists( '$$type', $raw ) || ! array_key_exists( 'value', $raw ) ) {
            return false;
        }

        if ( method_exists( $prop_type, 'get_prop_types' ) ) {
            return array_key_exists( $raw['$$type'], $prop_type->get_prop_types() );
        }

        if ( method_exists( $prop_type, 'get_key' ) ) {
            return $raw['$$type'] === $prop_type::get_key();
        }

        return false;
    }

    /**
     * Wrap $raw against a Union_Prop_Type by trying each candidate
     * branch and keeping the first one whose own validate() accepts the
     * wrapped result.
     *
     * `overridable` (a global-class override marker) and `dynamic` (a
     * dynamic-tag binding) branches are tried last - a caller supplying
     * a plain value almost always means the concrete type, not one of
     * those cross-cutting wrapper branches.
     *
     * @param object $union The Union_Prop_Type instance.
     * @param mixed  $raw   The plain value.
     * @return array The enveloped value.
     */
    private function wrap_union( object $union, $raw ): array {
        $candidates = method_exists( $union, 'get_prop_types' ) ? $union->get_prop_types() : [];
        $deferred   = [ 'overridable', 'dynamic' ];

        foreach ( [ true, false ] as $skip_deferred ) {
            foreach ( $candidates as $key => $candidate ) {
                if ( $skip_deferred && in_array( $key, $deferred, true ) ) {
                    continue;
                }

                $wrapped = $this->wrap_value( $candidate, $raw );

                if ( method_exists( $candidate, 'validate' ) && $candidate->validate( $wrapped ) ) {
                    return $wrapped;
                }
            }
        }

        // Nothing validated; best-effort wrap against the first
        // candidate so the caller still gets *a* structure.
        // validate_props() is the real safety gate and will correctly
        // reject this.
        $first = reset( $candidates );

        return $first ? $this->wrap_value( $first, $raw ) : [
            '$$type' => 'string',
            'value'  => $raw,
        ];
    }

    /**
     * Wrap $raw against an Object_Prop_Type by mapping each of its shape
     * fields recursively.
     *
     * If $raw is not itself an array (e.g. a caller passes a bare string
     * for a heading's `title`), it is mapped onto the shape's `content`
     * field when the shape has one - this is what makes
     * `--props '{"title":"Hello"}'` work against Html_V3_Prop_Type
     * without the caller knowing its internal `{content, children}`
     * shape.
     *
     * @param object $object_type The Object_Prop_Type instance.
     * @param mixed  $raw         The plain value.
     * @return array The enveloped value.
     */
    private function wrap_object( object $object_type, $raw ): array {
        $shape = method_exists( $object_type, 'get_shape' ) ? $object_type->get_shape() : [];

        if ( ! is_array( $raw ) ) {
            $raw = array_key_exists( 'content', $shape ) ? [ 'content' => $raw ] : [];
        }

        $value = [];
        foreach ( $shape as $field => $field_type ) {
            if ( array_key_exists( $field, $raw ) ) {
                $value[ $field ] = $this->wrap_value( $field_type, $raw[ $field ] );
            }
        }

        return [
            '$$type' => $object_type::get_key(),
            'value'  => $value,
        ];
    }

    /**
     * Wrap $raw against an Array_Prop_Type by enveloping each list item
     * per its declared item type.
     *
     * @param object $array_type The Array_Prop_Type instance.
     * @param mixed  $raw        The plain list.
     * @return array The enveloped value.
     */
    private function wrap_array( object $array_type, $raw ): array {
        $item_type = method_exists( $array_type, 'get_item_type' ) ? $array_type->get_item_type() : null;
        $list      = is_array( $raw ) ? array_values( $raw ) : [];

        $items = null === $item_type
            ? $list
            : array_map( fn( $item ) => $this->wrap_value( $item_type, $item ), $list );

        return [
            '$$type' => $array_type::get_key(),
            'value'  => $items,
        ];
    }

    /**
     * Recursively strip `$$type`/`value` envelopes for readable output.
     *
     * The inverse of wrap_prop()/wrap_value(): an enveloped value is
     * replaced by its unwrapped inner value (recursed into, since an
     * object-shaped prop's value is itself full of nested envelopes); a
     * plain array has each of its members unwrapped in place; anything
     * else is returned unchanged.
     *
     * @param mixed $value An enveloped prop value, a props array, or a plain value.
     * @return mixed The unwrapped value.
     */
    public function unwrap_prop( $value ) {
        if ( is_array( $value ) && array_key_exists( '$$type', $value ) && array_key_exists( 'value', $value ) ) {
            return $this->unwrap_prop( $value['value'] );
        }

        if ( is_array( $value ) ) {
            $result = [];
            foreach ( $value as $key => $item ) {
                $result[ $key ] = $this->unwrap_prop( $item );
            }
            return $result;
        }

        return $value;
    }

    /**
     * Deep-merge a props change set into a current props array.
     *
     * Same semantics as WPX_Elementor_Bridge::merge_settings(): nested
     * associative arrays are merged recursively key by key; sequential
     * (list) arrays are replaced wholesale; a `null` value in $changes
     * deletes the corresponding key; anything else overwrites. Operates
     * on whatever arrays are passed in - typically $current is an
     * element's existing (enveloped) `settings`, and $changes is a set
     * of already wrap_prop()-wrapped values keyed by prop name.
     *
     * @param array $current The current props (or nested props) array.
     * @param array $changes The changes to merge in.
     * @return array The merged result.
     */
    public function merge_props( array $current, array $changes ): array {
        foreach ( $changes as $key => $value ) {
            if ( null === $value ) {
                unset( $current[ $key ] );
                continue;
            }

            $existing = $current[ $key ] ?? null;

            if (
                is_array( $value ) && $this->is_assoc_array( $value )
                && is_array( $existing ) && $this->is_assoc_array( $existing )
            ) {
                $current[ $key ] = $this->merge_props( $existing, $value );
                continue;
            }

            $current[ $key ] = $value;
        }

        return $current;
    }

    /**
     * Determine whether an array is associative (i.e. not a plain
     * sequential list with keys 0..n-1).
     *
     * An empty array is treated as a list (not associative), since an
     * empty `[]` conventionally means "cleared list" here.
     *
     * @param array $array The array to check.
     * @return bool True if the array is associative.
     */
    private function is_assoc_array( array $array ): bool {
        if ( [] === $array ) {
            return false;
        }

        return array_keys( $array ) !== range( 0, count( $array ) - 1 );
    }

    /**
     * Validate a set of (already-enveloped) props against an element/
     * widget type's schema, using Elementor's own Props_Parser rather
     * than reimplementing validation - this is the safety gate that
     * stops wpx writing a document the V4 editor cannot open.
     *
     * @param string      $element_type The elType (e.g. 'widget', 'e-flexbox').
     * @param string|null $widget_type  The widgetType, when $element_type is 'widget'.
     * @param array       $props        The enveloped props to validate (e.g. from wrap_prop()).
     * @return array{ok: bool, errors: string[]}
     */
    public function validate_props( string $element_type, ?string $widget_type, array $props ): array {
        if ( ! class_exists( '\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser' ) ) {
            return [
                'ok'     => false,
                'errors' => [ 'Elementor atomic-widgets Props_Parser is not available.' ],
            ];
        }

        $schema = $this->props_schema( $element_type, $widget_type );

        if ( [] === $schema ) {
            $type_label = 'widget' === $element_type && $widget_type
                ? "widget type '{$widget_type}'"
                : "element type '{$element_type}'";

            return [
                'ok'     => false,
                'errors' => [ "No props schema found for {$type_label}." ],
            ];
        }

        $parser = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make( $schema );
        $result = $parser->parse( $props );

        if ( $result->is_valid() ) {
            return [
                'ok'     => true,
                'errors' => [],
            ];
        }

        $errors = [];
        foreach ( $result->errors()->all() as $error ) {
            $errors[] = "{$error['key']}: {$error['error']}";
        }

        return [
            'ok'     => false,
            'errors' => $errors,
        ];
    }
}
