package output

import (
	"strings"
	"testing"
)

// Dry-run output must be visibly distinct from a real result — an agent is
// told to run --dry-run first, so the two can never be confused for one
// another. FormatMutation carries its own banner for this rather than
// relying only on the footer line.
func TestFormatMutationDryRunIsVisiblyDistinct(t *testing.T) {
	details := []KV{{Key: "new_element_id", Value: "b3c1a09"}}

	dryRun := FormatMutation(241, "Duplicated f921a02", details, true)
	applied := FormatMutation(241, "Duplicated f921a02", details, false)

	if !strings.Contains(dryRun, "DRY RUN") {
		t.Errorf("dry-run output missing a DRY RUN banner:\n%s", dryRun)
	}
	if strings.Contains(applied, "DRY RUN") {
		t.Errorf("applied output should not mention DRY RUN:\n%s", applied)
	}
	if !strings.Contains(dryRun, "No changes applied.") {
		t.Errorf("dry-run output missing footer:\n%s", dryRun)
	}
	if !strings.Contains(applied, "Changes applied.") {
		t.Errorf("applied output missing footer:\n%s", applied)
	}
	if dryRun == applied {
		t.Fatal("dry-run and applied output must not be identical")
	}
}

func TestFormatMutationRendersDetailsInOrder(t *testing.T) {
	details := []KV{
		{Key: "container_id", Value: "c001"},
		{Key: "position", Value: "2"},
	}

	got := FormatMutation(7, "Created container", details, false)

	wantOrder := []string{"Page: #7", "Created container", "container_id: c001", "position: 2"}
	lastIdx := -1
	for _, want := range wantOrder {
		idx := strings.Index(got, want)
		if idx == -1 {
			t.Fatalf("missing %q in:\n%s", want, got)
		}
		if idx < lastIdx {
			t.Fatalf("expected %q to appear after previous line in:\n%s", want, got)
		}
		lastIdx = idx
	}
}

// A mutation with no known detail fields (e.g. the plugin's response shape
// doesn't match any of the keys we look for) must still render something
// coherent rather than an empty details block.
func TestFormatMutationHandlesNoDetails(t *testing.T) {
	got := FormatMutation(241, "Wrapped 2 element(s)", nil, false)

	if !strings.Contains(got, "Page: #241") || !strings.Contains(got, "Wrapped 2 element(s)") {
		t.Errorf("missing expected content in:\n%s", got)
	}
}
