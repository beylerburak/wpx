# wpx — agent-native CLI for WordPress + Elementor

**Edit real WordPress sites and Elementor designs from the terminal, safely enough to hand to an AI agent.**

wpx is a Go CLI plus a WordPress plugin. It gives coding agents (Claude Code, Codex, Cursor) and humans a
structured, reversible way to read and rewrite Elementor pages without opening a browser.

The design goal is not "an AI website builder". It is that a production site can be edited by an agent
without silently breaking — so every write is validated against Elementor's own parsers, snapshotted before
it lands, and refused outright when it cannot be made safely.

> **Status: early.** Interfaces will change. It has been exercised end to end against WordPress 7.1 /
> Elementor 4.2.4 with a 54-assertion integration suite, but it has not been run against a wide range of
> sites. Take a backup before pointing it at anything you care about.

## Quick start

```bash
# 1. Build the CLI
go install github.com/beylerburak/wpx/cmd/wpx@latest

# 2. Download wpx.zip from the matching GitHub release, then either upload it
#    in wp-admin (Plugins → Add New → Upload Plugin), or use WP-CLI:
wp plugin install ./wpx.zip --activate

# 3. Connect — remote over SSH, or a site on this machine
wpx connect mysite --ssh user@server.com --path /var/www/html
wpx connect dev --local --path /path/to/wordpress

# 4. Look around
wpx site info
wpx elementor tree 241
```

On panel hosting (Plesk/cPanel), PHP is usually not on the default PATH, which breaks WP-CLI's
`#!/usr/bin/env php` shebang. Point `env` at the right PATH with `--wp-env`, and use `--ssh siteuser@host`
rather than root — running as root makes `WP_Filesystem` fall back to FTP, and Elementor's CSS regeneration
fails as a result:

```bash
wpx connect mysite --ssh siteuser@host --path /var/www/vhosts/site/httpdocs \
  --wp-env PATH=/opt/plesk/php/8.4/bin:/usr/local/bin:/usr/bin
```

`--wp-bin` (works for both `--ssh` and `--local`) points at a specific `wp` binary instead of relying on
PATH; `--wp-env KEY=VALUE` is repeatable and only valid with `--ssh`.

## Architecture

```
Developer / AI agent
        │  shell commands
        ▼
    wpx CLI (Go)
        │  SSH, or direct exec for a local site
        ▼
    WP-CLI  ──  wp wpx <command>
        ▼
    WPX WordPress plugin
        │
   ┌────┴─────┬──────────────┬─────────────┐
   ▼          ▼              ▼             ▼
 classic    atomic       controls        lock
 bridge     bridge      (V4 schema)    (editor)
```

The plugin holds all the domain knowledge; the Go binary is transport and presentation. That split is
deliberate — the same plugin serves any front end, and the CLI needs no Elementor knowledge of its own.

## Commands

### WordPress

```bash
wpx site info                    # WordPress, theme, plugins, Elementor versions
wpx capabilities                 # What this site supports
wpx plugins list|install|activate|deactivate
wpx pages                        # Elementor-built pages
wpx option get|set <key> [value]
wpx history                      # Operation log
wpx undo <operation_id>          # Restore the pre-write snapshot
```

### Reading a page

```bash
wpx elementor tree 241                    # Element hierarchy with IDs
wpx elementor get 241 f921a02             # One element in full
wpx elementor lock 241                    # Is anyone editing this page right now?
```

`tree` prints IDs you pass to every other command:

```
PAGE 241: Homepage
container [a81f3a1] "flex(column)"
├─ heading [f921a02] "Build Better Products."
│  align: left
│  tag: h1
└─ container [72cc104] "flex(row)"
   ├─ button [213aa05] "Start a Project"
   │  url: /contact
   └─ image [91bc206] "hero.png"
```

### Editing

```bash
wpx elementor set 241 f921a02 --settings '{"title":"New Heading"}'

wpx elementor style 241 f921a02 \
  --desktop '{"typography_font_size":"72px"}' \
  --tablet  '{"typography_font_size":"52px"}' \
  --mobile  '{"typography_font_size":"38px"}'
```

Style writes resolve their required group-control toggles automatically. Setting `typography_font_size`
without `typography_typography` produces **no CSS at all** in Elementor — wpx reads the widget's real
control schema to find the gate rather than guessing at it from the key name.

### Structure

```bash
wpx elementor add-widget 241 --type button --parent a81f3a1 --settings '{"text":"Go"}'
wpx elementor duplicate 241 72cc104
wpx elementor delete 241 91bc206
wpx elementor move 241 213aa05 --into 72cc104 --position 0

wpx elementor container create 241 --parent a81f3a1 --grid-columns 3 --gap 24
wpx elementor wrap 241 f921a02 41ab203 --direction row --gap 32
```

`wrap` moves a set of siblings into a new container as one operation, so a failure cannot leave the page
half-rearranged.

### Global styles

```bash
wpx elementor globals colors
wpx elementor globals typography
wpx elementor globals set-color primary "#111111"
wpx elementor globals site-settings
```

## Safety

Every write goes through the same sequence: **guard → snapshot → record intent → mutate → persist → confirm.**

- **`--dry-run`** on every write reports what would actually happen — it rehearses the operation rather than
  predicting it, so an impossible edit is refused at dry-run time instead of being green-lit and then failing.
- **`undo`** restores a full pre-write snapshot of the document, not an inverse operation. Replaying an
  inverse cannot express where a deleted element used to sit, or remove settings keys that did not exist
  before a change.
