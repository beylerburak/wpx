package commands

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"

	"github.com/beylerburak/wpx/internal/output"
	"github.com/spf13/cobra"
)

// forceHelp is shared by every write command's --force flag. It exists to be
// used deliberately: a PHP-side editor-lock check refuses writes to a page
// someone has open in the Elementor editor, and --force is the only way
// through it, at the cost of silently clobbering whatever they haven't saved.
const forceHelp = "Bypass the editor-lock check and write even if someone has this page open in the Elementor editor. This can overwrite their in-progress work — use deliberately, not as a default."

func newElementorCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "elementor",
		Short: "Elementor page builder commands",
		Long: `Commands for reading and modifying Elementor page designs.

Workflow:
  1. wpx elementor tree <page_id>          # View element hierarchy
  2. wpx elementor get <page_id> <el_id>   # Inspect element details
  3. wpx elementor set ... --dry-run       # Preview changes
  4. wpx elementor set ...                 # Apply changes`,
	}

	cmd.AddCommand(newElementorTreeCmd())
	cmd.AddCommand(newElementorGetCmd())
	cmd.AddCommand(newElementorSetCmd())
	cmd.AddCommand(newElementorStyleCmd())
	cmd.AddCommand(newElementorAddWidgetCmd())
	cmd.AddCommand(newElementorDeleteCmd())
	cmd.AddCommand(newElementorMoveCmd())
	cmd.AddCommand(newElementorGlobalsCmd())
	cmd.AddCommand(newElementorDuplicateCmd())
	cmd.AddCommand(newElementorContainerCmd())
	cmd.AddCommand(newElementorWrapCmd())
	cmd.AddCommand(newElementorLockCmd())

	return cmd
}

func newElementorTreeCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "tree <page_id>",
		Short: "Show element hierarchy for an Elementor page",
		Long: `Displays the full element tree of an Elementor page in a readable format.

Each node shows: element type, ID (for use in other commands), and a summary.
Use the element IDs with 'wpx elementor get' and 'wpx elementor set'.`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-tree", args[0])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			// If JSON format requested, pass through
			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			// Parse JSON and render as tree
			var tree output.PageTree
			if err := json.Unmarshal([]byte(stdout), &tree); err != nil {
				// Fall back to raw output, but say why. A silent fallback here
				// hid a decoding bug that made every tree render as raw JSON.
				fmt.Fprintf(os.Stderr, "warning: could not decode tree (%v); showing raw output. Is the WPX plugin up to date?\n", err)
				fmt.Print(stdout)
				return nil
			}

			fmt.Print(output.FormatTree(&tree))
			return nil
		},
	}
}

func newElementorGetCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "get <page_id> <element_id>",
		Short: "Get full details of an Elementor element",
		Long: `Returns all settings, styles, and metadata for a specific element.

The element_id is the 7-character alphanumeric ID shown in 'wpx elementor tree'.`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-get", args[0], args[1])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}
}

