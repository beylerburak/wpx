package output

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
	"strings"
	"time"
)

// SummaryMap holds an element's summarised settings.
//
// It decodes from either a JSON object or an empty JSON array: PHP encodes an
// empty associative array as [], so a plugin older than the fix that made this
// field a consistent object still decodes here rather than failing the whole
// tree. The CLI and the plugin are versioned separately and routinely drift.
type SummaryMap map[string]string

// UnmarshalJSON implements json.Unmarshaler.
func (s *SummaryMap) UnmarshalJSON(data []byte) error {
	trimmed := bytes.TrimSpace(data)

	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) || bytes.Equal(trimmed, []byte("[]")) {
		*s = nil
		return nil
	}

	var m map[string]string
	if err := json.Unmarshal(trimmed, &m); err != nil {
		return err
	}

	*s = m
	return nil
}

// Keys returns the summary keys in a stable order. Map iteration order is
// random in Go, and a tree whose lines reshuffle between runs is useless both
// for diffing output and for an agent trying to match on it.
func (s SummaryMap) Keys() []string {
	keys := make([]string, 0, len(s))
	for k := range s {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// TreeNode represents a node in the Elementor element tree.
type TreeNode struct {
	ID         string     `json:"id"`
	Type       string     `json:"type"`
	WidgetType string     `json:"widget_type,omitempty"`
	Label      string     `json:"label,omitempty"`
	Summary    SummaryMap `json:"summary,omitempty"`
	Depth      int        `json:"depth"`
	Children   []TreeNode `json:"children,omitempty"`
}

// PageTree represents the full page tree structure.
type PageTree struct {
	PostID int        `json:"post_id"`
	Title  string     `json:"title"`
	Type   string     `json:"type"`
	Nodes  []TreeNode `json:"nodes"`
}

// FormatTree renders a PageTree as a human-readable tree string.
// This is the agent-friendly format that shows element hierarchy.
func FormatTree(tree *PageTree) string {
	var sb strings.Builder

	sb.WriteString(fmt.Sprintf("PAGE %d: %s\n", tree.PostID, tree.Title))

	// Top-level elements are rendered without a connector: they are siblings at
	// the page root, not children of anything, and drawing ├─/└─ against them
	// implies a parent that does not exist.
	for _, node := range tree.Nodes {
		writeNodeLine(&sb, &node, "", "")
		writeSummary(&sb, &node, treeIndent)
		renderChildren(&sb, &node, "")
	}

	return sb.String()
}

// treeIndent is the width of a connector ("├─ "), used to align a top-level
// element's summary lines under its own name.
const treeIndent = "   "

// renderChildren renders a node's children beneath it.
func renderChildren(sb *strings.Builder, node *TreeNode, prefix string) {
	for i, child := range node.Children {
		isLast := i == len(node.Children)-1

		connector := "├─ "
		childPrefix := "│  "
		if isLast {
			connector = "└─ "
			childPrefix = "   "
		}

		writeNodeLine(sb, &child, prefix, connector)
		writeSummary(sb, &child, prefix+childPrefix)
		renderChildren(sb, &child, prefix+childPrefix)
	}
}

// writeNodeLine writes the single line identifying an element.
func writeNodeLine(sb *strings.Builder, node *TreeNode, prefix, connector string) {
	typeStr := node.Type
	if node.WidgetType != "" {
		typeStr = node.WidgetType
	}

	sb.WriteString(prefix)
	sb.WriteString(connector)
	sb.WriteString(fmt.Sprintf("%s [%s]", typeStr, node.ID))

	if node.Label != "" {
		sb.WriteString(fmt.Sprintf(" %q", node.Label))
	}

	sb.WriteString("\n")
}

// writeSummary writes a node's summarised settings underneath it, aligned to
// the start of the element name on the line above.
func writeSummary(sb *strings.Builder, node *TreeNode, prefix string) {
	for _, key := range node.Summary.Keys() {
		sb.WriteString(prefix)
		sb.WriteString(fmt.Sprintf("%s: %s\n", key, node.Summary[key]))
	}
}

// DiffEntry represents a single change in a diff.
type DiffEntry struct {
	Key string      `json:"key"`
	Old interface{} `json:"old"`
	New interface{} `json:"new"`
}

// FormatDiff renders a diff as a human-readable string with +/- indicators.
func FormatDiff(postID int, elementID string, elementType string, widgetType string, diffs []DiffEntry, dryRun bool) string {
	var sb strings.Builder

	// Header
	if widgetType != "" {
		sb.WriteString(fmt.Sprintf("Page: #%d\n", postID))
		sb.WriteString(fmt.Sprintf("Element: %s[%s]\n", widgetType, elementID))
	} else {
		sb.WriteString(fmt.Sprintf("Page: #%d\n", postID))
		sb.WriteString(fmt.Sprintf("Element: %s[%s]\n", elementType, elementID))
	}
	sb.WriteString("\n")

	// Diff lines
	for _, d := range diffs {
		oldStr := formatValue(d.Old)
		newStr := formatValue(d.New)

		sb.WriteString(fmt.Sprintf("  - %s: %s\n", d.Key, oldStr))
		sb.WriteString(fmt.Sprintf("  + %s: %s\n", d.Key, newStr))
	}

	// Footer
	sb.WriteString("\n")
	if dryRun {
		sb.WriteString("No changes applied.")
	} else {
		sb.WriteString("Changes applied.")
	}

	return sb.String()
}

// FormatTable renders a slice of maps as an ASCII table.
func FormatTable(headers []string, rows []map[string]string) string {
	if len(rows) == 0 {
		return "(no results)"
	}

	// Calculate column widths
	widths := make(map[string]int)
	for _, h := range headers {
		widths[h] = len(h)
	}
	for _, row := range rows {
		for _, h := range headers {
			val := row[h]
			if len(val) > widths[h] {
				widths[h] = len(val)
			}
		}
	}

	var sb strings.Builder

	// Header row
	for i, h := range headers {
		if i > 0 {
			sb.WriteString("  ")
		}
		sb.WriteString(fmt.Sprintf("%-*s", widths[h], strings.ToUpper(h)))
	}
	sb.WriteString("\n")

	// Separator
	for i, h := range headers {
		if i > 0 {
			sb.WriteString("  ")
		}
		sb.WriteString(strings.Repeat("─", widths[h]))
	}
	sb.WriteString("\n")

	// Data rows
	for _, row := range rows {
		for i, h := range headers {
			if i > 0 {
				sb.WriteString("  ")
			}
			sb.WriteString(fmt.Sprintf("%-*s", widths[h], row[h]))
		}
		sb.WriteString("\n")
	}

	return sb.String()
}

// FormatJSON renders any value as pretty-printed JSON.
func FormatJSON(v interface{}) (string, error) {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return "", err
	}
	return string(data), nil
}

// KV is an ordered label/value pair. Used where display order matters and a
// map's randomised iteration would make output reshuffle between runs.
type KV struct {
	Key   string
	Value string
}

// FormatMutation renders a human-readable summary for an Elementor operation
// that creates or relocates elements (duplicate, container create, wrap)
// rather than diffing settings on an element that already existed.
//
// Dry-run output carries its own banner rather than relying solely on the
// footer line FormatDiff uses: an agent is told to run --dry-run before a
// real write, so the two must be impossible to mistake for one another even
// at a glance, not just on close reading.
func FormatMutation(postID int, action string, details []KV, dryRun bool) string {
	var sb strings.Builder

	if dryRun {
		sb.WriteString("=== DRY RUN — no changes applied ===\n\n")
	}

	sb.WriteString(fmt.Sprintf("Page: #%d\n", postID))
	sb.WriteString(action)
	sb.WriteString("\n")

	for _, d := range details {
		sb.WriteString(fmt.Sprintf("  %s: %s\n", d.Key, d.Value))
	}

	sb.WriteString("\n")
	if dryRun {
		sb.WriteString("No changes applied.")
	} else {
		sb.WriteString("Changes applied.")
	}
	sb.WriteString("\n")

	return sb.String()
}

// LockStatus is the plugin's response to elementor-lock-status.
//
// The plugin reports a flat structure rather than a nested holder object, and
// notably does not report a write verdict directly: whether a write is allowed
// follows from the lock being live and not stale, which is the same rule the
// save layer applies. Deriving it here rather than trusting a separate field
// keeps the two from drifting apart and reporting contradictory things.
type LockStatus struct {
	PostID                 int    `json:"post_id"`
	Locked                 bool   `json:"locked"`
	UserID                 *int   `json:"user_id"`
	UserName               string `json:"user_name"`
	AgeSeconds             *int   `json:"age_seconds"`
	Stale                  bool   `json:"stale"`
	Reason                 string `json:"reason"`
	StalenessWindowSeconds int    `json:"staleness_window_seconds"`
	HeldByCurrentSession   bool   `json:"held_by_current_session"`
}

// WriteAllowed reports whether a write would currently go through without
// --force. A stale lock does not block, and neither does a lock this session
// holds itself.
func (l *LockStatus) WriteAllowed() bool {
	return !l.Locked || l.Stale || l.HeldByCurrentSession
}

// FormatLockStatus renders a lock status as a human-readable report: who holds
// it, how long ago, whether it looks stale, and whether a write would be
// allowed right now.
func FormatLockStatus(l *LockStatus) string {
	var sb strings.Builder

	sb.WriteString(fmt.Sprintf("Page: #%d\n", l.PostID))

	if !l.Locked {
		sb.WriteString("Lock: none\n")
	} else {
		who := l.UserName
		if who == "" && l.UserID != nil {
			who = fmt.Sprintf("user #%d", *l.UserID)
		}
		if who == "" {
			who = "an unidentified session"
		}

		sb.WriteString(fmt.Sprintf("Locked by: %s", who))
		if l.UserName != "" && l.UserID != nil {
			sb.WriteString(fmt.Sprintf(" (user #%d)", *l.UserID))
		}
		sb.WriteString("\n")

		if l.AgeSeconds != nil {
			sb.WriteString(fmt.Sprintf("Opened: %s ago\n", formatAge(*l.AgeSeconds)))
		}

		switch {
		case l.HeldByCurrentSession:
			sb.WriteString("Status: held by this session\n")
		case l.Stale:
			sb.WriteString(fmt.Sprintf("Status: stale (older than %s; likely an abandoned session)\n", formatAge(l.StalenessWindowSeconds)))
		default:
			sb.WriteString("Status: active\n")
		}
	}

	if l.WriteAllowed() {
		sb.WriteString("Write access: allowed\n")
	} else {
		sb.WriteString("Write access: blocked — pass --force to overwrite work someone has open in the editor\n")
	}

	return sb.String()
}

// formatAge renders a duration given in seconds as a short human string,
// e.g. "45s", "12m", "3h".
func formatAge(seconds int) string {
	d := time.Duration(seconds) * time.Second
	switch {
	case d < time.Minute:
		return fmt.Sprintf("%ds", int(d.Seconds()))
	case d < time.Hour:
		return fmt.Sprintf("%dm", int(d.Minutes()))
	default:
		return fmt.Sprintf("%dh", int(d.Hours()))
	}
}

// formatValue converts an interface{} to a display string.
func formatValue(v interface{}) string {
	if v == nil {
		return "(not set)"
	}
	switch val := v.(type) {
	case string:
		return fmt.Sprintf("%q", val)
	case float64:
		if val == float64(int(val)) {
			return fmt.Sprintf("%d", int(val))
		}
		return fmt.Sprintf("%.2f", val)
	case map[string]interface{}:
		// Compact JSON for complex values
		data, err := json.Marshal(val)
		if err != nil {
			return fmt.Sprintf("%v", val)
		}
		return string(data)
	default:
		return fmt.Sprintf("%v", val)
	}
}
