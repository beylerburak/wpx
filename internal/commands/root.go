package commands

import (
	"github.com/spf13/cobra"
)

// Global flags
var (
	flagSite   string
	flagFormat string
)

// NewRootCmd creates the root cobra command.
func NewRootCmd(version string) *cobra.Command {
	rootCmd := &cobra.Command{
		Use:   "wpx",
		Short: "Agent-native CLI for WordPress + Elementor",
		Long: `wpx is an agent-native command-line interface for managing WordPress sites
and editing Elementor page designs from the terminal.

Designed for use by AI coding agents (Claude Code, Codex, Cursor) and
developers who want to control WordPress without opening a browser.

Get started:
  wpx connect mysite --ssh user@server --path /var/www/html
  wpx site info
  wpx elementor tree 241`,
		Version: version,
		SilenceUsage:  true,
		SilenceErrors: true,
	}

	// Global flags
	rootCmd.PersistentFlags().StringVar(&flagSite, "site", "", "Site alias to use (default: default site)")
	rootCmd.PersistentFlags().StringVar(&flagFormat, "format", "", "Output format: tree, table, json (default varies by command)")

	// Register sub-commands
	rootCmd.AddCommand(newConnectCmd())
	rootCmd.AddCommand(newSiteCmd())
	rootCmd.AddCommand(newPluginsCmd())
	rootCmd.AddCommand(newPagesCmd())
	rootCmd.AddCommand(newOptionCmd())
	rootCmd.AddCommand(newElementorCmd())
	rootCmd.AddCommand(newHistoryCmd())
	rootCmd.AddCommand(newUndoCmd())
	rootCmd.AddCommand(newCapabilitiesCmd())

	return rootCmd
}