func newElementorSetCmd() *cobra.Command {
	var (
		settingsFlag string
		dryRunFlag   bool
		forceFlag    bool
	)

	cmd := &cobra.Command{
		Use:   "set <page_id> <element_id>",
		Short: "Update an element's settings",
		Long: `Modify settings on an Elementor element.

Settings are passed as a JSON object via --setting flag.
Use --dry-run to preview changes without applying them.

Examples:
  wpx elementor set 241 f921a --setting title="New Heading"
  wpx elementor set 241 f921a --settings '{"title":"New","header_size":"h2"}'
  wpx elementor set 241 f921a --settings '{"title":"Test"}' --dry-run`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			// Build WPX command args
			wpxArgs := []string{args[0], args[1], "--settings=" + settingsFlag}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-set", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			// Parse result and format
			var result map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &result); err != nil {
				fmt.Print(stdout)
				return nil
			}

			// Show formatted diff if available
			if diffs, ok := result["diff"].([]interface{}); ok && len(diffs) > 0 && flagFormat != "json" {
				diffEntries := make([]output.DiffEntry, 0, len(diffs))
				for _, d := range diffs {
					if dm, ok := d.(map[string]interface{}); ok {
						diffEntries = append(diffEntries, output.DiffEntry{
							Key: fmt.Sprint(dm["key"]),
							Old: dm["old"],
							New: dm["new"],
						})
					}
				}

				elType := fmt.Sprint(result["element_type"])
				widgetType := ""
				if wt, ok := result["widget_type"]; ok && wt != nil {
					widgetType = fmt.Sprint(wt)
				}

				pageID := 0
				if pid, ok := result["post_id"].(float64); ok {
					pageID = int(pid)
				}

				fmt.Println(output.FormatDiff(pageID, args[1], elType, widgetType, diffEntries, dryRunFlag))

				if opID, ok := result["operation_id"]; ok && opID != nil {
					fmt.Printf("\nOperation: %s\n", opID)
				}
			} else {
				fmt.Print(stdout)
			}

			return nil
		},
	}

	cmd.Flags().StringVar(&settingsFlag, "settings", "{}", "Settings to update as JSON")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview changes without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorStyleCmd() *cobra.Command {
	var (
		desktopFlag string
		tabletFlag  string
		mobileFlag  string
		dryRunFlag  bool
		forceFlag   bool
	)

	cmd := &cobra.Command{
		Use:   "style <page_id> <element_id>",
		Short: "Update responsive styles for an element",
		Long: `Set responsive style values for desktop, tablet, and mobile breakpoints.

Each breakpoint flag takes a JSON object of style changes.

Examples:
  wpx elementor style 241 f921a \
    --desktop '{"typography_font_size":"72px"}' \
    --tablet '{"typography_font_size":"52px"}' \
    --mobile '{"typography_font_size":"38px"}'

  wpx elementor style 241 f921a \
    --desktop '{"padding":{"unit":"px","top":"80","right":"40","bottom":"80","left":"40","isLinked":false}}'`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], args[1]}

			if desktopFlag != "{}" && desktopFlag != "" {
				wpxArgs = append(wpxArgs, "--desktop="+desktopFlag)
			}
			if tabletFlag != "{}" && tabletFlag != "" {
				wpxArgs = append(wpxArgs, "--tablet="+tabletFlag)
			}
			if mobileFlag != "{}" && mobileFlag != "" {
				wpxArgs = append(wpxArgs, "--mobile="+mobileFlag)
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-style", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}

	cmd.Flags().StringVar(&desktopFlag, "desktop", "{}", "Desktop style changes as JSON")
	cmd.Flags().StringVar(&tabletFlag, "tablet", "{}", "Tablet style changes as JSON")
	cmd.Flags().StringVar(&mobileFlag, "mobile", "{}", "Mobile style changes as JSON")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview changes without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorAddWidgetCmd() *cobra.Command {
	var (
		typeFlag     string
		parentFlag   string
		afterFlag    string
		positionFlag int
		settingsFlag string
		dryRunFlag   bool
		forceFlag    bool
	)

	cmd := &cobra.Command{
		Use:   "add-widget <page_id>",
		Short: "Add a new widget to an Elementor page",
		Long: `Insert a new widget into the page element tree.

Examples:
  wpx elementor add-widget 241 --type button --parent a81f3 \
    --settings '{"text":"Contact Us","link":{"url":"/contact"}}'

  wpx elementor add-widget 241 --type heading --after 41ab2 \
    --settings '{"title":"Our Services","header_size":"h2"}'`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], "--type=" + typeFlag}

			if parentFlag != "" {
				wpxArgs = append(wpxArgs, "--parent="+parentFlag)
			}
			if afterFlag != "" {
				wpxArgs = append(wpxArgs, "--after="+afterFlag)
			}
			if cmd.Flags().Changed("position") {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--position=%d", positionFlag))
			}
			if settingsFlag != "{}" && settingsFlag != "" {
				wpxArgs = append(wpxArgs, "--settings="+settingsFlag)
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-add-widget", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}

	cmd.Flags().StringVar(&typeFlag, "type", "", "Widget type (heading, button, image, text-editor, etc.)")
	cmd.Flags().StringVar(&parentFlag, "parent", "", "Parent container ID")
	cmd.Flags().StringVar(&afterFlag, "after", "", "Insert after this element ID")
	cmd.Flags().IntVar(&positionFlag, "position", -1, "Position index in parent")
	cmd.Flags().StringVar(&settingsFlag, "settings", "{}", "Widget settings as JSON")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	_ = cmd.MarkFlagRequired("type")

	return cmd
}

func newElementorDeleteCmd() *cobra.Command {
	var (
		dryRunFlag bool
		forceFlag  bool
	)

	cmd := &cobra.Command{
		Use:   "delete <page_id> <element_id>",
		Short: "Delete an element from an Elementor page",
		Long: `Remove an element and all its children from the page.
Use --dry-run to see what would be removed.

Examples:
  wpx elementor delete 241 91bc2 --dry-run
  wpx elementor delete 241 91bc2`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], args[1]}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-delete", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}

	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without deleting")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorMoveCmd() *cobra.Command {
	var (
		intoFlag     string
		positionFlag int
		dryRunFlag   bool
		forceFlag    bool
	)

	cmd := &cobra.Command{
		Use:   "move <page_id> <element_id>",
		Short: "Move an element to a new position",
		Long: `Move an element to a different parent or position in the element tree.

Examples:
  wpx elementor move 241 213aa --into 72cc1 --position 0
  wpx elementor move 241 213aa --into root`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], args[1]}

			if intoFlag != "" {
				wpxArgs = append(wpxArgs, "--into="+intoFlag)
			}
			if cmd.Flags().Changed("position") {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--position=%d", positionFlag))
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-move", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}

	cmd.Flags().StringVar(&intoFlag, "into", "", "Target parent container ID")
	cmd.Flags().IntVar(&positionFlag, "position", -1, "Position in target container")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without moving")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorGlobalsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "globals",
		Short: "Manage Elementor global styles",
	}

	cmd.AddCommand(&cobra.Command{
		Use:   "colors",
		Short: "List global colors",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-globals-colors")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			// Parse and format as table
			var colors []map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &colors); err != nil {
				fmt.Print(stdout)
				return nil
			}

			headers := []string{"id", "title", "color", "type"}
			rows := make([]map[string]string, len(colors))
			for i, c := range colors {
				rows[i] = map[string]string{
					"id":    fmt.Sprint(c["id"]),
					"title": fmt.Sprint(c["title"]),
					"color": fmt.Sprint(c["color"]),
					"type":  fmt.Sprint(c["type"]),
				}
			}

			fmt.Print(output.FormatTable(headers, rows))
			return nil
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "typography",
		Short: "List global typography",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-globals-typography")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			var typos []map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &typos); err != nil {
				fmt.Print(stdout)
				return nil
			}

			headers := []string{"id", "title", "font_family", "font_weight", "font_size", "type"}
			rows := make([]map[string]string, len(typos))
			for i, t := range typos {
				rows[i] = map[string]string{
					"id":          fmt.Sprint(t["id"]),
					"title":       fmt.Sprint(t["title"]),
					"font_family": fmt.Sprint(t["font_family"]),
					"font_weight": fmt.Sprint(t["font_weight"]),
					"font_size":   fmt.Sprint(t["font_size"]),
					"type":        fmt.Sprint(t["type"]),
				}
			}

			fmt.Print(output.FormatTable(headers, rows))
			return nil
		},
	})

	// set-color sub-command
	setColorCmd := &cobra.Command{
		Use:   "set-color <color_id> <hex_value>",
		Short: "Set a global color value",
		Long: `Update a global color (system or custom).

Examples:
  wpx elementor globals set-color primary "#111111"
  wpx elementor globals set-color accent "#FF5C35" --dry-run`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], args[1]}

			dryRun, _ := cmd.Flags().GetBool("dry-run")
			if dryRun {
				wpxArgs = append(wpxArgs, "--dry-run")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-globals-set-color", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}
	setColorCmd.Flags().Bool("dry-run", false, "Preview without applying")
	cmd.AddCommand(setColorCmd)

	// Site settings
	cmd.AddCommand(&cobra.Command{
		Use:   "site-settings",
		Short: "Show Elementor site settings",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-site-settings")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	return cmd
}

func newElementorDuplicateCmd() *cobra.Command {
	var (
		positionFlag int
		dryRunFlag   bool
		forceFlag    bool
	)

	cmd := &cobra.Command{
		Use:   "duplicate <page_id> <element_id>",
		Short: "Duplicate an Elementor element",
		Long: `Create a copy of an element (and its children) as a sibling of the original.

Examples:
  wpx elementor duplicate 241 f921a02
  wpx elementor duplicate 241 f921a02 --position 3
  wpx elementor duplicate 241 f921a02 --dry-run`,
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0], args[1]}
			if cmd.Flags().Changed("position") {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--position=%d", positionFlag))
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-duplicate", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			return renderMutationResult(stdout, args[0], "Duplicated "+args[1], dryRunFlag)
		},
	}

	cmd.Flags().IntVar(&positionFlag, "position", -1, "Position index for the duplicate within its parent")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorContainerCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "container",
		Short: "Manage Elementor containers",
	}

	cmd.AddCommand(newElementorContainerCreateCmd())

	return cmd
}

