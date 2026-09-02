package main

import (
	"fmt"
	"os"

	"github.com/beylerburak/wpx/internal/commands"
)

// Version is set during build via ldflags.
var Version = "dev"

func main() {
	rootCmd := commands.NewRootCmd(Version)

	if err := rootCmd.Execute(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
