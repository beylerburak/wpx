# WPX Agent Rules for Claude Code

When modifying WordPress sites, use the `wpx` CLI tool. Follow these rules:

## Before Making Changes
1. Run `wpx capabilities` to understand what's available on the site.
2. Run `wpx elementor tree <page_id>` to understand the page structure before editing.
3. Always use `--dry-run` before destructive operations to preview changes.

## Elementor Workflow
1. Use `wpx pages` to find Elementor-built pages.
2. Use `wpx elementor tree <page_id>` to see the element hierarchy.
3. Use `wpx elementor get <page_id> <element_id>` to inspect element details.
4. Use `wpx elementor set <page_id> <element_id> --settings '...' --dry-run` to preview.
5. Apply changes only after reviewing the dry-run diff.

## Key Commands
- `wpx site info` — Get WordPress version, plugins, theme info
- `wpx plugins list` — List installed plugins
- `wpx pages` — List Elementor pages
- `wpx elementor tree <page_id>` — Show element tree
- `wpx elementor get <page_id> <el_id>` — Element details
- `wpx elementor set <page_id> <el_id> --settings '...'` — Update settings
- `wpx elementor style <page_id> <el_id> --desktop/--tablet/--mobile '...'` — Responsive styles
- `wpx elementor add-widget <page_id> --type <type> --parent <id>` — Add widget
- `wpx elementor delete <page_id> <el_id>` — Remove element
- `wpx elementor move <page_id> <el_id> --into <parent> --position <n>` — Reorder
- `wpx elementor globals colors` — List global colors
- `wpx elementor globals set-color <id> <hex>` — Update global color
- `wpx history` — View operation log
- `wpx undo <operation_id>` — Revert a change

## Safety Rules
- Never skip `--dry-run` on first attempt.
- Use `wpx undo` if changes look wrong.
- Don't delete containers without checking their children first via `wpx elementor tree`.
- When making multiple changes, make them one at a time and verify each.

## Style Values
- Font sizes: use "72px", "2em", "1.5rem"
- Colors: use "#RRGGBB" format
- Padding/margin: use JSON object format: `{"unit":"px","top":"20","right":"15","bottom":"20","left":"15","isLinked":false}`
- Responsive: desktop is default, add `_tablet` or `_mobile` suffix for breakpoints