func newElementorContainerCreateCmd() *cobra.Command {
	var (
		parentFlag      string
		directionFlag   string
		gapFlag         string
		justifyFlag     string
		alignFlag       string
		gridColumnsFlag int
		positionFlag    int
		afterFlag       string
		settingsFlag    string
		dryRunFlag      bool
		forceFlag       bool
	)

	cmd := &cobra.Command{
		Use:   "create <page_id>",
		Short: "Create a new Elementor container",
		Long: `Insert a new flex or grid container into the page element tree.

Examples:
  wpx elementor container create 241 --direction row --gap 20
  wpx elementor container create 241 --parent a81f3 --direction column --justify center
  wpx elementor container create 241 --after 41ab2 --grid-columns 3
  wpx elementor container create 241 --direction row --dry-run`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{args[0]}
			if parentFlag != "" {
				wpxArgs = append(wpxArgs, "--parent="+parentFlag)
			}
			if directionFlag != "" {
				wpxArgs = append(wpxArgs, "--direction="+directionFlag)
			}
			if gapFlag != "" {
				wpxArgs = append(wpxArgs, "--gap="+gapFlag)
			}
			if justifyFlag != "" {
				wpxArgs = append(wpxArgs, "--justify="+justifyFlag)
			}
			if alignFlag != "" {
				wpxArgs = append(wpxArgs, "--align="+alignFlag)
			}
			if cmd.Flags().Changed("grid-columns") {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--grid-columns=%d", gridColumnsFlag))
			}
			if cmd.Flags().Changed("position") {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--position=%d", positionFlag))
			}
			if afterFlag != "" {
				wpxArgs = append(wpxArgs, "--after="+afterFlag)
			}
			if settingsFlag != "{}" && settingsFlag != "" {
				wpxArgs = append(wpxArgs, "--settings="+settingsFlag)
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-container-create", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			return renderMutationResult(stdout, args[0], "Created container", dryRunFlag)
		},
	}

	cmd.Flags().StringVar(&parentFlag, "parent", "", "Parent container ID (default: page root)")
	cmd.Flags().StringVar(&directionFlag, "direction", "", "Flex direction: row or column")
	cmd.Flags().StringVar(&gapFlag, "gap", "", "Gap between children")
	cmd.Flags().StringVar(&justifyFlag, "justify", "", "Justify-content value")
	cmd.Flags().StringVar(&alignFlag, "align", "", "Align-items value")
	cmd.Flags().IntVar(&gridColumnsFlag, "grid-columns", 0, "Number of grid columns (grid containers)")
	cmd.Flags().IntVar(&positionFlag, "position", -1, "Position index in parent")
	cmd.Flags().StringVar(&afterFlag, "after", "", "Insert after this element ID")
	cmd.Flags().StringVar(&settingsFlag, "settings", "{}", "Additional container settings as JSON")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorWrapCmd() *cobra.Command {
	var (
		directionFlag string
		gapFlag       string
		settingsFlag  string
		dryRunFlag    bool
		forceFlag     bool
	)

	cmd := &cobra.Command{
		Use:   "wrap <page_id> <element_id>...",
		Short: "Wrap one or more elements in a new container",
		Long: `Create a new container around one or more existing elements and reparent
them into it, preserving their order and settings.

Examples:
  wpx elementor wrap 241 f921a02 213aa05
  wpx elementor wrap 241 f921a02 213aa05 --direction row --gap 16
  wpx elementor wrap 241 f921a02 213aa05 --dry-run`,
		Args: cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			pageID := args[0]
			elementIDs := args[1:]

			wpxArgs := []string{pageID, "--elements=" + strings.Join(elementIDs, ",")}
			if directionFlag != "" {
				wpxArgs = append(wpxArgs, "--direction="+directionFlag)
			}
			if gapFlag != "" {
				wpxArgs = append(wpxArgs, "--gap="+gapFlag)
			}
			if settingsFlag != "{}" && settingsFlag != "" {
				wpxArgs = append(wpxArgs, "--settings="+settingsFlag)
			}
			if dryRunFlag {
				wpxArgs = append(wpxArgs, "--dry-run")
			}
			if forceFlag {
				wpxArgs = append(wpxArgs, "--force")
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-wrap", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			action := fmt.Sprintf("Wrapped %d element(s): %s", len(elementIDs), strings.Join(elementIDs, ", "))
			return renderMutationResult(stdout, pageID, action, dryRunFlag)
		},
	}

	cmd.Flags().StringVar(&directionFlag, "direction", "", "Flex direction of the new wrapper: row or column")
	cmd.Flags().StringVar(&gapFlag, "gap", "", "Gap between wrapped children")
	cmd.Flags().StringVar(&settingsFlag, "settings", "{}", "Additional wrapper settings as JSON")
	cmd.Flags().BoolVar(&dryRunFlag, "dry-run", false, "Preview without applying")
	cmd.Flags().BoolVar(&forceFlag, "force", false, forceHelp)

	return cmd
}

func newElementorLockCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "lock <page_id>",
		Short: "Show the Elementor editor-lock status for a page",
		Long: `Reports whether a page is currently open in the Elementor editor: who holds
the lock, how long ago it was acquired, whether it looks stale, and whether a
write would currently be allowed.

Write commands (set, style, add-widget, delete, move, duplicate, container
create, wrap) refuse to run against a page someone else has locked unless
passed --force.

Examples:
  wpx elementor lock 241`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("elementor-lock-status", args[0])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			var status output.LockStatus
			if err := json.Unmarshal([]byte(stdout), &status); err != nil {
				// Never fall back silently: a swallowed decode error here is
				// exactly the bug that made 'elementor tree' print raw JSON
				// for months.
				fmt.Fprintf(os.Stderr, "warning: could not decode lock status (%v); showing raw output. Is the WPX plugin up to date?\n", err)
				fmt.Print(stdout)
				return nil
			}

			fmt.Print(output.FormatLockStatus(&status))
			return nil
		},
	}
}

