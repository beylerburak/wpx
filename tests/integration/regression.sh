#!/usr/bin/env bash
#
# WPX integration regression suite.
#
# Every test here encodes a defect that was reproduced against a real
# WordPress + Elementor install. They are written to FAIL on the code as it
# stood before the fixes, so a passing run means something.
#
# WARNING: this suite CREATES, REWRITES AND DELETES pages on the WordPress
# install it points at, and writes to the wpx operations table. Point it at a
# scratch site, never at anything real.
#
# Usage:
#   WP_ROOT=/path/to/wordpress WP_BIN=/path/to/wp tests/integration/regression.sh
#
# Environment overrides (the defaults suit a local XAMPP install on macOS):
#   WP_ROOT   WordPress installation directory
#   WP_BIN    wp-cli binary
#   WPX_BIN   wpx binary
#
set -u

WP_ROOT="${WP_ROOT:-/Applications/XAMPP/xamppfiles/htdocs/wordpress}"
WP_BIN="${WP_BIN:-$HOME/.local/bin/wp}"
WPX_BIN="${WPX_BIN:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/build/wpx}"

wp() { "$WP_BIN" --path="$WP_ROOT" "$@"; }
wpx() { "$WPX_BIN" "$@"; }

CSS_DIR="$WP_ROOT/wp-content/uploads/elementor/css"
SUITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PASS=0
FAIL=0
CURRENT=""

start() {
    CURRENT="$1"
    printf '\n\033[1m▸ %s\033[0m\n' "$CURRENT"
}

ok() {
    PASS=$((PASS + 1))
    printf '  \033[32mPASS\033[0m %s\n' "$1"
}

bad() {
    FAIL=$((FAIL + 1))
    printf '  \033[31mFAIL\033[0m %s\n' "$1"
    if [ $# -gt 1 ]; then
        printf '       %s\n' "$2"
    fi
}

# assert_contains <haystack> <needle> <label>
assert_contains() {
    case "$1" in
        *"$2"*) ok "$3" ;;
        *) bad "$3" "beklenen parça bulunamadı: '$2'" ;;
    esac
}

# assert_not_contains <haystack> <needle> <label>
assert_not_contains() {
    case "$1" in
        *"$2"*) bad "$3" "olmaması gereken parça bulundu: '$2'" ;;
        *) ok "$3" ;;
    esac
}

# assert_eq <actual> <expected> <label>
assert_eq() {
    if [ "$1" = "$2" ]; then
        ok "$3"
    else
        bad "$3" "beklenen '$2', gelen '$1'"
    fi
}

# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

reseed() {
    local out
    out="$(wp eval-file "$SUITE_DIR/fixtures.php" 2>&1)" || {
        printf '\033[31mFixture kurulumu başarısız:\033[0m\n%s\n' "$out"
        exit 1
    }
    eval "$out"
}

# settings_json <post_id> <element_id> — the element's settings as compact JSON.
#
# Note: `wp eval` takes no positional arguments, so values are interpolated
# into the snippet. Callers only ever pass numeric post IDs and fixture
# element IDs, so this is safe here — do not extend it to arbitrary input.
settings_json() {
    wp eval "
    \$needle = '$2';
    \$find = function ( \$els ) use ( &\$find, \$needle ) {
        foreach ( \$els as \$e ) {
            if ( ( \$e['id'] ?? '' ) === \$needle ) { return \$e; }
            if ( ! empty( \$e['elements'] ) ) { \$f = \$find( \$e['elements'] ); if ( \$f ) { return \$f; } }
        }
        return null;
    };
    \$el = \$find( json_decode( get_post_meta( $1, '_elementor_data', true ), true ) ?: [] );
    echo \$el ? json_encode( \$el['settings'] ?? [] ) : 'MISSING';
    "
}

# parent_of <post_id> <element_id> — "<parent_id>:<index>", or "root:<index>".
parent_of() {
    wp eval "
    \$needle = '$2';
    \$walk = function ( \$els, \$parent ) use ( &\$walk, \$needle ) {
        foreach ( \$els as \$i => \$e ) {
            if ( ( \$e['id'] ?? '' ) === \$needle ) { return \$parent . ':' . \$i; }
            if ( ! empty( \$e['elements'] ) ) {
                \$f = \$walk( \$e['elements'], \$e['id'] );
                if ( \$f !== null ) { return \$f; }
            }
        }
        return null;
    };
    echo \$walk( json_decode( get_post_meta( $1, '_elementor_data', true ), true ) ?: [], 'root' ) ?? 'MISSING';
    "
}

