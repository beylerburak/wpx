package commands

import (
	"fmt"
	"net/url"
	"strconv"
	"strings"

	"github.com/beylerburak/wpx/internal/config"
	"github.com/beylerburak/wpx/internal/wpcli"
	"github.com/spf13/cobra"
)

func newConnectCmd() *cobra.Command {
	var (
		sshFlag   string
		pathFlag  string
		portFlag  int
		localFlag bool
		wpBinFlag string
	)

	cmd := &cobra.Command{
		Use:   "connect <site-url-or-alias>",
		Short: "Connect to a WordPress site over SSH or on this machine",
		Long: `Connect to a WordPress site and save the connection for future use.

The connection is stored in ~/.wpx/config.yaml and can be referenced
by alias in subsequent commands.

Use --ssh for a remote site, or --local for a WordPress install on this
machine (a local dev or staging site).

Examples:
  wpx connect mysite --ssh user@server.com --path /var/www/html
  wpx connect mysite --ssh deploy@app.example.com:2222 --path /home/deploy/public_html
  wpx connect dev --local --path /Applications/XAMPP/xamppfiles/htdocs/wordpress`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			target := args[0]

			if localFlag && sshFlag != "" {
				return fmt.Errorf("--local and --ssh are mutually exclusive")
			}

			if !localFlag && sshFlag == "" {
				return fmt.Errorf("one of --ssh or --local is required\nUsage: wpx connect <alias> --ssh user@host[:port] [--path /var/www/html]\n       wpx connect <alias> --local --path /path/to/wordpress")
			}

			var sshConfig *config.SSHConfig
			if !localFlag {
				parsed, err := parseSSHString(sshFlag)
				if err != nil {
					return fmt.Errorf("invalid SSH string: %w", err)
				}
				if portFlag > 0 {
					parsed.Port = portFlag
				}
				sshConfig = parsed
			}

			// Determine alias and URL
			alias := target
			siteURL := ""

			if strings.HasPrefix(target, "http://") || strings.HasPrefix(target, "https://") {
				u, err := url.Parse(target)
				if err != nil {
					return fmt.Errorf("invalid URL: %w", err)
				}
				alias = u.Hostname()
				siteURL = target
			}

			site := &config.SiteConfig{
				Alias:  alias,
				URL:    siteURL,
				SSH:    sshConfig,
				WPPath: pathFlag,
			}

			if localFlag {
				if pathFlag == "" {
					return fmt.Errorf("--path is required with --local (the WordPress installation directory)")
				}
				site.Local = &config.LocalConfig{WPBin: wpBinFlag}
				fmt.Printf("Connecting to local WordPress at %s...\n", pathFlag)
			} else {
				fmt.Printf("Connecting to %s via SSH...\n", sshConfig.Host)
			}

			client := wpcli.NewClient(site)
			if err := client.TestConnection(); err != nil {
				return fmt.Errorf("connection failed: %w", err)
			}

			if localFlag {
				fmt.Println("✓ Local WordPress found")
			} else {
				fmt.Println("✓ SSH connection successful")
			}
			fmt.Println("✓ WP-CLI available")

			// Test WPX plugin
			if err := client.TestWPXPlugin(); err != nil {
				fmt.Println("⚠ WPX plugin not detected. Install it for full functionality.")
				fmt.Println("  Install and activate the Agent Control Plane for Elementor plugin.")
			} else {
				fmt.Println("✓ WPX plugin active")
			}

			// Save config
			cfg, err := config.Load()
			if err != nil {
				return fmt.Errorf("cannot load config: %w", err)
			}

			cfg.AddSite(site)
			if err := cfg.Save(); err != nil {
				return fmt.Errorf("cannot save config: %w", err)
			}

			fmt.Printf("\nSite '%s' connected and saved.\n", alias)
			if cfg.DefaultSite == alias {
				fmt.Printf("Set as default site.\n")
			}

			return nil
		},
	}

	cmd.Flags().StringVar(&sshFlag, "ssh", "", "SSH connection string (user@host[:port])")
	cmd.Flags().StringVar(&pathFlag, "path", "", "WordPress installation path on server")
	cmd.Flags().IntVar(&portFlag, "port", 0, "SSH port (overrides port in --ssh)")
	cmd.Flags().BoolVar(&localFlag, "local", false, "Site runs on this machine; run WP-CLI directly instead of over SSH")
	cmd.Flags().StringVar(&wpBinFlag, "wp-bin", "", "Path to the wp binary for --local (default: wp on PATH)")

	return cmd
}

// parseSSHString parses "user@host:port" into an SSHConfig.
func parseSSHString(s string) (*config.SSHConfig, error) {
	cfg := &config.SSHConfig{
		Port: 22,
	}

	// Split user@host
	if idx := strings.Index(s, "@"); idx >= 0 {
		cfg.User = s[:idx]
		s = s[idx+1:]
	}

	// Split host:port
	if idx := strings.LastIndex(s, ":"); idx >= 0 {
		port, err := strconv.Atoi(s[idx+1:])
		if err == nil {
			cfg.Port = port
			s = s[:idx]
		}
		// If it's not a number, it might be part of the hostname (IPv6 etc.)
	}

	cfg.Host = s

	if cfg.Host == "" {
		return nil, fmt.Errorf("hostname is required")
	}

	return cfg, nil
}