// renderMutationResult prints the plugin's response to an operation that
// creates or relocates elements (duplicate, container create, wrap) rather
// than diffing settings on one that already exists.
//
// A JSON decode failure is never swallowed silently — it always warns on
// stderr before falling back to raw output, per the 'elementor tree' lesson.
func renderMutationResult(stdout string, pageIDArg string, actionLabel string, dryRun bool) error {
	if flagFormat == "json" {
		fmt.Print(stdout)
		return nil
	}

	var result map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &result); err != nil {
		fmt.Fprintf(os.Stderr, "warning: could not decode result (%v); showing raw output. Is the WPX plugin up to date?\n", err)
		fmt.Print(stdout)
		return nil
	}

	// If the plugin reported a settings diff (e.g. styles derived for a new
	// wrapper), show it the same way 'elementor set' does rather than
	// inventing a second diff renderer.
	if diffs, ok := result["diff"].([]interface{}); ok && len(diffs) > 0 {
		diffEntries := make([]output.DiffEntry, 0, len(diffs))
		for _, d := range diffs {
			if dm, ok := d.(map[string]interface{}); ok {
				diffEntries = append(diffEntries, output.DiffEntry{
					Key: fmt.Sprint(dm["key"]),
					Old: dm["old"],
					New: dm["new"],
				})
			}
		}

		elType := fmt.Sprint(result["element_type"])
		fmt.Println(output.FormatDiff(parsePageID(pageIDArg, result), fmt.Sprint(result["element_id"]), elType, "", diffEntries, dryRun))
		if opID, ok := result["operation_id"]; ok && opID != nil {
			fmt.Printf("\nOperation: %s\n", opID)
		}
		return nil
	}

	var details []output.KV
	for _, key := range []string{"element_id", "source_element_id", "new_element_id", "container_id", "position", "parent_id"} {
		if v, ok := result[key]; ok && v != nil {
			details = append(details, output.KV{Key: key, Value: fmt.Sprint(v)})
		}
	}
	if elems, ok := result["elements"].([]interface{}); ok && len(elems) > 0 {
		strs := make([]string, len(elems))
		for i, e := range elems {
			strs[i] = fmt.Sprint(e)
		}
		details = append(details, output.KV{Key: "elements", Value: strings.Join(strs, ", ")})
	}

	fmt.Print(output.FormatMutation(parsePageID(pageIDArg, result), actionLabel, details, dryRun))

	if opID, ok := result["operation_id"]; ok && opID != nil {
		fmt.Printf("\nOperation: %s\n", opID)
	}
	return nil
}