# all_ids <post_id> — every element id on the page, one per line.
all_ids() {
    wp eval "
    \$walk = function ( \$els ) use ( &\$walk ) {
        \$out = [];
        foreach ( \$els as \$e ) {
            \$out[] = \$e['id'] ?? '';
            if ( ! empty( \$e['elements'] ) ) { \$out = array_merge( \$out, \$walk( \$e['elements'] ) ); }
        }
        return \$out;
    };
    echo implode( \"\\n\", \$walk( json_decode( get_post_meta( $1, '_elementor_data', true ), true ) ?: [] ) );
    "
}

last_op() {
    wp eval 'global $wpdb; echo (string) $wpdb->get_var( "SELECT operation_id FROM {$wpdb->prefix}wpx_operations ORDER BY id DESC LIMIT 1" );' 2>/dev/null
}

printf '\033[1mWPX regression suite\033[0m\n'
printf 'WordPress : %s\n' "$WP_ROOT"
printf 'wpx       : %s\n' "$WPX_BIN"

[ -x "$WPX_BIN" ] || { printf '\033[31mwpx binary yok: %s  (make build)\033[0m\n' "$WPX_BIN"; exit 1; }

reseed
printf 'Fixtures  : page A=%s, page B=%s, V4=%s, kit=%s\n' "$PAGE_A" "$PAGE_B" "$PAGE_V4" "$KIT"

# render_v4 — request the V4 page so Elementor writes its atomic style files.
render_v4() { curl -sL -o /dev/null "$V4_URL"; }

# v4_css <breakpoint> — contents of the V4 page's generated local stylesheet.
v4_css() { cat "$CSS_DIR/local-$PAGE_V4-frontend-$1.css" 2>/dev/null || echo ''; }

# ---------------------------------------------------------------------------
# 1. Group-control toggles: a style write must actually render.
# ---------------------------------------------------------------------------
start "Responsive style CSS üretiyor (group-control toggle)"
wpx elementor style "$PAGE_A" f921a02 \
    --desktop '{"typography_font_size":"72px"}' \
    --tablet  '{"typography_font_size":"52px"}' \
    --mobile  '{"typography_font_size":"38px"}' >/dev/null 2>&1
CSS="$(cat "$CSS_DIR/post-$PAGE_A.css" 2>/dev/null || echo '')"
assert_contains "$CSS" "font-size:72px" "desktop font-size CSS'e yazıldı"
assert_contains "$CSS" "font-size:52px" "tablet font-size CSS'e yazıldı"
assert_contains "$CSS" "font-size:38px" "mobile font-size CSS'e yazıldı"

# ---------------------------------------------------------------------------
# 2. Dry-run must not green-light an impossible move.
# ---------------------------------------------------------------------------
start "dry-run imkansız move'a onay vermiyor"
OUT="$(wpx elementor move "$PAGE_A" a81f3a1 --into 72cc104 --dry-run 2>&1)"
assert_not_contains "$OUT" '"success": true' "kendi torununa taşıma dry-run'da reddedildi"

# ---------------------------------------------------------------------------
# 3. Undo of a delete must restore the element where it came from.
# ---------------------------------------------------------------------------
start "delete + undo elemanı eski yerine koyuyor"
BEFORE="$(parent_of "$PAGE_A" 213aa05)"
assert_eq "$BEFORE" "72cc104:0" "başlangıç konumu doğru"
wpx elementor delete "$PAGE_A" 213aa05 >/dev/null 2>&1
OP="$(last_op)"
wpx undo "$OP" >/dev/null 2>&1
assert_eq "$(parent_of "$PAGE_A" 213aa05)" "72cc104:0" "undo sonrası aynı parent ve index"

# ---------------------------------------------------------------------------
# 4. Undo of a set must remove keys the set introduced.
# ---------------------------------------------------------------------------
start "set + undo yeni eklenen key'leri siliyor"
reseed
BEFORE="$(settings_json "$PAGE_A" 41ab203)"
wpx elementor set "$PAGE_A" 41ab203 --settings '{"align":"center","text_color":"#FF0000"}' >/dev/null 2>&1
OP="$(last_op)"
wpx undo "$OP" >/dev/null 2>&1
AFTER="$(settings_json "$PAGE_A" 41ab203)"
assert_eq "$AFTER" "$BEFORE" "ayarlar birebir eski haline döndü"

# ---------------------------------------------------------------------------
# 5. Nested settings must merge, not clobber.
# ---------------------------------------------------------------------------
start "nested ayarlar deep merge ediliyor"
reseed
wpx elementor set "$PAGE_A" 213aa05 --settings '{"link":{"url":"/iletisim"}}' >/dev/null 2>&1
S="$(settings_json "$PAGE_A" 213aa05)"
assert_contains "$S" '"url":"\/iletisim"' "url güncellendi"
assert_contains "$S" 'is_external' "kardeş alan is_external korundu"
assert_contains "$S" 'nofollow' "kardeş alan nofollow korundu"

# ---------------------------------------------------------------------------
# 6. Editing one page must not invalidate another page's CSS.
# ---------------------------------------------------------------------------
start "tek sayfa düzenlemesi diğer sayfanın CSS'ini silmiyor"
reseed
[ -f "$CSS_DIR/post-$PAGE_B.css" ] || bad "ön koşul" "post-$PAGE_B.css fixture sonrası yok"
wpx elementor set "$PAGE_A" f921a02 --settings '{"align":"center"}' >/dev/null 2>&1
if [ -f "$CSS_DIR/post-$PAGE_B.css" ]; then
    ok "ilgisiz sayfanın CSS dosyası duruyor"
else
    bad "ilgisiz sayfanın CSS dosyası duruyor" "post-$PAGE_B.css silinmiş — site geneli cache temizleniyor"
fi

# ---------------------------------------------------------------------------
# 7. Writes must bump post_modified so downstream caches invalidate.
# ---------------------------------------------------------------------------
start "yazma işlemi post_modified'i güncelliyor"
# wp_update_post() always stamps the current time on update, so backdate the
# row directly — otherwise this test would pass without proving anything.
wp eval "global \$wpdb; \$wpdb->update( \$wpdb->posts, [ 'post_modified' => '2001-01-01 00:00:00', 'post_modified_gmt' => '2001-01-01 00:00:00' ], [ 'ID' => $PAGE_A ] ); clean_post_cache( $PAGE_A );" >/dev/null 2>&1
BACKDATED="$(wp eval "echo get_post_field( 'post_modified', $PAGE_A );")"
assert_contains "$BACKDATED" "2001-01-01" "ön koşul: post_modified geriye alındı" 
wpx elementor set "$PAGE_A" f921a02 --settings '{"align":"right"}' >/dev/null 2>&1
MOD="$(wp eval "echo get_post_field( 'post_modified', $PAGE_A );")"
assert_not_contains "$MOD" "2001-01-01" "post_modified ileri alındı"

# ---------------------------------------------------------------------------
# 8. Global colors must be readable on a kit whose meta was never materialised.
# ---------------------------------------------------------------------------
start "global renkler materyalize edilmemiş kit'te de okunuyor"
wp eval "update_post_meta( $KIT, '_elementor_page_settings', '' );" >/dev/null 2>&1
OUT="$(wpx elementor globals colors 2>&1)"
assert_contains "$OUT" "primary" "primary rengi listelendi"

# ---------------------------------------------------------------------------
# 9. Global color write must not fatal on Elementor 4 and must persist.
# ---------------------------------------------------------------------------
start "global renk yazma Elementor 4'te fatal vermiyor"
OUT="$(wpx elementor globals set-color primary '#123456' 2>&1)"
assert_not_contains "$OUT" "Fatal error" "fatal error yok"
assert_not_contains "$OUT" "Global_CSS" "kaldırılmış Global_CSS sınıfına referans yok"
OUT="$(wpx elementor globals colors 2>&1)"
assert_contains "$OUT" "#123456" "yeni renk geri okunabiliyor"

# ---------------------------------------------------------------------------
# 10. A kit write must not wipe the settings it did not touch.
# ---------------------------------------------------------------------------
start "kit yazması dokunmadığı ayarları silmiyor"
COUNT="$(wp eval "
\$m = get_post_meta( $KIT, '_elementor_page_settings', true );
echo is_array( \$m ) && isset( \$m['system_colors'] ) ? count( \$m['system_colors'] ) : 0;
")"
assert_eq "$COUNT" "4" "dört sistem rengi de yerinde"

# ---------------------------------------------------------------------------
# 11. The tree must render as a tree, not fall back to raw JSON.
# ---------------------------------------------------------------------------
start "elementor tree ağaç olarak render ediliyor"
reseed
OUT="$(wpx elementor tree "$PAGE_A" 2>&1)"
assert_not_contains "$OUT" '"post_id"' "ham JSON'a düşmedi"
assert_not_contains "$OUT" 'warning: could not decode' "decode uyarısı yok"
assert_contains "$OUT" '├─ heading' "hiyerarşi çizildi"
assert_contains "$OUT" 'tag: h1' "summary satırı basıldı"
assert_contains "$OUT" '└─ image' "son kardeş doğru bağlayıcıyla"
OUT="$(wpx elementor tree "$PAGE_A" --format json 2>&1)"
assert_contains "$OUT" '"post_id"' "--format json hâlâ JSON veriyor"

# ---------------------------------------------------------------------------
# 12. Duplicating a subtree must copy it with entirely fresh ids.
# ---------------------------------------------------------------------------
start "duplicate alt ağacı taze ID'lerle kopyalıyor"
reseed
BEFORE_IDS="$(all_ids "$PAGE_A" | sort)"
wpx elementor duplicate "$PAGE_A" 72cc104 >/dev/null 2>&1
AFTER_IDS="$(all_ids "$PAGE_A")"
TOTAL="$(printf '%s\n' "$AFTER_IDS" | wc -l | tr -d ' ')"
UNIQUE="$(printf '%s\n' "$AFTER_IDS" | sort -u | wc -l | tr -d ' ')"
assert_eq "$TOTAL" "9" "3 elemanlık alt ağaç kopyalandı (6 → 9)"
assert_eq "$UNIQUE" "$TOTAL" "hiçbir ID çakışmıyor"
assert_contains "$(printf '%s\n' "$AFTER_IDS")" "213aa05" "orijinal eleman yerinde duruyor"

# ---------------------------------------------------------------------------
# 13. A created container's settings must be real keys that render.
# ---------------------------------------------------------------------------
start "container create gerçekten CSS üretiyor"
reseed
wpx elementor container create "$PAGE_A" --parent a81f3a1 --grid-columns 3 --gap 24 >/dev/null 2>&1
CSS="$(cat "$CSS_DIR/post-$PAGE_A.css" 2>/dev/null || echo '')"
assert_contains "$CSS" "display:grid" "grid container render edildi"
assert_contains "$CSS" "repeat(3, 1fr)" "3 kolon CSS'e yansıdı"

# ---------------------------------------------------------------------------
# 14. Wrapping siblings must move them, in order, into one new container.
# ---------------------------------------------------------------------------
start "wrap kardeşleri sırasıyla yeni container'a alıyor"
reseed
wpx elementor wrap "$PAGE_A" f921a02 41ab203 --direction row --gap 32 >/dev/null 2>&1
P1="$(parent_of "$PAGE_A" f921a02)"
P2="$(parent_of "$PAGE_A" 41ab203)"
assert_eq "${P1##*:}" "0" "ilk eleman yeni container'ın 0. sırasında"
assert_eq "${P2##*:}" "1" "ikinci eleman 1. sırada"
assert_eq "${P1%%:*}" "${P2%%:*}" "ikisi de aynı yeni container'ın altında"
assert_not_contains "${P1%%:*}" "a81f3a1" "eski parent değil, yeni bir container"
assert_contains "$(cat "$CSS_DIR/post-$PAGE_A.css" 2>/dev/null)" "gap:32px" "wrap container'ının gap'i render edildi"

# ---------------------------------------------------------------------------
# 15. A page open in the Elementor editor must not be silently overwritten.
# ---------------------------------------------------------------------------
start "editör kilidi yazmayı engelliyor"
reseed
wp eval "update_post_meta( $PAGE_A, '_edit_lock', time() . ':1' );" >/dev/null 2>&1
OUT="$(wpx elementor set "$PAGE_A" f921a02 --settings '{"align":"center"}' 2>&1)"
assert_contains "$OUT" "Locked by" "kilitliyken yazma reddedildi"
assert_not_contains "$(settings_json "$PAGE_A" f921a02)" '"align":"center"' "reddedilen yazma sayfaya işlenmedi"

REPORT="$(wpx elementor lock "$PAGE_A" 2>&1)"
assert_contains "$REPORT" "Locked by: beyler" "lock raporu sahibi gösteriyor"
assert_not_contains "$REPORT" "Lock: none" "rapor kendi kendisiyle çelişmiyor"

wpx elementor set "$PAGE_A" f921a02 --settings '{"align":"center"}' --force >/dev/null 2>&1
assert_contains "$(settings_json "$PAGE_A" f921a02)" '"align":"center"' "--force kilidi geçiyor"

wp eval "update_post_meta( $PAGE_A, '_edit_lock', ( time() - 200 ) . ':1' );" >/dev/null 2>&1
assert_contains "$(wpx elementor lock "$PAGE_A" 2>&1)" "Write access: allowed" "bayat kilit engellemiyor"
wp eval "delete_post_meta( $PAGE_A, '_edit_lock' );" >/dev/null 2>&1

# ---------------------------------------------------------------------------
# 16. V4 (atomic) pages must be readable and writable, not refused.
# ---------------------------------------------------------------------------
start "V4 sayfa okunuyor ve prop yazılabiliyor"
OUT="$(wpx elementor tree "$PAGE_V4" 2>&1)"
assert_contains "$OUT" "e-heading [v4head1]" "atomic ağaç render edildi"
assert_contains "$OUT" "Atomic Hero" "prop zarfı çözülüp okunabilir etiket üretildi"

wpx elementor set "$PAGE_V4" v4head1 --settings '{"title":"Rewritten By Agent"}' >/dev/null 2>&1
assert_contains "$(wpx elementor tree "$PAGE_V4" 2>&1)" "Rewritten By Agent" "prop yazması uygulandı"

# ---------------------------------------------------------------------------
# 17. A malformed V4 prop must be refused by Elementor's own parser.
# ---------------------------------------------------------------------------
start "bozuk V4 prop'u Elementor'ın parser'ı reddediyor"
OUT="$(wpx elementor set "$PAGE_V4" v4head1 --settings '{"title":{"bogus":"shape"}}' 2>&1)"
assert_contains "$OUT" "Elementor rejected" "geçersiz prop reddedildi"
assert_contains "$(wpx elementor tree "$PAGE_V4" 2>&1)" "Rewritten By Agent" "reddedilen yazma dokümanı bozmadı"

# ---------------------------------------------------------------------------
# 18. V4 styles must render, and must not go stale after a second write.
# ---------------------------------------------------------------------------
start "V4 stilleri render ediliyor ve bayatlamıyor"
wpx elementor style "$PAGE_V4" v4head1 --desktop '{"font-size":"72px"}' --mobile '{"font-size":"32px"}' >/dev/null 2>&1
render_v4
assert_contains "$(v4_css desktop)" "font-size:72px" "desktop stili CSS'e yazıldı"
assert_contains "$(v4_css mobile)" "font-size:32px" "mobile stili ayrı dosyaya yazıldı"
assert_contains "$(v4_css mobile)" "@media" "mobile stili media query içinde"

wpx elementor style "$PAGE_V4" v4head1 --desktop '{"font-size":"120px"}' >/dev/null 2>&1
render_v4
assert_contains "$(v4_css desktop)" "font-size:120px" "ikinci yazma sonrası CSS tazelendi"
assert_not_contains "$(v4_css desktop)" "font-size:72px" "eski değer CSS'te kalmadı"

# ---------------------------------------------------------------------------
# 19. Operations wpx cannot do on V4 must be refused, not half-applied.
# ---------------------------------------------------------------------------
start "V4'te desteklenmeyen işlemler açıkça reddediliyor"
for op in "duplicate $PAGE_V4 v4head1" "wrap $PAGE_V4 v4head1 v4btn01"; do
    OUT="$(wpx elementor $op 2>&1)"
    assert_contains "$OUT" "cannot" "'${op%% *}' V4'te reddedildi"
done
OUT="$(wpx elementor container create "$PAGE_V4" --direction row 2>&1)"
assert_contains "$OUT" "cannot" "'container create' V4'te reddedildi"
assert_contains "$(wpx elementor tree "$PAGE_V4" 2>&1)" "e-heading [v4head1]" "reddedilen işlemler dokümanı değiştirmedi"

# ---------------------------------------------------------------------------

printf '\n\033[1m─────────────────────────────\033[0m\n'
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32m%d geçti, 0 başarısız\033[0m\n' "$PASS"
else
    printf '\033[31m%d geçti, %d BAŞARISIZ\033[0m\n' "$PASS" "$FAIL"
fi

reseed >/dev/null 2>&1
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