- **A write that cannot be recorded is refused**, because it would not be undoable.
- **Validation before persistence.** V4 props and styles are parsed by Elementor's own `Props_Parser` /
  `Style_Parser`; anything they reject is never written.
- **Editor locking.** wpx refuses to write to a page someone has open in the Elementor editor — their browser
  holds the whole document and would silently discard your change on save. `--force` overrides it deliberately.
- **Scoped cache invalidation.** Editing one page regenerates that page's CSS, not the whole site's.

```bash
wpx elementor set 241 f921a02 --settings '{"title":"Test"}' --dry-run
wpx history
wpx undo op_0a1b2c3d4e5f
```

## Elementor V4 (atomic) support

Elementor 4 ships a second element model alongside the classic one, and both appear on real sites. wpx
detects which model a page uses and routes accordingly.

|                          | classic | V4 (atomic) |
|--------------------------|:-------:|:-----------:|
| read (`tree`, `get`)     | ✅ | ✅ |
| `set` element properties | ✅ | ✅ |
| `style`                  | ✅ | ✅ |
| `delete`, `move`         | ✅ | ✅ |
| `add-widget`             | ✅ | — |
| `duplicate`, `wrap`, `container create` | ✅ | — |

Unsupported V4 operations are refused with an explanation rather than half-applied. Pages that **mix** both
models are refused for writes entirely: there is no single set of conventions that would not corrupt the
other half.

## Using it with an AI agent

Add to your project's `CLAUDE.md` (or the equivalent for your agent):

```
When modifying WordPress, use the `wpx` CLI.
Run `wpx elementor tree <page_id>` before making changes.
Always use --dry-run before destructive operations.
```

Then describe the change you want. A typical loop:

```
wpx elementor tree 241                     # read the structure
wpx elementor get 241 f921a02              # inspect a specific element
wpx elementor set ... --dry-run            # preview
wpx elementor set ...                      # apply
wpx undo op_...                            # if it went wrong
```

## Project layout

```
cmd/wpx/                     Go CLI entry point
internal/
  commands/                  cobra command handlers
  config/                    ~/.wpx/config.yaml
  wpcli/                     transport dispatch (local exec or SSH)
  ssh/                       SSH execution
  output/                    tree, table, diff, lock formatters
plugin/agent-control-plane-for-elementor/  the WordPress plugin
  includes/
    class-wpx-elementor-bridge.php    classic document model
    class-wpx-atomic-bridge.php       V4 document model + prop envelopes
    class-wpx-atomic-styles.php       V4 class-based style system
    class-wpx-elementor-controls.php  control schema + group-control toggles
    class-wpx-elementor-compat.php    Elementor version differences, CSS regeneration
    class-wpx-elementor-save.php      write orchestration
    class-wpx-elementor-globals.php   kit colours and typography
    class-wpx-lock.php                editor lock
    class-wpx-operation-history.php   snapshots and undo
    class-wpx-cli-commands.php        wp wpx command registration
tests/integration/           regression suite (see below)
agent-rules/                 drop-in agent instructions
```

## Requirements

- Go 1.21+ to build the CLI
- WordPress 6.8+, PHP 8.0+
- Elementor 3.6+ (flexbox containers); developed and tested against 4.2.4
- WP-CLI on the target machine
- SSH access, or a WordPress install on the same machine (`--local`)

## Development

```bash
make build              # build ./build/wpx
make test               # Go unit tests
make test-integration   # end-to-end suite against a real WordPress
make test-integration-docker # disposable WordPress + Elementor stack
make package-plugin     # build ./build/wpx.zip for wp-admin
```

The integration suite is the useful one. Every assertion in it encodes a defect that was reproduced against
a real WordPress + Elementor install — a style write that stored data but rendered no CSS, an undo that put
a deleted element back in the wrong place, a page edit that wiped an unrelated page's stylesheet. They are
written to fail on the code that had those bugs, so a passing run means something.

It needs a WordPress install with Elementor and the plugin active, and it **creates, rewrites and deletes
pages** — point it at a scratch site, never at anything real:

```bash
WP_ROOT=/path/to/wordpress WP_BIN=/path/to/wp make test-integration
```

For a reproducible scratch site, install Docker and run:

```bash
make test-integration-docker
```

This starts an isolated WordPress/MariaDB stack, installs the requested Elementor version, runs the suite,
and removes the containers and volumes afterwards. The defaults are the currently verified pair; versions
can be overridden without editing files:

```bash
WORDPRESS_VERSION=7.1 ELEMENTOR_VERSION=4.2.4 make test-integration-docker
```

## Compatibility

| WordPress | PHP | Elementor | Suite | Notes |
|-----------|-----|-----------|-------|-------|
| 6.8 | 8.3 | 4.2.4 | 54 assertions | Verified end to end, including V4 atomic pages |
| 7.1 | 8.3 | 4.2.4 | 54 assertions | Verified end to end, including V4 atomic pages |

Only combinations that have passed the full disposable integration suite are listed as verified. The stated
minimums (WordPress 6.8, PHP 8.0, Elementor 3.6) describe the intended support floor, not yet a fully tested
cross-product. CI reruns every verified row on each change; adding a row means running the same suite with
the two version overrides above.

## Releases

Tags are the source of truth for releases. A tag such as `v0.1.0` builds versioned macOS and Linux CLI
binaries, an installable `wpx.zip`, and SHA-256 checksums, then attaches them to a GitHub release. The plugin
header version must match the tag, so a mismatched release fails instead of publishing inconsistent pieces.

## License

[GPL v2 or later](LICENSE), matching WordPress.
