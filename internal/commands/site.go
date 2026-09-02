package commands

import (
	"encoding/json"
	"fmt"

	"github.com/beylerburak/wpx/internal/config"
	"github.com/beylerburak/wpx/internal/output"
	"github.com/beylerburak/wpx/internal/wpcli"
	"github.com/spf13/cobra"
)

// getClient creates a WP-CLI client for the selected site.
func getClient() (*wpcli.Client, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, fmt.Errorf("cannot load config: %w", err)
	}

	site, err := cfg.GetSite(flagSite)
	if err != nil {
		return nil, err
	}

	return wpcli.NewClient(site), nil
}

func newSiteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "site",
		Short: "Site information commands",
	}

	cmd.AddCommand(&cobra.Command{
		Use:   "info",
		Short: "Display WordPress site information",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("site-info")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" || flagFormat == "" {
				fmt.Print(stdout)
				return nil
			}

			// Parse and format as table
			var info map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &info); err != nil {
				fmt.Print(stdout) // fallback to raw output
				return nil
			}

			formatted, _ := output.FormatJSON(info)
			fmt.Println(formatted)
			return nil
		},
	})

	return cmd
}

func newPluginsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "plugins",
		Short: "Manage WordPress plugins",
	}

	cmd.AddCommand(&cobra.Command{
		Use:   "list",
		Short: "List installed plugins",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("plugin", "list", "--format=json")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			// Parse and format as table
			var plugins []map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &plugins); err != nil {
				fmt.Print(stdout)
				return nil
			}

			headers := []string{"name", "status", "version", "update"}
			rows := make([]map[string]string, len(plugins))
			for i, p := range plugins {
				rows[i] = map[string]string{
					"name":    fmt.Sprint(p["name"]),
					"status":  fmt.Sprint(p["status"]),
					"version": fmt.Sprint(p["version"]),
					"update":  fmt.Sprint(p["update"]),
				}
			}

			fmt.Print(output.FormatTable(headers, rows))
			return nil
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "install <plugin>",
		Short: "Install a plugin",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("plugin", "install", args[0])
			if err != nil {
				return fmt.Errorf("install failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "activate <plugin>",
		Short: "Activate a plugin",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("plugin", "activate", args[0])
			if err != nil {
				return fmt.Errorf("activate failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "deactivate <plugin>",
		Short: "Deactivate a plugin",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("plugin", "deactivate", args[0])
			if err != nil {
				return fmt.Errorf("deactivate failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	return cmd
}

func newPagesCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "pages",
		Short: "List Elementor-built pages",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("pages")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			if flagFormat == "json" {
				fmt.Print(stdout)
				return nil
			}

			// Parse and format as table
			var pages []map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &pages); err != nil {
				fmt.Print(stdout)
				return nil
			}

			headers := []string{"id", "title", "status", "elements", "url"}
			rows := make([]map[string]string, len(pages))
			for i, p := range pages {
				rows[i] = map[string]string{
					"id":       fmt.Sprint(p["id"]),
					"title":    fmt.Sprint(p["title"]),
					"status":   fmt.Sprint(p["status"]),
					"elements": fmt.Sprint(p["element_count"]),
					"url":      fmt.Sprint(p["url"]),
				}
			}

			fmt.Print(output.FormatTable(headers, rows))
			return nil
		},
	}
}

func newOptionCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "option",
		Short: "Manage WordPress options",
	}

	cmd.AddCommand(&cobra.Command{
		Use:   "get <key>",
		Short: "Get a WordPress option value",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("option", "get", args[0])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "set <key> <value>",
		Short: "Set a WordPress option value",
		Args:  cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPCLI("option", "update", args[0], args[1])
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	})

	return cmd
}

func newCapabilitiesCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "capabilities",
		Short: "Show available capabilities of the connected site",
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := getClient()
			if err != nil {
				return err
			}

			stdout, stderr, err := client.ExecWPXCommand("capabilities")
			if err != nil {
				return fmt.Errorf("command failed: %s\n%s", stderr, err)
			}

			fmt.Print(stdout)
			return nil
		},
	}
}
