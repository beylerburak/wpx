<?php
/**
 * WPX Operation History
 *
 * Tracks all operations performed through WPX for auditing and undo support.
 *
 * Undo works by restoring a full pre-write snapshot (`page_snapshot`) of
 * `_elementor_data` (or the Elementor kit settings for global operations),
 * rather than by replaying an element-shaped diff. This is deliberate:
 * a diff of "just the element" or "just the settings that changed" cannot
 * express where a deleted element used to live in the tree, cannot express
 * the removal of settings keys that did not exist before a change, and is
 * lost entirely if the process dies between saving the write and recording
 * it. A full snapshot taken *before* the write, restored wholesale, is
 * correct for every operation type by construction.
 *
 * Recording is two-phase (begin/complete/fail) so that a fatal error
 * partway through a write still leaves a recoverable audit trail: begin()
 * stores the snapshot and marks the row `pending` *before* anything is
 * written to the site, complete() flips it to `applied` once the write
 * (and any post-write bookkeeping) has succeeded, and fail() flips it to
 * `failed` while keeping the snapshot, so the change can still be found
 * and manually reconciled even though `wpx` never got to confirm it.
 *
 * Valid `status` values: pending | applied | failed | undone.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Operation_History {

    /**
     * Table name (without prefix).
     */
    const TABLE_NAME = 'wpx_operations';

    /**
     * Current schema version. Bump this whenever the CREATE TABLE below
     * changes, so create_table() knows to run dbDelta() again on sites
     * that already have the table.
     */
    const SCHEMA_VERSION = '2.0.0';

    /**
     * Option name used to track which schema version is installed.
     */
    const SCHEMA_VERSION_OPTION = 'wpx_operations_schema_version';

    /**
     * The most recent $wpdb error captured by this class, if any.
     *
     * @var string|null
     */
    private static ?string $last_error = null;

    /**
     * Create (or upgrade) the operations table.
     *
     * Safe to call on every plugin load, not just on activation: dbDelta()
     * is idempotent by design, and this method additionally short-circuits
     * against an installed-version option so repeated calls on an
     * up-to-date site don't even touch dbDelta.
     *
     * Formatting notes (dbDelta is picky and will otherwise re-run the same
     * ALTER on every single call forever):
     *  - Column types must be lowercase.
     *  - "PRIMARY KEY" must be followed by exactly two spaces before the
     *    "(column)" that follows it; every other KEY/UNIQUE KEY definition
     *    takes exactly one space before its "(column)".
     *  - Each KEY definition must be on its own line.
     *  - `created_at`'s `DEFAULT CURRENT_TIMESTAMP` is left untouched from
     *    the original schema on purpose: dbDelta compares the column's
     *    *type* token (e.g. "datetime"), not its default clause, so
     *    changing the default here would not fix anything and risks
     *    tripping the well-known dbDelta bug where a mismatched
     *    CURRENT_TIMESTAMP default causes it to believe the column keeps
     *    changing and re-issue the same ALTER on every run.
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $installed_version = get_option( self::SCHEMA_VERSION_OPTION, '' );
        if ( self::SCHEMA_VERSION === $installed_version ) {
            return;
        }

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_id varchar(32) NOT NULL,
            command varchar(500) NOT NULL,
            target_type varchar(20) NOT NULL DEFAULT 'post',
            post_id bigint(20) unsigned DEFAULT NULL,
            element_id varchar(40) DEFAULT NULL,
            page_snapshot longtext DEFAULT NULL,
            revision_id bigint(20) unsigned DEFAULT NULL,
            before_state longtext DEFAULT NULL,
            after_state longtext DEFAULT NULL,
            actor varchar(191) DEFAULT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            undone_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_id (operation_id),
            KEY post_id (post_id),
            KEY created_at (created_at),
            KEY status (status),
            KEY target_type (target_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
    }

    /**
     * Generate a unique operation ID.
     */
    public static function generate_operation_id(): string {
        return 'op_' . bin2hex( random_bytes( 6 ) );
    }

    /**
     * Get the most recent $wpdb error captured by begin()/complete()/fail(),
     * for callers that need to know *why* an operation could not be
     * recorded (e.g. the table is missing because the plugin was updated
     * without reactivation).
     *
     * @return string|null The last error message, or null if the most
     *                      recent call succeeded.
     */
    public static function last_error(): ?string {
        return self::$last_error;
    }

    /**
     * Begin recording an operation, *before* the write it describes is
     * applied. Stores a `pending` row containing the command, target, and
     * a full pre-write snapshot, so that even a fatal error between this
     * call and complete()/fail() leaves a recoverable audit trail — the
     * change can be found (status = 'pending') and the snapshot used to
     * confirm what the site looked like immediately beforehand.
     *
     * @param array $args {
     *     Operation arguments.
     *
     *     @type string      $command       Required. The CLI command being executed.
     *     @type string      $target_type   Optional. 'post' or 'kit'. Default 'post'.
     *     @type int|null    $post_id       Optional. Post ID affected (the kit post ID for target_type 'kit').
     *     @type string|null $element_id    Optional. The Elementor element ID affected.
     *     @type mixed       $page_snapshot Optional. Full pre-write `_elementor_data` (or kit
     *                                      settings). Encoded with wp_json_encode() unless already a string.
     *     @type int|null    $revision_id   Optional. WP revision ID created before the write.
     *     @type mixed       $before_state  Optional. Legacy element-level "before" state, kept for
     *                                      callers using the record() back-compat wrapper.
     * }
     * @return string|null The operation ID, or null if the row could not be inserted
     *                      (see last_error() for why).
     */
    public static function begin( array $args ): ?string {
        global $wpdb;

        self::$last_error = null;

        if ( empty( $args['command'] ) ) {
            self::$last_error = 'begin() requires a "command" argument.';
            return null;
        }

        $page_snapshot = $args['page_snapshot'] ?? null;
        if ( ! is_null( $page_snapshot ) && ! is_string( $page_snapshot ) ) {
            $page_snapshot = wp_json_encode( $page_snapshot );
        }

        $before_state = $args['before_state'] ?? null;
        if ( ! is_null( $before_state ) && ! is_string( $before_state ) ) {
            $before_state = wp_json_encode( $before_state );
        }

        $operation_id = self::generate_operation_id();
        $table_name   = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WPX owns this audit table; no core CRUD API exists.
        $inserted = $wpdb->insert(
            $table_name,
            [
                'operation_id'  => $operation_id,
                'command'       => $args['command'],
                'target_type'   => $args['target_type'] ?? 'post',
                'post_id'       => $args['post_id'] ?? null,
                'element_id'    => $args['element_id'] ?? null,
                'page_snapshot' => $page_snapshot,
                'revision_id'   => $args['revision_id'] ?? null,
                'before_state'  => $before_state,
                'actor'         => self::current_actor(),
                'user_id'       => get_current_user_id(),
                'status'        => 'pending',
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ]
        );

        if ( false === $inserted ) {
            self::$last_error = $wpdb->last_error ?: 'Unknown error inserting operation row.';
            return null;
        }

        return $operation_id;
    }

    /**
     * Mark a pending operation as applied, i.e. the write it describes
     * completed successfully.
     *
     * @param string $operation_id The operation ID returned by begin().
     * @param mixed  $after_state  Optional. Legacy element-level "after" state, kept
     *                             for callers using the record() back-compat wrapper.
     * @return bool Whether the row was found and updated.
     */
    public static function complete( string $operation_id, mixed $after_state = null ): bool {
        global $wpdb;

        self::$last_error = null;

        if ( ! is_null( $after_state ) && ! is_string( $after_state ) ) {
            $after_state = wp_json_encode( $after_state );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit status must be persisted immediately.
        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'      => 'applied',
                'after_state' => $after_state,
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'operation_id' => $operation_id ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );

        if ( false === $updated ) {
            self::$last_error = $wpdb->last_error ?: 'Unknown error completing operation.';
            return false;
        }

        return $updated > 0;
    }

    /**
     * Mark an operation as failed. The pre-write snapshot recorded by
     * begin() is deliberately left in place: a half-applied write should
     * stay recoverable, not disappear along with the failure.
     *
     * @param string $operation_id The operation ID.
     * @param string $reason       Human-readable failure reason, stored for diagnostics.
     * @return bool Whether the row was found and updated.
     */
    public static function fail( string $operation_id, string $reason ): bool {
        global $wpdb;

        self::$last_error = null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit status must be persisted immediately.
        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'        => 'failed',
                'error_message' => $reason,
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'operation_id' => $operation_id ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );

        if ( false === $updated ) {
            self::$last_error = $wpdb->last_error ?: 'Unknown error failing operation.';
            return false;
        }

        return $updated > 0;
    }

    /**
     * Record a completed operation in one call.
     *
     * Thin wrapper over begin() + complete(), kept so existing callers
     * that already know the operation succeeded by the time they call this
     * (i.e. everything that used the old single-shot record() API) keep
     * working unchanged.
     *
     * @param string      $command      The CLI command that was executed.
     * @param int|null    $post_id      The post ID affected (if any).
     * @param string|null $element_id   The Elementor element ID affected (if any).
     * @param mixed       $before_state The state before the operation.
     * @param mixed       $after_state  The state after the operation.
     * @return string|null The operation ID, or null if the row could not be inserted
     *                      (see last_error() for why).
     */
    public static function record(
        string $command,
        ?int $post_id = null,
        ?string $element_id = null,
        mixed $before_state = null,
        mixed $after_state = null
    ): ?string {
        $operation_id = self::begin(
            [
                'command'      => $command,
                'post_id'      => $post_id,
                'element_id'   => $element_id,
                'before_state' => $before_state,
            ]
        );

        if ( null === $operation_id ) {
            return null;
        }

        self::complete( $operation_id, $after_state );

        return $operation_id;
    }

    /**
     * Get an operation by its ID, including its snapshot and state columns.
     *
     * @param string $operation_id The operation ID.
     * @return object|null The operation row or null.
     */
    public static function get( string $operation_id ): ?object {
        global $wpdb;

        $table_name = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operation reads must reflect the current audit state.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE operation_id = %s',
                $table_name,
                $operation_id
            )
        );

        return $row ?: null;
    }

    /**
     * Get the stored pre-write snapshot for an operation.
     *
     * @param string $operation_id The operation ID.
     * @return string|null The raw snapshot JSON, or null if the operation has no
     *                      snapshot recorded (or does not exist).
     */
    public static function get_snapshot( string $operation_id ): ?string {
        global $wpdb;

        $table_name = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshots are mutable audit data and must not be stale.
        $snapshot = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT page_snapshot FROM %i WHERE operation_id = %s',
                $table_name,
                $operation_id
            )
        );

        return is_null( $snapshot ) ? null : (string) $snapshot;
    }

    /**
     * List recent operations.
     *
     * The `page_snapshot`, `before_state` and `after_state` columns are
     * always excluded here — they can hold an entire page's Elementor JSON
     * and a listing must not drag that through the CLI for every row. Use
     * get() or get_snapshot() to fetch a specific operation's full state.
     *
     * @param int         $limit   Maximum number of operations to return.
     * @param int|null    $post_id Filter by post ID (optional).
     * @param string|null $status  Filter by status (optional): pending|applied|failed|undone.
     * @return array Array of operation rows (without snapshot/state columns).
     */
    public static function list_recent( int $limit = 20, ?int $post_id = null, ?string $status = null ): array {
        global $wpdb;

        $table_name = self::table_name();
        if ( $post_id && $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit log results must be current.
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, operation_id, command, target_type, post_id, element_id, revision_id, actor, user_id, status, error_message, created_at, updated_at, undone_at FROM %i WHERE post_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d',
                    $table_name,
                    $post_id,
                    $status,
                    $limit
                )
            );
        }

        if ( $post_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit log results must be current.
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, operation_id, command, target_type, post_id, element_id, revision_id, actor, user_id, status, error_message, created_at, updated_at, undone_at FROM %i WHERE post_id = %d ORDER BY created_at DESC LIMIT %d',
                    $table_name,
                    $post_id,
                    $limit
                )
            );
        }

        if ( $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit log results must be current.
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, operation_id, command, target_type, post_id, element_id, revision_id, actor, user_id, status, error_message, created_at, updated_at, undone_at FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d',
                    $table_name,
                    $status,
                    $limit
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit log results must be current.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, operation_id, command, target_type, post_id, element_id, revision_id, actor, user_id, status, error_message, created_at, updated_at, undone_at FROM %i ORDER BY created_at DESC LIMIT %d',
                $table_name,
                $limit
            )
        );
    }

    /**
     * Delete the oldest operations beyond a retention count, so the table
     * cannot grow without bound (snapshots are large).
     *
     * @param int $keep Number of most recent rows to retain.
     * @return int Number of rows deleted.
     */
    public static function prune( int $keep = 500 ): int {
        global $wpdb;

        if ( $keep < 0 ) {
            $keep = 0;
        }

        $table_name = self::table_name();

        if ( 0 === $keep ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit maintenance of WPX's own table.
            $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table_name ) );
            return false === $deleted ? 0 : (int) $deleted;
        }

        // The derived table is required by MySQL when selecting from the same
        // table being deleted. `%i` safely quotes both identifier occurrences.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit maintenance of WPX's own table.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE id NOT IN (SELECT id FROM (SELECT id FROM %i ORDER BY created_at DESC, id DESC LIMIT %d) AS retained)',
                $table_name,
                $table_name,
                $keep
            )
        );

        return false === $deleted ? 0 : (int) $deleted;
    }

    /**
     * Mark an operation as undone.
     *
     * @param string $operation_id The operation ID to undo.
     * @return bool Whether the operation was marked as undone.
     */
    public static function mark_undone( string $operation_id ): bool {
        global $wpdb;

        self::$last_error = null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit status must be persisted immediately.
        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'    => 'undone',
                'undone_at' => current_time( 'mysql' ),
            ],
            [ 'operation_id' => $operation_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        if ( false === $updated ) {
            self::$last_error = $wpdb->last_error ?: 'Unknown error marking operation undone.';
            return false;
        }

        return $updated > 0;
    }

    /**
     * Get the fully-prefixed operations table name.
     */
    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Determine a human-meaningful actor string for the current request.
     *
     * get_current_user_id() is always 0 under WP-CLI — which is `wpx`'s
     * main entry point — so the `user_id` column alone can't identify who
     * ran a command. Fall back to the system user WP-CLI is running as.
     *
     * @return string e.g. "cli:burak", "web:5", "web:guest".
     */
    private static function current_actor(): string {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $system_user = null;

            if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
                $pwuid       = posix_getpwuid( posix_geteuid() );
                $system_user = $pwuid['name'] ?? null;
            }

            if ( ! $system_user ) {
                $system_user = getenv( 'USER' ) ?: getenv( 'USERNAME' ) ?: 'unknown';
            }

            return 'cli:' . $system_user;
        }

        $user_id = get_current_user_id();

        return $user_id > 0 ? 'web:' . $user_id : 'web:guest';
    }
}
