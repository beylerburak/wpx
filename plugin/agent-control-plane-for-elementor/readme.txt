=== Agent Control Plane for Elementor ===
Contributors: burakbeyler
Tags: wp-cli, elementor, developer-tools, automation
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 0.1.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely inspect and edit Elementor pages from WP-CLI, with validation, snapshots, editor locks, and undo support.

== Description ==

WPX adds a structured `wp wpx` command namespace to WordPress. It lets developers, automation tools, and coding agents inspect and modify Elementor pages through WP-CLI.

The plugin works on the WordPress server and does not contact an external service. You can use its WP-CLI commands directly or install the optional open-source `wpx` Go client for local and SSH-based workflows.

Write operations are designed to fail safely:

* A snapshot is stored before each mutation.
* Writes are refused when the Elementor editor has an active lock, unless explicitly forced.
* Dry runs exercise the same validation path without persisting changes.
* Elementor's own parsers validate supported V4 atomic properties and styles.
* Undo restores the complete pre-write document snapshot.

WPX supports reading classic and Elementor V4 atomic documents. Some structural mutations are currently limited to classic documents; unsupported operations are refused without changing the page.

Source code, CLI downloads, and documentation are available at https://github.com/beylerburak/wpx.

== Installation ==

1. Install and activate Elementor.
2. Upload the WPX plugin ZIP through Plugins > Add New > Upload Plugin, or install it with WP-CLI.
3. Activate WPX.
4. Confirm the integration with `wp wpx capabilities`.

The optional cross-platform client can be installed with:

`go install github.com/beylerburak/wpx/cmd/wpx@latest`

Then connect it to a local or SSH-accessible WordPress installation. WP-CLI must be available on the target machine.

== Frequently Asked Questions ==

= Does WPX send site data to an external service? =

No. The WordPress plugin registers local WP-CLI commands and makes no external service calls. If you use the optional Go client over SSH, authentication is handled by your existing SSH configuration and agent.

= Does this provide a WordPress admin screen? =

No. WPX is intentionally a WP-CLI integration for developers, automation, and coding agents.

= Does it work without the Go client? =

Yes. Every capability is available directly through the `wp wpx` command namespace. The Go client provides connection aliases and human-readable terminal output.

= Is Elementor Pro required? =

No. Elementor is required; Elementor Pro is optional.

= Should I use this on a production site? =

WPX is early software. Test commands with `--dry-run` where supported and take a normal site backup before modifying production content.

== Changelog ==

= 0.1.1 =

* Prepared the plugin for WordPress.org distribution with a canonical package identity and directory metadata.
* Declared Elementor as a required plugin dependency.
* Hardened operation-history database queries and added automated Plugin Check validation.

= 0.1.0 =

* Initial public release.
* Added structured Elementor tree and element inspection.
* Added guarded classic document edits, snapshots, history, dry runs, and undo.
* Added Elementor V4 atomic document reading, property updates, and responsive styles.
* Added editor-lock protection and scoped CSS regeneration.