// parsePageID prefers the post_id the plugin echoed back; the CLI argument
// is only a fallback for a response that omits it.
func parsePageID(arg string, result map[string]interface{}) int {
	if pid, ok := result["post_id"].(float64); ok {
		return int(pid)
	}
	n, _ := strconv.Atoi(arg)
	return n
}

func newHistoryCmd() *cobra.Command {
	var (
		limitFlag  int
		postIDFlag int
	)

	cmd := &cobra.Command{
		Use:   "history",
		Short: "Show operation history",
		Long: `List recent WPX operations with their IDs for potential undo.

Examples:
  wpx history
  wpx history --limit 50
  wpx history --post-id 241`,
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			wpxArgs := []string{}
			if limitFlag > 0 {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--limit=%d", limitFlag))
			}
			if postIDFlag > 0 {
				wpxArgs = append(wpxArgs, fmt.Sprintf("--post-id=%d", postIDFlag))
			}

			stdout, stderr, err := client.ExecWPXCommand("history", wpxArgs...)
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			var ops []map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &ops); err != nil {
				fmt.Print(stdout)
				return nil
			}

			headers := []string{"operation_id", "command", "status", "created_at"}
			rows := make([]map[string]string, len(ops))
			for i, op := range ops {
				cmdStr := fmt.Sprint(op["command"])
				if len(cmdStr) > 50 {
					cmdStr = cmdStr[:47] + "..."
				}
				rows[i] = map[string]string{
					"operation_id": fmt.Sprint(op["operation_id"]),
					"command":      cmdStr,
					"status":       fmt.Sprint(op["status"]),
					"created_at":   fmt.Sprint(op["created_at"]),
				}
			}

			fmt.Print(output.FormatTable(headers, rows))
			return nil
		},
	}

	cmd.Flags().IntVar(&limitFlag, "limit", 20, "Max operations to show")
	cmd.Flags().IntVar(&postIDFlag, "post-id", 0, "Filter by post ID")

	return cmd
}

func newUndoCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "undo <operation_id>",
		Short: "Undo a previous operation",
		Long: `Revert a previous WPX operation by its ID.

Get operation IDs from 'wpx history'.

Examples:
  wpx undo op_0a1b2c3d4e5f`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if !strings.HasPrefix(args[0], "op_") {
				return fmt.Errorf("invalid operation ID format. Expected 'op_...' (get IDs from 'wpx history')")
			}

			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("undo", args[0])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}
}
