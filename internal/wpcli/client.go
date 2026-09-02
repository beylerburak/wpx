// Package wpcli runs WP-CLI commands against a configured site, either on the
// local machine or on a remote host over SSH.
package wpcli

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"strings"

	"github.com/beylerburak/wpx/internal/config"
	"github.com/beylerburak/wpx/internal/ssh"
)

// Client dispatches WP-CLI invocations to the transport the site is configured for.
type Client struct {
	site *config.SiteConfig
	ssh  *ssh.Client
}

// NewClient creates a client for the given site config.
func NewClient(site *config.SiteConfig) *Client {
	c := &Client{site: site}
	if site.SSH != nil {
		c.ssh = ssh.NewClient(site)
	}
	return c
}

// IsLocal reports whether commands run on this machine.
func (c *Client) IsLocal() bool {
	return c.site.Local != nil
}

// ExecWPCLI runs a WP-CLI command and returns stdout, stderr and any error.
func (c *Client) ExecWPCLI(args ...string) (string, string, error) {
	if !c.IsLocal() {
		if c.ssh == nil {
			return "", "", fmt.Errorf("site '%s' has neither a local nor an SSH transport configured", c.site.Alias)
		}
		return c.ssh.ExecWPCLI(args...)
	}

	// Local mode: exec the wp binary directly. No shell is involved, so the
	// arguments need no quoting.
	wpArgs := []string{}
	if c.site.WPPath != "" {
		wpArgs = append(wpArgs, "--path="+c.site.WPPath)
	}
	wpArgs = append(wpArgs, args...)

	cmd := exec.Command(c.site.Local.Binary(), wpArgs...)
	cmd.Stdin = os.Stdin

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()

	return stdout.String(), stderr.String(), err
}

// ExecWPXCommand runs a command in the `wp wpx` namespace.
func (c *Client) ExecWPXCommand(subcommand string, args ...string) (string, string, error) {
	wpxArgs := []string{"wpx", subcommand}
	wpxArgs = append(wpxArgs, args...)
	return c.ExecWPCLI(wpxArgs...)
}

// TestConnection verifies the transport works and WP-CLI is reachable.
func (c *Client) TestConnection() error {
	if !c.IsLocal() {
		return c.ssh.TestConnection()
	}

	stdout, stderr, err := c.ExecWPCLI("core", "version")
	if err != nil {
		if msg := strings.TrimSpace(stderr); msg != "" {
			return fmt.Errorf("local WP-CLI failed: %s", msg)
		}
		return fmt.Errorf("local WP-CLI failed: %w", err)
	}

	if strings.TrimSpace(stdout) == "" {
		return fmt.Errorf("local WP-CLI returned no WordPress version for path '%s'", c.site.WPPath)
	}

	return nil
}

// TestWPXPlugin verifies the WPX WordPress plugin is installed and active.
func (c *Client) TestWPXPlugin() error {
	stdout, _, err := c.ExecWPXCommand("capabilities")
	if err != nil {
		return fmt.Errorf("WPX plugin not active: %w", err)
	}

	if !strings.Contains(stdout, "wpx") {
		return fmt.Errorf("WPX plugin not responding correctly")
	}

	return nil
}
