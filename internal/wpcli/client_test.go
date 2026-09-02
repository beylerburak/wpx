package wpcli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/beylerburak/wpx/internal/config"
)

// ExecWPXCommandStdin must thread the explicit reader it's given through to
// the invoked process instead of the CLI's own os.Stdin, while building the
// exact same `wpx <subcommand> <args...>` command ExecWPXCommand always has.
// This is the path 'wpx elementor import' relies on to stream a local file
// to `elementor-import --file=-` without a temp file — get either half
// wrong and the payload silently goes missing.
func TestExecWPXCommandStdinThreadsStdinAndArgs(t *testing.T) {
	dir := t.TempDir()
	binPath := filepath.Join(dir, "wp")

	// A fake "wp" binary that prints each argument on its own line, then
	// whatever it received on stdin, so one run of the fake binary
	// captures both halves of the wiring being tested.
	script := "#!/bin/sh\nfor a in \"$@\"; do echo \"ARG:$a\"; done\nprintf 'STDIN:'\ncat\n"
	if err := os.WriteFile(binPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake wp binary: %v", err)
	}

	site := &config.SiteConfig{
		Alias: "test",
		Local: &config.LocalConfig{WPBin: binPath},
	}
	client := NewClient(site)

	payload := `{"elements":[]}`
	stdout, stderr, err := client.ExecWPXCommandStdin(strings.NewReader(payload), "elementor-import", "241", "--file=-", "--dry-run")
	if err != nil {
		t.Fatalf("ExecWPXCommandStdin failed: %v\nstderr: %s", err, stderr)
	}

	wantArgs := []string{"ARG:wpx", "ARG:elementor-import", "ARG:241", "ARG:--file=-", "ARG:--dry-run"}
	for _, want := range wantArgs {
		if !strings.Contains(stdout, want) {
			t.Errorf("missing %q in command args:\n%s", want, stdout)
		}
	}

	if !strings.Contains(stdout, "STDIN:"+payload) {
		t.Errorf("payload was not streamed through stdin, got:\n%s", stdout)
	}
}

// ExecWPCLI (no explicit stdin) must keep behaving exactly as it did before
// ExecWPCLIStdin existed — every other call site depends on this.
func TestExecWPCLIStillLocalOnlyWithoutSSH(t *testing.T) {
	site := &config.SiteConfig{Alias: "test"}
	client := NewClient(site)

	if client.IsLocal() {
		t.Fatal("site with neither Local nor SSH configured must not report IsLocal")
	}

	_, _, err := client.ExecWPCLI("core", "version")
	if err == nil {
		t.Fatal("expected an error for a site with no transport configured")
	}
	if !strings.Contains(err.Error(), "neither a local nor an SSH transport") {
		t.Errorf("unexpected error message: %v", err)
	}
}
