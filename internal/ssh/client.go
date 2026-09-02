package ssh

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"

	"github.com/beylerburak/wpx/internal/config"
)

// Client handles SSH connections and WP-CLI command execution.
type Client struct {
	site *config.SiteConfig
}

// NewClient creates a new SSH client for the given site config.
func NewClient(site *config.SiteConfig) *Client {
	return &Client{site: site}
}

// ExecWPCLI executes a WP-CLI command on the remote server via SSH.
// It uses the system's ssh binary for maximum compatibility with SSH configs,
// agent forwarding, and key management.
//
// Returns stdout, stderr, and any error.
func (c *Client) ExecWPCLI(args ...string) (string, string, error) {
	return c.ExecWPCLIStdin(os.Stdin, args...)
}

// ExecWPCLIStdin is ExecWPCLI with an explicit stdin, for the rare command
// that needs to stream something other than the CLI process's own stdin to
// the remote WP-CLI invocation — e.g. a local file piped to
// `elementor-import --file=-`. Every other call site keeps using ExecWPCLI,
// which is unchanged and still inherits os.Stdin.
//
// Returns stdout, stderr, and any error.
func (c *Client) ExecWPCLIStdin(stdin io.Reader, args ...string) (string, string, error) {
	sshConfig := c.site.SSH
	if sshConfig == nil {
		return "", "", fmt.Errorf("no SSH configuration for site '%s'", c.site.Alias)
	}

	// Build the remote command string
	remoteCmd := remoteWPCLICommand(sshConfig, c.site.WPPath, args)

	// Build the SSH command
	sshArgs := c.buildSSHArgs()
	sshArgs = append(sshArgs, remoteCmd)

	cmd := exec.Command("ssh", sshArgs...)
	cmd.Stdin = stdin

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()

	return stdout.String(), stderr.String(), err
}

// ExecWPXCommand executes a WPX-specific WP-CLI command.
// Wraps the command under the `wp wpx` namespace.
func (c *Client) ExecWPXCommand(subcommand string, args ...string) (string, string, error) {
	wpxArgs := []string{"wpx", subcommand}
	wpxArgs = append(wpxArgs, args...)
	return c.ExecWPCLI(wpxArgs...)
}

// ExecWPXCommandStdin is ExecWPXCommand with an explicit stdin. See
// ExecWPCLIStdin.
func (c *Client) ExecWPXCommandStdin(stdin io.Reader, subcommand string, args ...string) (string, string, error) {
	wpxArgs := []string{"wpx", subcommand}
	wpxArgs = append(wpxArgs, args...)
	return c.ExecWPCLIStdin(stdin, wpxArgs...)
}

// TestConnection tests the SSH connection and WP-CLI availability.
func (c *Client) TestConnection() error {
	stdout, stderr, err := c.ExecWPCLI("--info", "--format=json")
	if err != nil {
		errMsg := strings.TrimSpace(stderr)
		if errMsg != "" {
			return fmt.Errorf("SSH connection failed: %s", errMsg)
		}
		return fmt.Errorf("SSH connection failed: %w", err)
	}

	if !strings.Contains(stdout, "wp_version") && !strings.Contains(stdout, "wp-cli") {
		return fmt.Errorf("WP-CLI not available on remote server")
	}

	return nil
}

// TestWPXPlugin tests if the WPX plugin is installed and active.
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

// buildSSHArgs constructs the SSH command arguments.
func (c *Client) buildSSHArgs() []string {
	ssh := c.site.SSH
	args := []string{}

	// Port
	if ssh.Port > 0 && ssh.Port != 22 {
		args = append(args, "-p", fmt.Sprintf("%d", ssh.Port))
	}

	// Key file
	if ssh.KeyFile != "" {
		expandedPath := expandPath(ssh.KeyFile)
		args = append(args, "-i", expandedPath)
	}

	// Disable strict host key checking for first connection
	args = append(args, "-o", "StrictHostKeyChecking=accept-new")

	// Connection timeout
	args = append(args, "-o", "ConnectTimeout=10")

	// Disable pseudo-terminal allocation (we want raw output)
	args = append(args, "-T")

	// User@Host
	target := ssh.Host
	if ssh.User != "" {
		target = ssh.User + "@" + ssh.Host
	}
	args = append(args, target)

	return args
}

// remoteWPCLICommand builds the shell-quoted WP-CLI command to run on the
// remote host. Panel hosts (Plesk/cPanel) often have no php on the default
// PATH, which breaks WP-CLI's `#!/usr/bin/env php` shebang; WPEnv lets the
// caller patch the remote environment (typically PATH) so `env` can find php
// before wp runs. Both WPBin and WPEnv default to zero values, so an unset
// SSHConfig produces the exact same command it always has.
func remoteWPCLICommand(sshConfig *config.SSHConfig, wpPath string, args []string) string {
	wpArgs := []string{}
	if len(sshConfig.WPEnv) > 0 {
		wpArgs = append(wpArgs, "env")
		wpArgs = append(wpArgs, sshConfig.WPEnv...)
	}
	wpArgs = append(wpArgs, sshConfig.Binary())
	if wpPath != "" {
		wpArgs = append(wpArgs, "--path="+wpPath)
	}
	wpArgs = append(wpArgs, args...)

	return shellJoin(wpArgs)
}

// shellJoin safely joins command arguments for shell execution.
func shellJoin(args []string) string {
	quoted := make([]string, len(args))
	for i, arg := range args {
		if needsQuoting(arg) {
			quoted[i] = "'" + strings.ReplaceAll(arg, "'", "'\\''") + "'"
		} else {
			quoted[i] = arg
		}
	}
	return strings.Join(quoted, " ")
}

// needsQuoting checks if a shell argument needs quoting.
func needsQuoting(s string) bool {
	for _, c := range s {
		switch c {
		case ' ', '\t', '\n', '"', '\'', '\\', '`', '$', '!', '&', '|', ';', '(', ')', '<', '>', '{', '}', '[', ']', '?', '*', '#', '~':
			return true
		}
	}
	return false
}

// expandPath expands ~ to the home directory.
func expandPath(path string) string {
	if strings.HasPrefix(path, "~/") {
		home, err := os.UserHomeDir()
		if err == nil {
			return strings.Replace(path, "~", home, 1)
		}
	}
	return path
}
