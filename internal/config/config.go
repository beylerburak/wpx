package config

import (
	"fmt"
	"os"
	"path/filepath"

	"gopkg.in/yaml.v3"
)

// Config holds the global CLI configuration.
type Config struct {
	// DefaultSite is the alias of the default site to use.
	DefaultSite string `yaml:"default_site,omitempty"`

	// Sites is a map of site alias -> SiteConfig.
	Sites map[string]*SiteConfig `yaml:"sites"`
}

// SiteConfig holds connection details for a WordPress site.
type SiteConfig struct {
	// Alias is the short name for this site.
	Alias string `yaml:"alias"`

	// URL is the WordPress site URL.
	URL string `yaml:"url"`

	// SSH connection details. Nil when the site runs on this machine.
	SSH *SSHConfig `yaml:"ssh,omitempty"`

	// Local connection details. Nil when the site is reached over SSH.
	Local *LocalConfig `yaml:"local,omitempty"`

	// WPPath is the WordPress installation path on the server.
	WPPath string `yaml:"wp_path,omitempty"`
}

// LocalConfig holds parameters for driving a WordPress install on this machine.
type LocalConfig struct {
	// WPBin is the path to the wp binary. Empty means "wp" on PATH.
	WPBin string `yaml:"wp_bin,omitempty"`
}

// Binary returns the wp binary to invoke.
func (l *LocalConfig) Binary() string {
	if l.WPBin != "" {
		return l.WPBin
	}
	return "wp"
}

// SSHConfig holds SSH connection parameters.
type SSHConfig struct {
	// Host is the SSH hostname.
	Host string `yaml:"host"`

	// Port is the SSH port (default: 22).
	Port int `yaml:"port,omitempty"`

	// User is the SSH username.
	User string `yaml:"user"`

	// KeyFile is the path to the SSH private key.
	KeyFile string `yaml:"key_file,omitempty"`
}

// ConfigDir returns the path to the wpx config directory (~/.wpx/).
func ConfigDir() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", fmt.Errorf("cannot determine home directory: %w", err)
	}

	dir := filepath.Join(home, ".wpx")
	return dir, nil
}

// ConfigPath returns the path to the config file (~/.wpx/config.yaml).
func ConfigPath() (string, error) {
	dir, err := ConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "config.yaml"), nil
}

// Load reads the config file from disk.
// Returns an empty config if the file doesn't exist.
func Load() (*Config, error) {
	path, err := ConfigPath()
	if err != nil {
		return nil, err
	}

	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return &Config{
				Sites: make(map[string]*SiteConfig),
			}, nil
		}
		return nil, fmt.Errorf("cannot read config: %w", err)
	}

	var cfg Config
	if err := yaml.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("cannot parse config: %w", err)
	}

	if cfg.Sites == nil {
		cfg.Sites = make(map[string]*SiteConfig)
	}

	return &cfg, nil
}

// Save writes the config to disk.
func (c *Config) Save() error {
	path, err := ConfigPath()
	if err != nil {
		return err
	}

	// Ensure directory exists
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("cannot create config directory: %w", err)
	}

	data, err := yaml.Marshal(c)
	if err != nil {
		return fmt.Errorf("cannot marshal config: %w", err)
	}

	if err := os.WriteFile(path, data, 0600); err != nil {
		return fmt.Errorf("cannot write config: %w", err)
	}

	return nil
}

// GetSite returns the site config for the given alias, or the default site.
func (c *Config) GetSite(alias string) (*SiteConfig, error) {
	if alias == "" {
		alias = c.DefaultSite
	}

	if alias == "" {
		return nil, fmt.Errorf("no site specified and no default site configured; run 'wpx connect' first")
	}

	site, ok := c.Sites[alias]
	if !ok {
		return nil, fmt.Errorf("site '%s' not found; run 'wpx connect' to add it", alias)
	}

	return site, nil
}

// AddSite adds or updates a site configuration.
func (c *Config) AddSite(site *SiteConfig) {
	c.Sites[site.Alias] = site

	// Set as default if it's the first site
	if len(c.Sites) == 1 {
		c.DefaultSite = site.Alias
	}
}

// SSHString returns the WP-CLI compatible SSH connection string.
// Format: user@host:port/wp_path
func (s *SiteConfig) SSHString() string {
	ssh := s.SSH
	if ssh == nil {
		return ""
	}

	result := ""
	if ssh.User != "" {
		result = ssh.User + "@"
	}
	result += ssh.Host

	if ssh.Port > 0 && ssh.Port != 22 {
		result += fmt.Sprintf(":%d", ssh.Port)
	}

	if s.WPPath != "" {
		result += s.WPPath
	}

	return result
}
