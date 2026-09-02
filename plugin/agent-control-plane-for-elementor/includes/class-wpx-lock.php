<?php
/**
 * WPX Post Lock
 *
 * A thin wrapper around WordPress core's own editor-lock primitive so that
 * `wpx` writes play by the same rules a human's browser already understands,
 * instead of inventing a parallel one it would have to keep in sync.
 *
 * === Ground truth: what `_edit_lock` actually is ===
 *
 * `_edit_lock` post meta is a single string `"TIMESTAMP:USER_ID"`, written by
 * core's `wp_set_post_lock()` (wp-admin/includes/post.php). Core's
 * `wp_check_post_lock()` treats it as a live, blocking lock only when all of
 * the following hold (verified by reading wp-admin/includes/post.php in this
 * checkout, WordPress 7.1):
 *
 *   - USER_ID resolves to a real `WP_User` — `get_userdata( $user_id )` must
 *     be truthy. A lock naming a deleted/nonexistent user is discarded
 *     entirely and treated as no lock at all. Confirmed empirically:
 *     `get_userdata( 0 )` is `false` on this install, same as any other
 *     nonexistent ID — there is nothing special about 0 here, it just never
 *     corresponds to a row in `wp_users`.
 *   - TIMESTAMP is newer than `time() - apply_filters( 'wp_check_post_lock_window', 150 )`.
 *     150 seconds is core's own default and is exactly what this class uses
 *     for staleness — see `staleness_window()`. Documented at
 *     wp-admin/includes/ajax-actions.php (the same filter core's own
 *     heartbeat handler consults when deciding whether a lock has expired).
 *   - The caller isn't USER_ID itself (`get_current_user_id() !== $user`) —
 *     core never lets an editor session block itself.
 *
 * === Ground truth: Elementor keeps no lock state of its own ===
 *
 * Elementor 4.2.4 (wp-content/plugins/elementor) does not maintain a separate
 * "someone has this document open" signal. `core/editor/editor.php::lock_post()`
 * and `::get_locked_user()` call core's `wp_set_post_lock()` / `wp_check_post_lock()`
 * directly — requiring `wp-admin/includes/post.php` on demand, since it is
 * pure function declarations with no top-level side effects and is not
 * autoloaded outside wp-admin. `includes/heartbeat.php::heartbeat_received()`
 * calls `get_locked_user()` on every heartbeat tick (~15s, WordPress's default
 * pulse) while the editor is open, refreshes the lock via `lock_post()` when
 * nobody else holds it, and surfaces the "someone else is editing" banner in
 * the browser via `$response['locked_user']` when someone else does. So
 * basing this class on core's `_edit_lock` *is* basing it on Elementor's real
 * signal — there is nothing else to defer to.
 *
 * === Identity under WP-CLI: acquire() as the CLI user, or as nobody ===
 *
 * `get_current_user_id()` is 0 for a typical `wp wpx ...` invocation (no
 * `--user`). Core's `wp_set_post_lock()` already refuses to write a lock for
 * user 0 (it returns `false` before touching post meta), and this class
 * respects that refusal rather than working around it, for two reasons:
 *
 *   1. It would not work anyway. `get_userdata( 0 )` is `false`, so a lock
 *      written as `"time:0"` would be invisible to `wp_check_post_lock()`
 *      for *every* browser session, including a legitimate human's. It would
 *      protect no one — pure theatre.
 *   2. Silently borrowing a real human's identity instead (e.g. the site's
 *      sole administrator, so the lock "counts") is actively worse than
 *      doing nothing. If that same human is the one with the editor open,
 *      `get_current_user_id() === $user` becomes true *for them*, and core's
 *      own "don't block yourself" rule waves them straight through — mid
 *      agent-write, on their own document. A lock that exempts the one
 *      person most likely to be using the editor is worse than no lock at
 *      all, because it creates false confidence instead of none.
 *
 * So: `acquire()` identifies as the CLI user when there is one — an operator
 * who ran `wp --user=<id> wpx ...` has a real, attributable identity, and it
 * is correct for Elementor to warn every *other* session while letting that
 * same person's own browser tabs through unaffected. When there is no
 * current user (the common case), `acquire()` honestly does nothing and
 * returns `false`; a `wpx` run with no `--user` gets no browser-visible lock
 * of its own. `is_writable()` and `check()` are unaffected by this — they
 * fully detect and respect any *live* lock left by an actual human's browser
 * regardless of which identity `wpx` happens to be running as.
 *
 * @package WPX
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPX_Lock {

    /**
     * Make sure core's post-locking functions are loaded.
     *
     * wp-admin/includes/post.php is not autoloaded outside wp-admin (WP-CLI
     * included). It declares functions only, with no top-level side effects,
     * so requiring it on demand is safe — this is the exact guard Elementor
     * itself uses in core/editor/editor.php before calling wp_set_post_lock()
     * / wp_check_post_lock().
     *
     * @return void
     */
    private function ensure_core_loaded(): void {
        if ( ! function_exists( 'wp_check_post_lock' ) || ! function_exists( 'wp_set_post_lock' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
    }

    /**
     * The staleness window core itself uses, in seconds.
     *
     * Mirrors `wp_check_post_lock()`'s own `apply_filters( 'wp_check_post_lock_window', 150 )`
     * call so a site that customizes the filter stays consistent between
     * `wpx` and the editor. Default 150 seconds, documented at
     * wp-admin/includes/ajax-actions.php.
     *
     * @return int Window in seconds.
     */
    private function staleness_window(): int {
        /** This filter is documented in wp-admin/includes/ajax-actions.php */
        return (int) apply_filters( 'wp_check_post_lock_window', 150 );
    }

    /**
     * Read and parse the raw `_edit_lock` meta the same way core does.
     *
     * @param int $post_id The post ID.
     * @return array{time: int, user_id: int}|null Null if no lock meta is present.
     */
    private function read_raw_lock( int $post_id ): ?array {
        $raw = get_post_meta( $post_id, '_edit_lock', true );

        if ( ! $raw ) {
            return null;
        }

        $parts = explode( ':', (string) $raw );
        $time  = (int) ( $parts[0] ?? 0 );
        $user  = isset( $parts[1] ) ? (int) $parts[1] : (int) get_post_meta( $post_id, '_edit_last', true );

        return [
            'time'    => $time,
            'user_id' => $user,
        ];
    }

    /**
     * Produce a structured verdict on a post's current lock state.
     *
     * @param int $post_id The post ID.
     * @return array{
     *     locked: bool,
     *     user_id: int|null,
     *     user_name: string|null,
     *     age_seconds: int|null,
     *     stale: bool,
     *     reason: string
     * }
     */
    public function check( int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return [
                'locked'      => false,
                'user_id'     => null,
                'user_name'   => null,
                'age_seconds' => null,
                'stale'       => false,
                'reason'      => "Post #{$post_id} does not exist.",
            ];
        }

        $lock = $this->read_raw_lock( $post_id );

        if ( null === $lock ) {
            return [
                'locked'      => false,
                'user_id'     => null,
                'user_name'   => null,
                'age_seconds' => null,
                'stale'       => false,
                'reason'      => 'Not locked; no one has this post open in the editor.',
            ];
        }

        $age_seconds = max( 0, time() - $lock['time'] );
        $user        = get_userdata( $lock['user_id'] );

        if ( ! $user ) {
            return [
                'locked'      => false,
                'user_id'     => $lock['user_id'],
                'user_name'   => null,
                'age_seconds' => $age_seconds,
                'stale'       => $age_seconds > $this->staleness_window(),
                'reason'      => "Lock references WordPress user #{$lock['user_id']}, who no longer exists; treated as unlocked.",
            ];
        }

        $window  = $this->staleness_window();
        $stale   = $age_seconds > $window;
        $ago     = human_time_diff( $lock['time'], time() );
        $is_self = get_current_user_id() > 0 && get_current_user_id() === $lock['user_id'];

        if ( $stale ) {
            $reason = "Last held by {$user->display_name} {$ago} ago, past the {$window}s staleness window; no longer considered active.";
        } elseif ( $is_self ) {
            $reason = "Held by you ({$user->display_name}, user #{$lock['user_id']}) since {$ago} ago; this is your own session.";
        } else {
            $reason = "Locked by {$user->display_name} (user #{$lock['user_id']}), who opened the editor {$ago} ago.";
        }

        return [
            'locked'      => true,
            'user_id'     => $lock['user_id'],
            'user_name'   => $user->display_name,
            'age_seconds' => $age_seconds,
            'stale'       => $stale,
            'reason'      => $reason,
        ];
    }

    /**
     * The gate the save layer should call before every write.
     *
     * A stale lock never blocks. A live lock blocks unless it is held by
     * this same session (never block on a lock this process itself holds)
     * or `$force` is explicitly true. `$force` is never inferred — it must
     * be a deliberate argument from the caller.
     *
     * @param int  $post_id The post ID.
     * @param bool $force   If true, a live lock is overridden rather than blocking.
     * @return array{ok: bool, reason: string}
     */
    public function is_writable( int $post_id, bool $force = false ): array {
        $verdict = $this->check( $post_id );

        if ( ! $verdict['locked'] || $verdict['stale'] ) {
            return [
                'ok'     => true,
                'reason' => $verdict['reason'],
            ];
        }

        $is_self = get_current_user_id() > 0 && get_current_user_id() === $verdict['user_id'];

        if ( $is_self ) {
            return [
                'ok'     => true,
                'reason' => $verdict['reason'],
            ];
        }

        if ( $force ) {
            return [
                'ok'     => true,
                'reason' => "{$verdict['reason']} Overridden with force=true.",
            ];
        }

        return [
            'ok'     => false,
            'reason' => "{$verdict['reason']} Refusing to write: saving now risks being silently discarded the moment they save in the editor.",
        ];
    }

    /**
     * Take the post lock for the duration of a `wpx` write.
     *
     * Delegates to core's `wp_set_post_lock()`, which — deliberately, see
     * this class's docblock — only writes a lock when there is a real
     * current user (`get_current_user_id() > 0`, i.e. `wpx` was invoked with
     * `--user=<id>`). With no current user this is a no-op that returns
     * `false`; it never fabricates an identity.
     *
     * @param int $post_id The post ID.
     * @return bool True if a lock was actually written.
     */
    public function acquire( int $post_id ): bool {
        if ( ! get_post( $post_id ) ) {
            return false;
        }

        $this->ensure_core_loaded();

        return false !== wp_set_post_lock( $post_id );
    }

    /**
     * Release a lock this session holds.
     *
     * Only clears `_edit_lock` when it is currently owned by the current
     * user — a lock left by someone else, including a real human's browser,
     * is never this process's to clear.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function release( int $post_id ): void {
        $lock = $this->read_raw_lock( $post_id );

        if ( null === $lock ) {
            return;
        }

        $current = get_current_user_id();

        if ( $current > 0 && $current === $lock['user_id'] ) {
            delete_post_meta( $post_id, '_edit_lock' );
        }
    }

    /**
     * A read-only lock report for a `wpx` status command.
     *
     * @param int $post_id The post ID.
     * @return array{
     *     post_id: int,
     *     locked: bool,
     *     user_id: int|null,
     *     user_name: string|null,
     *     age_seconds: int|null,
     *     stale: bool,
     *     reason: string,
     *     staleness_window_seconds: int,
     *     held_by_current_session: bool
     * }
     */
    public function describe( int $post_id ): array {
        $verdict = $this->check( $post_id );

        return array_merge(
            $verdict,
            [
                'post_id'                  => $post_id,
                'staleness_window_seconds' => $this->staleness_window(),
                'held_by_current_session'  => $verdict['locked']
                    && get_current_user_id() > 0
                    && get_current_user_id() === $verdict['user_id'],
            ]
        );
    }
}
