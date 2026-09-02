package output

import (
	"encoding/json"
	"strings"
	"testing"
)

// The plugin reports lock state flat, with user_id and age_seconds nullable.
// Decoding must survive both the locked and unlocked shapes it really emits.
func TestLockStatusDecodesPluginShape(t *testing.T) {
	locked := `{"locked":true,"user_id":1,"user_name":"beyler","age_seconds":12,
		"stale":false,"reason":"Locked by beyler (user #1).","post_id":72,
		"staleness_window_seconds":150,"held_by_current_session":false}`

	var l LockStatus
	if err := json.Unmarshal([]byte(locked), &l); err != nil {
		t.Fatalf("decoding locked status: %v", err)
	}

	if !l.Locked || l.UserName != "beyler" || l.UserID == nil || *l.UserID != 1 {
		t.Fatalf("got %+v", l)
	}
	if l.WriteAllowed() {
		t.Error("a live lock held by someone else must block writes")
	}

	unlocked := `{"locked":false,"user_id":null,"user_name":null,"age_seconds":null,
		"stale":false,"reason":"Not locked.","post_id":72,
		"staleness_window_seconds":150,"held_by_current_session":false}`

	var u LockStatus
	if err := json.Unmarshal([]byte(unlocked), &u); err != nil {
		t.Fatalf("decoding unlocked status: %v", err)
	}
	if !u.WriteAllowed() {
		t.Error("an unlocked post must be writable")
	}
}

// A stale lock, and a lock this session holds, must both report as writable —
// otherwise wpx blocks on an abandoned browser tab, or on itself.
func TestLockStatusWriteVerdict(t *testing.T) {
	cases := []struct {
		name string
		l    LockStatus
		want bool
	}{
		{"unlocked", LockStatus{Locked: false}, true},
		{"live lock", LockStatus{Locked: true}, false},
		{"stale lock", LockStatus{Locked: true, Stale: true}, true},
		{"own lock", LockStatus{Locked: true, HeldByCurrentSession: true}, true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := tc.l.WriteAllowed(); got != tc.want {
				t.Errorf("got %v, want %v", got, tc.want)
			}
		})
	}
}

// The report must never say "Lock: none" while also refusing the write; that
// contradiction is exactly what a separate write_allowed field produced.
func TestFormatLockStatusIsSelfConsistent(t *testing.T) {
	uid, age := 1, 12
	got := FormatLockStatus(&LockStatus{
		PostID: 72, Locked: true, UserID: &uid, UserName: "beyler",
		AgeSeconds: &age, StalenessWindowSeconds: 150,
	})

	for _, want := range []string{"Page: #72", "Locked by: beyler (user #1)", "Opened: 12s ago", "Status: active", "Write access: blocked"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
	if strings.Contains(got, "Lock: none") {
		t.Errorf("reported no lock while blocking the write:\n%s", got)
	}

	free := FormatLockStatus(&LockStatus{PostID: 72, Locked: false})
	if !strings.Contains(free, "Lock: none") || !strings.Contains(free, "Write access: allowed") {
		t.Errorf("unlocked report is wrong:\n%s", free)
	}
}
