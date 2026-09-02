package output

import (
	"encoding/json"
	"strings"
	"testing"
)

// The plugin encodes an element's summary as a JSON object when it has keys and
// as an empty JSON array when it does not — PHP cannot distinguish the two.
// Decoding into a plain map failed on the array form, which made the CLI fall
// back to printing raw JSON for every page whose first element had no summary.
func TestSummaryMapDecodesBothShapes(t *testing.T) {
	cases := []struct {
		name  string
		input string
		want  map[string]string
	}{
		{"object", `{"tag":"h1","align":"left"}`, map[string]string{"tag": "h1", "align": "left"}},
		{"empty array", `[]`, nil},
		{"empty object", `{}`, map[string]string{}},
		{"null", `null`, nil},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			var got SummaryMap
			if err := json.Unmarshal([]byte(tc.input), &got); err != nil {
				t.Fatalf("decoding %s: %v", tc.input, err)
			}

			if len(got) != len(tc.want) {
				t.Fatalf("got %v, want %v", got, tc.want)
			}

			for k, v := range tc.want {
				if got[k] != v {
					t.Errorf("key %q: got %q, want %q", k, got[k], v)
				}
			}
		})
	}
}

func TestSummaryMapRejectsUnexpectedShape(t *testing.T) {
	var got SummaryMap
	if err := json.Unmarshal([]byte(`["a","b"]`), &got); err == nil {
		t.Fatal("expected a non-empty array to be rejected, got no error")
	}
}

// Summary keys must render in a stable order; Go map iteration is randomised,
// and output that reshuffles between runs cannot be diffed or matched on.
func TestSummaryMapKeysAreSorted(t *testing.T) {
	s := SummaryMap{"tag": "h1", "align": "left", "url": "/contact"}

	got := strings.Join(s.Keys(), ",")
	if want := "align,tag,url"; got != want {
		t.Errorf("got %q, want %q", got, want)
	}
}

func TestFormatTreeRendersHierarchy(t *testing.T) {
	tree := &PageTree{
		PostID: 7,
		Title:  "Hero",
		Nodes: []TreeNode{{
			ID:    "a81f3a1",
			Type:  "container",
			Label: "flex(column)",
			Children: []TreeNode{
				{
					ID:         "f921a02",
					Type:       "widget",
					WidgetType: "heading",
					Label:      "Build Better Products.",
					Summary:    SummaryMap{"tag": "h1"},
				},
				{
					ID:         "213aa05",
					Type:       "widget",
					WidgetType: "button",
					Label:      "Start a Project",
				},
			},
		}},
	}

	got := FormatTree(tree)

	want := []string{
		"PAGE 7: Hero",
		`container [a81f3a1] "flex(column)"`,
		`├─ heading [f921a02] "Build Better Products."`,
		"│  tag: h1",
		`└─ button [213aa05] "Start a Project"`,
	}

	for _, line := range want {
		if !strings.Contains(got, line) {
			t.Errorf("missing line %q in:\n%s", line, got)
		}
	}
}
