package ssh

import (
	"testing"

	"github.com/beylerburak/wpx/internal/config"
)

// With WPBin and WPEnv unset, the remote command must be byte-identical to
// what it was before those fields existed — existing config.yaml files have
// neither, and must keep working exactly as they do today.
func TestRemoteWPCLICommandDefaultsMatchLegacyForm(t *testing.T) {
	sshConfig := &config.SSHConfig{Host: "plesk", User: "root"}

	got := remoteWPCLICommand(sshConfig, "/var/www/vhosts/site/httpdocs", []string{"core", "version"})
	want := "wp --path=/var/www/vhosts/site/httpdocs core version"

	if got != want {
		t.Errorf("got %q, want %q", got, want)
	}
}

// --wp-bin points the invocation at a specific wp binary instead of relying
// on PATH.
func TestRemoteWPCLICommandUsesWPBin(t *testing.T) {
	sshConfig := &config.SSHConfig{Host: "plesk", WPBin: "/usr/local/bin/wp"}

	got := remoteWPCLICommand(sshConfig, "", []string{"--info"})
	want := "/usr/local/bin/wp --info"

	if got != want {
		t.Errorf("got %q, want %q", got, want)
	}
}

// --wp-env prefixes the command with `env KEY=VALUE ...` so php can be found
// on hosts (Plesk/cPanel) that don't put it on the default PATH.
func TestRemoteWPCLICommandPrefixesEnv(t *testing.T) {
	sshConfig := &config.SSHConfig{
		Host:  "plesk",
		WPEnv: []string{"PATH=/opt/plesk/php/8.4/bin:/usr/local/bin:/usr/bin"},
	}

	got := remoteWPCLICommand(sshConfig, "/var/www/vhosts/site/httpdocs", []string{"--info", "--format=json"})
	want := "env PATH=/opt/plesk/php/8.4/bin:/usr/local/bin:/usr/bin wp --path=/var/www/vhosts/site/httpdocs --info --format=json"

	if got != want {
		t.Errorf("got %q, want %q", got, want)
	}
}

// A WPEnv value containing shell-special characters (space, $) must stay
// intact as a single quoted argument rather than letting the remote shell
// split or expand it.
func TestRemoteWPCLICommandQuotesSpecialEnvValues(t *testing.T) {
	sshConfig := &config.SSHConfig{
		Host:  "plesk",
		WPBin: "/usr/local/bin/wp",
		WPEnv: []string{"GREETING=hello $USER", "FOO=bar baz"},
	}

	got := remoteWPCLICommand(sshConfig, "", []string{"core", "version"})
	want := `env 'GREETING=hello $USER' 'FOO=bar baz' /usr/local/bin/wp core version`

	if got != want {
		t.Errorf("got %q, want %q", got, want)
	}
}
