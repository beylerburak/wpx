<?php
/**
 * WPX Elementor Bridge
 *
 * Core class for reading and manipulating Elementor document data.
 * Provides tree building, element lookup, and structural operations.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Elementor_Bridge {

    /**
     * Get the parsed Elementor data for a post.
     *
     * @param int $post_id The post ID.
     * @return array|null The decoded elements array, or null if not an Elementor page.
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
     * Check if a post is built with Elementor.
     *
     * @param int $post_id The post ID.
     * @return bool True if built with Elementor.
     */
    public function is_elementor_post( int $post_id ): bool {
        $edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
        return $edit_mode === 'builder';
    }

    /**
     * Build an agent-friendly tree representation of the page.
     *
     * Returns a structured array that can be rendered as a tree in the CLI.
     * Each node has: id, type, widget_type (if widget), label, children, depth.
     *
     * @param int $post_id The post ID.
     * @return array|null The tree structure, or null if not an Elementor page.
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
            $node = [
                'id'    => $element['id'] ?? '',
                'type'  => $element['elType'] ?? 'unknown',
                'depth' => $depth,
            ];

            // Add widget type for widgets
            if ( ( $element['elType'] ?? '' ) === 'widget' ) {
                $node['widget_type'] = $element['widgetType'] ?? 'unknown';
                $node['label']       = $this->get_widget_label( $element );
            } else {
                $node['label'] = $this->get_container_label( $element );
            }

            // Add key settings summary. Cast to object so an empty summary
            // encodes as {} rather than [] — a field that changes JSON type
            // depending on its contents cannot be decoded by a typed client.
            $node['summary'] = (object) $this->summarize_settings( $element );

            // Recurse into children
            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $node['children'] = $this->build_tree_nodes( $children, $depth + 1 );
            } else {
                $node['children'] = [];
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Get a human-readable label for a widget element.
     *
     * @param array $element The element data.
     * @return string The label.
     */
    private function get_widget_label( array $element ): string {
        $settings    = $element['settings'] ?? [];
        $widget_type = $element['widgetType'] ?? 'unknown';

        return match ( $widget_type ) {
            'heading'     => $this->truncate( $settings['title'] ?? '', 50 ),
            'text-editor' => $this->truncate( wp_strip_all_tags( $settings['editor'] ?? '' ), 50 ),
            'button'      => $settings['text'] ?? '',
            'image'       => $settings['image']['alt'] ?? basename( $settings['image']['url'] ?? '' ),
            'icon'        => $settings['selected_icon']['value'] ?? '',
            'video'       => $settings['youtube_url'] ?? $settings['vimeo_url'] ?? '',
            default       => '',
        };
    }

    /**
     * Get a human-readable label for a container/section element.
     *
     * @param array $element The element data.
     * @return string The label.
     */
    private function get_container_label( array $element ): string {
        $settings = $element['settings'] ?? [];
        $el_type  = $element['elType'] ?? '';

        if ( $el_type === 'container' ) {
            $container_type = $settings['container_type'] ?? 'flex';
            $direction      = $settings['flex_direction'] ?? 'column';

            if ( $container_type === 'grid' ) {
                $cols = $settings['grid_columns_grid']['size'] ?? '?';
                return "grid({$cols} cols)";
            }

            return "flex({$direction})";
        }

        if ( $el_type === 'section' ) {
            $layout = $settings['layout'] ?? 'boxed';
            $is_inner = $element['isInner'] ?? false;
            return ( $is_inner ? 'inner-' : '' ) . "section({$layout})";
        }

        if ( $el_type === 'column' ) {
            $width = $settings['_column_size'] ?? '?';
            return "column({$width}%)";
        }

        return '';
    }

    /**
     * Summarize key settings of an element for tree display.
     *
     * @param array $element The element data.
     * @return array Key-value pairs of important settings.
     */
    private function summarize_settings( array $element ): array {
        $settings = $element['settings'] ?? [];
        $summary  = [];

        // Check for global references
        if ( ! empty( $settings['__globals__'] ) ) {
            foreach ( $settings['__globals__'] as $key => $ref ) {
                if ( preg_match( '/globals\/(colors|typography)\?id=(\w+)/', $ref, $m ) ) {
                    $summary[ $key ] = "global:{$m[1]}/{$m[2]}";
                }
            }
        }

        // Widget-specific summaries
        $widget_type = $element['widgetType'] ?? '';

        switch ( $widget_type ) {
            case 'heading':
                if ( ! empty( $settings['header_size'] ) ) {
                    $summary['tag'] = $settings['header_size'];
                }
                if ( ! empty( $settings['align'] ) ) {
                    $summary['align'] = $settings['align'];
                }
                break;

            case 'button':
                if ( ! empty( $settings['link']['url'] ) ) {
                    $summary['url'] = $settings['link']['url'];
                }
                if ( ! empty( $settings['button_type'] ) ) {
                    $summary['type'] = $settings['button_type'];
                }
                break;

            case 'image':
                if ( ! empty( $settings['image_size'] ) ) {
                    $summary['size'] = $settings['image_size'];
                }
                break;
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
     * Find an element and its parent path.
     *
     * Returns the element along with the chain of parent IDs leading to it.
     *
     * @param int    $post_id    The post ID.
     * @param string $element_id The element ID.
     * @return array|null Array with 'element' and 'path' keys, or null.
     */
    public function find_element_with_path( int $post_id, string $element_id ): ?array {
        $elements = $this->get_elements_data( $post_id );

        if ( null === $elements ) {
            return null;
        }

        $path = [];
        $found = $this->find_with_path_recursive( $elements, $element_id, $path );

        if ( $found ) {
            return [
                'element' => $found,
                'path'    => $path,
            ];
        }

        return null;
    }

    /**
     * Recursive helper for find_element_with_path.
     *
     * @param array  $elements   Elements to search.
     * @param string $element_id Target ID.
     * @param array  &$path      Parent path accumulator.
     * @return array|null The found element, or null.
     */
    private function find_with_path_recursive( array $elements, string $element_id, array &$path ): ?array {
        foreach ( $elements as $index => $element ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                return $element;
            }

            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $path[] = $element['id'] ?? '';
                $found  = $this->find_with_path_recursive( $children, $element_id, $path );
                if ( $found !== null ) {
                    return $found;
                }
                array_pop( $path );
            }
        }

        return null;
    }

    /**
     * Update an element's settings within the page data.
     *
     * This modifies the in-memory data structure. Use WPX_Elementor_Save
     * to persist changes and handle CSS regeneration.
     *
     * Uses merge_settings() internally, so nested associative settings
     * (e.g. `link`, `image`, `__globals__`) are merged field-by-field
     * rather than replaced wholesale. See merge_settings() for the full
     * merge semantics.
     *
     * @param array  $elements   The full elements data (by reference).
     * @param string $element_id The element ID to update.
     * @param array  $changes    Key-value pairs to merge into settings.
     * @return bool True if element was found and updated.
     */
    public function update_element_settings( array &$elements, string $element_id, array $changes ): bool {
        foreach ( $elements as &$element ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                    $element['settings'] = [];
                }
                $element['settings'] = $this->merge_settings( $element['settings'], $changes );
                return true;
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( $this->update_element_settings( $element['elements'], $element_id, $changes ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Deep-merge a settings change set into a current settings array.
     *
     * Semantics (matches Elementor's settings shape):
     *  - Nested associative arrays (e.g. `link`, `image`, `__globals__`)
     *    are merged recursively, key by key, so sibling fields that are
     *    not mentioned in $changes survive untouched.
     *  - Sequential/list arrays (e.g. repeater items, which are indexed
     *    0..n) are replaced wholesale rather than merged by index, since
     *    merging two differently-sized lists by numeric key would produce
     *    a meaningless hybrid.
     *  - A value of `null` in $changes removes the corresponding key from
     *    the result entirely (this is how a caller signals "delete this
     *    field", since Elementor settings never legitimately store null).
     *  - Any other scalar/list value simply overwrites the current value.
     *
     * @param array $current The current settings (or nested settings) array.
     * @param array $changes The changes to merge in.
     * @return array The merged result.
     */
    public function merge_settings( array $current, array $changes ): array {
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
                $current[ $key ] = $this->merge_settings( $existing, $value );
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
     * empty `[]` in Elementor settings is conventionally a cleared list.
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
     * Find the parent ID and index of an element within the page data.
     *
     * Used to record where an element sits before it is removed, so it
     * can later be restored to the same place (e.g. undo-of-delete).
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
     * Add a new element (widget or container) to the page data.
     *
     * Every id in the element being added — its own id and, if it carries
     * a subtree, every descendant's id — is checked against the page's
     * existing ids and regenerated on collision. This is enforced here
     * (rather than left to the caller) so that no caller path — whether
     * it supplies its own id, relies on create_widget()/create_container()
     * having assigned one, or pastes in a whole subtree — can slip a
     * duplicate id past add_element().
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
        // Ensure the new element (and any descendants it carries) has ids
        // that don't collide with anything already on the page.
        $existing_ids = $this->collect_ids( $elements );
        $new_element  = $this->ensure_unique_ids( $new_element, $existing_ids );

        // Root level insertion
        if ( null === $parent_id || $parent_id === 'root' ) {
            return $this->insert_at_position( $elements, $new_element, $position, $after_id );
        }

        // Only elements that can legitimately hold children (container,
        // section, column) may be used as a parent.
        $parent_element = $this->find_element_recursive( $elements, $parent_id );
        if ( null === $parent_element || ! $this->can_have_children( $parent_element ) ) {
            return false;
        }

        // Find parent and insert
        return $this->add_to_parent( $elements, $parent_id, $new_element, $position, $after_id );
    }

    /**
     * Deep-copy an element (and its entire subtree) and insert the copy as
     * a sibling of the original.
     *
     * The copy is inserted immediately after the original, unless
     * $position is given, in which case it is spliced into the original's
     * parent array at that index instead. The copy's `isInner` value is
     * whatever the original's was — since the copy lands in the exact same
     * parent array as the original, that value is already correct for
     * where it lands, so it is carried over unchanged.
     *
     * Every id in the copied subtree — the copy's own id and every
     * descendant's — is regenerated to stay unique against the whole page.
     * This reuses collect_ids() and ensure_unique_ids(), the same
     * subtree-wide id de-duplication add_element() relies on, rather than
     * duplicating that logic here.
     *
     * Settings are copied by value: PHP arrays are value types, so the
     * plain `$element = $elements[ $index ]` copy below already has no
     * shared references into the original's nested settings (e.g. a
     * widget's `link` array) — mutating the copy afterward (id
     * regeneration) cannot affect the original.
     *
     * @param array       &$elements  The full elements data (by reference).
     * @param string      $element_id The element ID to duplicate.
     * @param int|null    $position   Position within the original's parent
     *                                array to insert the copy at (null to
     *                                insert immediately after the original).
     * @return array|null The newly-created copy, or null if $element_id
     *                     was not found.
     */
    public function duplicate_element( array &$elements, string $element_id, ?int $position = null ): ?array {
        $existing_ids = $this->collect_ids( $elements );

        return $this->duplicate_element_recursive( $elements, $element_id, $position, $existing_ids );
    }

    /**
     * Recursive helper for duplicate_element().
     *
     * @param array    &$elements     Elements to search (by reference).
     * @param string   $element_id    The element ID to duplicate.
     * @param int|null $position      Position to insert the copy at (null = right after the original).
     * @param array    &$existing_ids Ids already taken on the page (by reference, grows as ids are assigned).
     * @return array|null The newly-created copy, or null if not found.
     */
    private function duplicate_element_recursive( array &$elements, string $element_id, ?int $position, array &$existing_ids ): ?array {
        $count = count( $elements );

        for ( $index = 0; $index < $count; $index++ ) {
            if ( ( $elements[ $index ]['id'] ?? '' ) === $element_id ) {
                $copy = $elements[ $index ];
                $copy = $this->ensure_unique_ids( $copy, $existing_ids );

                $insert_at = $position ?? ( $index + 1 );
                array_splice( $elements, $insert_at, 0, [ $copy ] );

                return $copy;
            }

            if ( ! empty( $elements[ $index ]['elements'] ) ) {
                $found = $this->duplicate_element_recursive( $elements[ $index ]['elements'], $element_id, $position, $existing_ids );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Recursively assign a fresh, unique id to $element (and every
     * descendant in its subtree) whenever its current id is missing or
     * already present in $existing_ids. $existing_ids is grown as ids are
     * assigned, so two colliding siblings within the same subtree cannot
     * end up with the same freshly-generated id either.
     *
     * @param array $element      The element (and possibly its subtree) to de-duplicate.
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
     * Determine whether an element type can legitimately hold children.
     *
     * Confirmed against Elementor core (includes/elements/{container,
     * section,column}.php and includes/base/widget-base.php): only
     * `container`, `section`, and `column` elType values nest children in
     * Elementor's document model. `widget` (Widget_Base, which every
     * widget extends) is always a leaf — no core widget defines child
     * elements. Giving a widget an `elements` array produces a tree the
     * Elementor editor cannot render.
     *
     * @param array $element The element data (needs at least 'elType').
     * @return bool True if the element type may contain children.
     */
    private function can_have_children( array $element ): bool {
        $el_type = $element['elType'] ?? '';
        return in_array( $el_type, [ 'container', 'section', 'column' ], true );
    }

    /**
     * Validate whether an element can be moved to a given new parent.
     *
     * Rejects the move when: the element itself cannot be found; the new
     * parent cannot be found (unless it is null/'root', meaning the page
     * root); the new parent is the element being moved; the new parent
     * lies within the element's own subtree (which would delete the
     * element without being able to re-add it); or the new parent is not
     * a type that can hold children (see can_have_children()) — e.g. a
     * widget, which is always a leaf in Elementor's document model.
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
                    'reason' => "New parent '{$new_parent}' is a '{$parent_type}' element and cannot hold children (only container, section, or column can).",
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
     * Move an element to a new parent/position.
     *
     * Refuses the move (without mutating $elements) when validate_move()
     * rejects it — in particular, moving an element into its own
     * descendant. To surface the rejection reason to a caller (e.g. for
     * an honest --dry-run report), use move_element_with_reason() instead,
     * which this method delegates to.
     *
     * @param array       &$elements    The full elements data (by reference).
     * @param string      $element_id   The element to move.
     * @param string|null $new_parent   New parent ID (null for root).
     * @param int|null    $position     Position in new parent.
     * @return bool True if element was moved successfully.
     */
    public function move_element(
        array &$elements,
        string $element_id,
        ?string $new_parent,
        ?int $position = null
    ): bool {
        return $this->move_element_with_reason( $elements, $element_id, $new_parent, $position )['ok'];
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

        // Remove element from current position
        $removed = $this->delete_element( $elements, $element_id );
        if ( null === $removed ) {
            return [
                'ok'     => false,
                'reason' => "Element '{$element_id}' not found.",
            ];
        }

        // Add to new position
        if ( ! $this->add_element( $elements, $new_parent, $removed, $position ) ) {
            // Should not happen since validate_move() already confirmed the
            // new parent exists, but avoid silently losing the element.
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
     * Create a new widget element data structure.
     *
     * @param string $widget_type The widget type (e.g., 'heading', 'button').
     * @param array  $settings    The widget settings.
     * @return array The widget element structure.
     */
    public function create_widget( string $widget_type, array $settings = [] ): array {
        return [
            'id'         => $this->generate_element_id(),
            'elType'     => 'widget',
            'widgetType' => $widget_type,
            'isInner'    => false,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    /**
     * Create a new container element data structure.
     *
     * Setting keys are taken directly from Elementor core
     * (includes/elements/container.php and the group controls it
     * registers, includes/controls/groups/{flex,grid}-container.php), so
     * that the produced container actually lays out rather than silently
     * doing nothing because of a wrong key:
     *  - `container_type` ('flex'|'grid') — plain control, NOT responsive.
     *  - Flex group (name 'flex', prefixed `flex_`): `flex_direction`,
     *    `flex_gap` (shape `{row, column, unit, isLinked}`),
     *    `flex_justify_content`, `flex_align_items` — all responsive.
     *  - Grid group (name 'grid', prefixed `grid_`): `grid_columns_grid`
     *    / `grid_rows_grid` (shape `{unit: 'fr', size}`), `grid_gaps`
     *    (same shape as `flex_gap`), `grid_justify_content`,
     *    `grid_align_items` — all responsive.
     * Group-control toggles (e.g. whether the `flex`/`grid` group is even
     * "on") are not this method's concern — WPX_Elementor_Controls and the
     * save layer handle those.
     *
     * $layout is the extension point that grows this beyond the original
     * flex-direction-only stub, while leaving the original 3-argument call
     * shape (and its output) unchanged for existing callers:
     *  - 'type'      => 'flex' (default) or 'grid'.
     *  - 'direction' => overrides $direction when 'type' is 'flex'.
     *  - 'gap'       => a single value (applied to both row and column,
     *                   unit 'px') or an array with any of
     *                   'row'/'column'/'unit'/'isLinked'.
     *  - 'justify'   => justify-content choice (both flex and grid).
     *  - 'align'     => align-items choice (both flex and grid).
     *  - 'columns'   => grid column count (or an array `{unit, size}` for
     *                   a non-'fr' unit) — grid only.
     *  - 'rows'      => grid row count, same shape as 'columns' — grid only.
     *  - 'responsive' => `[ breakpoint => [ ...same keys as above... ] ]`,
     *                    e.g. `[ 'tablet' => [ 'gap' => 8 ] ]`. Breakpoint
     *                    names are appended as a `_{$breakpoint}` suffix
     *                    (the same `_tablet`/`_mobile` convention used
     *                    elsewhere in this codebase), matching Elementor's
     *                    own responsive control id scheme
     *                    (add_responsive_control() in
     *                    includes/base/controls-stack.php).
     * $settings, applied last, can still override any of the above.
     *
     * @param string $direction Flex direction: 'row', 'row-reverse', 'column', or 'column-reverse'. Ignored when $layout['type'] === 'grid'.
     * @param array  $settings  Additional settings, merged in last (can override anything $layout produced).
     * @param bool   $is_inner  Whether this is an inner container.
     * @param array  $layout    Layout options — see above. Defaults to an empty flex/column container, matching the original behaviour.
     * @return array The container element structure.
     */
    public function create_container(
        string $direction = 'column',
        array $settings = [],
        bool $is_inner = false,
        array $layout = []
    ): array {
        $type = $layout['type'] ?? 'flex';

        $base_layout = $layout;
        unset( $base_layout['type'], $base_layout['responsive'] );

        if ( 'grid' !== $type && ! array_key_exists( 'direction', $base_layout ) ) {
            $base_layout['direction'] = $direction;
        }

        $default_settings = array_merge(
            [ 'container_type' => $type ],
            $this->build_container_layout_settings( $type, $base_layout )
        );

        if ( ! empty( $layout['responsive'] ) && is_array( $layout['responsive'] ) ) {
            foreach ( $layout['responsive'] as $breakpoint => $overrides ) {
                if ( ! is_array( $overrides ) ) {
                    continue;
                }

                foreach ( $this->build_container_layout_settings( $type, $overrides ) as $key => $value ) {
                    $default_settings[ "{$key}_{$breakpoint}" ] = $value;
                }
            }
        }

        return [
            'id'       => $this->generate_element_id(),
            'elType'   => 'container',
            'isInner'  => $is_inner,
            'settings' => array_merge( $default_settings, $settings ),
            'elements' => [],
        ];
    }

    /**
     * Translate the layout options accepted by create_container() into the
     * real (desktop-level, unsuffixed) Elementor container setting keys.
     *
     * Only keys actually present in $layout are set, so this can be called
     * once for the base (desktop) layout and again for each 'responsive'
     * breakpoint override without either call inventing values the caller
     * did not ask for.
     *
     * @param string $type   'flex' or 'grid'.
     * @param array  $layout Layout options (see create_container() docblock).
     * @return array Elementor container settings, keyed by real setting name.
     */
    private function build_container_layout_settings( string $type, array $layout ): array {
        $settings = [];

        if ( 'grid' === $type ) {
            if ( array_key_exists( 'columns', $layout ) ) {
                $settings['grid_columns_grid'] = $this->format_grid_size( $layout['columns'] );
            }
            if ( array_key_exists( 'rows', $layout ) ) {
                $settings['grid_rows_grid'] = $this->format_grid_size( $layout['rows'] );
            }
            if ( array_key_exists( 'gap', $layout ) ) {
                $settings['grid_gaps'] = $this->format_gap_value( $layout['gap'] );
            }
            if ( array_key_exists( 'justify', $layout ) ) {
                $settings['grid_justify_content'] = $layout['justify'];
            }
            if ( array_key_exists( 'align', $layout ) ) {
                $settings['grid_align_items'] = $layout['align'];
            }

            return $settings;
        }

        if ( array_key_exists( 'direction', $layout ) ) {
            $settings['flex_direction'] = $layout['direction'];
        }
        if ( array_key_exists( 'gap', $layout ) ) {
            $settings['flex_gap'] = $this->format_gap_value( $layout['gap'] );
        }
        if ( array_key_exists( 'justify', $layout ) ) {
            $settings['flex_justify_content'] = $layout['justify'];
        }
        if ( array_key_exists( 'align', $layout ) ) {
            $settings['flex_align_items'] = $layout['align'];
        }

        return $settings;
    }

    /**
     * Normalize a gap option into Elementor's GAPS control value shape
     * (includes/controls/gaps.php: `{row, column, unit, isLinked}`).
     *
     * @param mixed $gap A scalar (applied to both row and column, unit 'px'), or an array with any of 'row'/'column'/'unit'/'isLinked'.
     * @return array The normalized gap value.
     */
    private function format_gap_value( $gap ): array {
        if ( ! is_array( $gap ) ) {
            return [
                'row'      => (string) $gap,
                'column'   => (string) $gap,
                'unit'     => 'px',
                'isLinked' => true,
            ];
        }

        $row    = $gap['row'] ?? ( $gap['column'] ?? '' );
        $column = $gap['column'] ?? ( $gap['row'] ?? '' );

        return [
            'row'      => (string) $row,
            'column'   => (string) $column,
            'unit'     => $gap['unit'] ?? 'px',
            'isLinked' => $gap['isLinked'] ?? ( (string) $row === (string) $column ),
        ];
    }

    /**
     * Normalize a grid column/row count option into Elementor's grid
     * SLIDER control value shape (includes/controls/groups/grid-container.php,
     * fields 'columns_grid'/'rows_grid': `{unit: 'fr', size}`).
     *
     * @param mixed $value An integer/numeric size (unit defaults to 'fr'), or an array with 'unit'/'size'.
     * @return array The normalized size value.
     */
    private function format_grid_size( $value ): array {
        if ( is_array( $value ) ) {
            return [
                'unit' => $value['unit'] ?? 'fr',
                'size' => $value['size'] ?? 1,
            ];
        }

        return [
            'unit' => 'fr',
            'size' => $value,
        ];
    }

    /**
     * Wrap a set of sibling elements in a newly-created container.
     *
     * The new container is created at the position of $element_ids[0] —
     * i.e. it lands where that element currently is, relative to the
     * siblings that are NOT being moved — and all of $element_ids become
     * its children, in their original document order (not necessarily the
     * order they were listed in $element_ids). This is done as a single
     * atomic operation: every id is validated up front, and $elements is
     * mutated only after every check has passed, so a rejected call leaves
     * $elements exactly as it was — no half-moved state.
     *
     * Rejected (returns null, no mutation) when: $element_ids is empty or
     * contains a duplicate; any id cannot be found; or the ids are not all
     * siblings of one another (same parent — including "all at the page
     * root", when parent is null).
     *
     * @param array  &$elements          The full elements data (by reference).
     * @param array  $element_ids        The sibling element IDs to wrap, in any order.
     * @param array  $container_settings Settings for the new container (see create_container()'s $settings param).
     * @return array|null The newly-created container (with the moved elements as its children), or null if the operation could not be completed.
     */
    public function wrap_in_container( array &$elements, array $element_ids, array $container_settings = [] ): ?array {
        if ( empty( $element_ids ) || count( array_unique( $element_ids ) ) !== count( $element_ids ) ) {
            return null;
        }

        $parent_id = null;

        foreach ( $element_ids as $index => $id ) {
            $info = $this->find_parent_and_index( $elements, $id );
            if ( null === $info ) {
                return null;
            }

            if ( 0 === $index ) {
                $parent_id = $info['parent_id'];
            } elseif ( $info['parent_id'] !== $parent_id ) {
                return null;
            }
        }

        $existing_ids = $this->collect_ids( $elements );

        if ( null === $parent_id ) {
            return $this->wrap_siblings_in_container( $elements, $element_ids, $container_settings, false, $existing_ids );
        }

        return $this->wrap_siblings_under_parent( $elements, $parent_id, $element_ids, $container_settings, $existing_ids );
    }

    /**
     * Recursively locate $parent_id and delegate to
     * wrap_siblings_in_container() on its children array.
     *
     * @param array  &$elements          Elements to search (by reference).
     * @param string $parent_id          The (already-validated) common parent ID.
     * @param array  $element_ids        The sibling element IDs to wrap.
     * @param array  $container_settings Settings for the new container.
     * @param array  $existing_ids       Ids already taken on the page, to keep the new container's id unique.
     * @return array|null The newly-created container, or null.
     */
    private function wrap_siblings_under_parent(
        array &$elements,
        string $parent_id,
        array $element_ids,
        array $container_settings,
        array $existing_ids
    ): ?array {
        foreach ( $elements as &$element ) {
            if ( ( $element['id'] ?? '' ) === $parent_id ) {
                if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
                    return null;
                }
                return $this->wrap_siblings_in_container( $element['elements'], $element_ids, $container_settings, true, $existing_ids );
            }

            if ( ! empty( $element['elements'] ) ) {
                $result = $this->wrap_siblings_under_parent( $element['elements'], $parent_id, $element_ids, $container_settings, $existing_ids );
                if ( null !== $result ) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Core mutation for wrap_in_container(): given the sibling array that
     * (per the caller's prior validation) contains every id in
     * $element_ids, build the new container, move the matching elements
     * into it in their original order, and splice the container in at the
     * position $element_ids[0] occupied among the elements that are NOT
     * moving.
     *
     * $siblings is built up into local $moved/$remaining arrays and only
     * reassigned at the very end, once every id has been confirmed found —
     * so a mismatch (defensive; should not happen given the caller's own
     * validation) leaves $siblings untouched.
     *
     * @param array  &$siblings          The parent's children array (by reference) — page root, or an element's 'elements'.
     * @param array  $element_ids        The sibling element IDs to wrap, in any order.
     * @param array  $container_settings Settings for the new container.
     * @param bool   $is_inner           Whether the new container is nested (true) or at the page root (false).
     * @param array  $existing_ids       Ids already taken on the page, to keep the new container's id unique.
     * @return array|null The newly-created container, or null if not every id was found in $siblings.
     */
    private function wrap_siblings_in_container(
        array &$siblings,
        array $element_ids,
        array $container_settings,
        bool $is_inner,
        array $existing_ids
    ): ?array {
        $id_lookup    = array_flip( $element_ids );
        $insert_index = null;
        $moved        = [];
        $remaining    = [];
        $found        = [];

        foreach ( $siblings as $sibling ) {
            $id = $sibling['id'] ?? '';

            if ( isset( $id_lookup[ $id ] ) ) {
                if ( $id === $element_ids[0] ) {
                    $insert_index = count( $remaining );
                }
                $moved[]        = $sibling;
                $found[ $id ]   = true;
            } else {
                $remaining[] = $sibling;
            }
        }

        if ( null === $insert_index || count( $found ) !== count( $id_lookup ) ) {
            return null;
        }

        $container              = $this->create_container( 'row', $container_settings, $is_inner );
        $container['id']        = $this->generate_element_id( $existing_ids );
        $container['elements']  = $moved;

        array_splice( $remaining, $insert_index, 0, [ $container ] );
        $siblings = $remaining;

        return $container;
    }

    /**
     * Generate a unique 7-character element ID (matching Elementor's format).
     *
     * When $existing_ids is supplied, the generated ID is checked against
     * it and regenerated until it does not collide with any of them. With
     * no argument, behaviour is unchanged from before (a single random
     * draw with no collision check).
     *
     * @param array $existing_ids Element IDs already present on the page, to avoid colliding with.
     * @return string The generated ID.
     */
    public function generate_element_id( array $existing_ids = [] ): string {
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
        // Insert after specific element
        if ( $after_id !== null ) {
            foreach ( $elements as $index => $el ) {
                if ( ( $el['id'] ?? '' ) === $after_id ) {
                    array_splice( $elements, $index + 1, 0, [ $new_element ] );
                    return true;
                }
            }
            return false;
        }

        // Insert at position
        if ( $position !== null ) {
            array_splice( $elements, $position, 0, [ $new_element ] );
            return true;
        }

        // Append
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
     * @param string $text      The text to truncate.
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
}
