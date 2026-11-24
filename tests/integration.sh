#!/usr/bin/env bash
# Integration tests for the Quick Photo plugin against the dev RackTables
# instance (http://127.0.0.1:8090). Needs colima docker and ../racktables-dev.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
DEV="$HERE/../../racktables-dev"
BASE="http://127.0.0.1:8090/index.php"
export DOCKER_CONTEXT=colima
set -a; . "$DEV/.env"; set +a
JAR="$(mktemp)"
PASS=0; FAIL=0

sql() { (cd "$DEV" && docker compose exec -T db sh -c "mariadb -N -uroot -p\"\$MARIADB_ROOT_PASSWORD\" racktables -e \"$1\""); }
get() { curl -s -b "$JAR" -c "$JAR" -u "admin:$RT_ADMIN_PASSWORD" "$@"; }
ok()   { PASS=$((PASS+1)); echo "ok   - $1"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL - $1"; }
has()  { if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else bad "$1"; fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then bad "$1"; else ok "$1"; fi; }

PHOTO_ID=""; OLD_COMMENT=""; BASE_FILE_ID=""; TRIGGER_ON=""
RC_HEX=""; RC_MD5=""; QP_USER=""; SHARED_LINK=""; REN_OBJ=""; REN_OLD_HEX=""
QJAR="$(mktemp)"
BADNAME="$(mktemp)"
# hex of a string, safe to pass through sql() as UNHEX('...')
md5q() { if command -v md5 >/dev/null 2>&1; then md5 -q "$1"; else md5sum "$1" | cut -d' ' -f1; fi; }
hexs() { printf '%s' "$1" | xxd -p | tr -d '\n'; }
clear_rc_cache() { sql "UPDATE Script SET script_text='' WHERE script_name='RackCodeCache'" >/dev/null 2>&1; }
CLEANED=""
cleanup() {
  local rc=$?
  [ -n "${1:-}" ] && rc=$1
  [ -n "$CLEANED" ] && exit "$rc"
  CLEANED=1
  if [ -n "$RC_HEX" ]; then
    sql "UPDATE Script SET script_text=UNHEX('$RC_HEX') WHERE script_name='RackCode'" >/dev/null 2>&1
    clear_rc_cache
    now="$(sql "SELECT MD5(script_text) FROM Script WHERE script_name='RackCode'" | tr -d '\r\n')"
    [ "$now" = "$RC_MD5" ] && echo "cleanup: RackCode restored (md5 $now)" || echo "cleanup: RACKCODE RESTORE FAILED ($now != $RC_MD5)"
  fi
  if [ -n "$QP_USER" ]; then
    sql "DELETE FROM UserConfig WHERE user='qptest'" >/dev/null 2>&1
    sql "DELETE FROM UserAccount WHERE user_name='qptest'" >/dev/null 2>&1
  fi
  [ -n "$SHARED_LINK" ] && sql "DELETE FROM FileLink WHERE file_id=40 AND entity_type='rack'" >/dev/null 2>&1
  [ -n "$REN_OBJ" ] && [ -n "$REN_OLD_HEX" ] && sql "UPDATE Object SET name=UNHEX('$REN_OLD_HEX') WHERE id=$REN_OBJ" >/dev/null 2>&1
  if [ -n "$PHOTO_ID" ] && [ -n "$OLD_COMMENT" ]; then
    sql "UPDATE File SET comment=$OLD_COMMENT WHERE id=$PHOTO_ID" >/dev/null 2>&1
  fi
  [ -n "$TRIGGER_ON" ] && sql "DROP TRIGGER IF EXISTS qp_test_fail" >/dev/null 2>&1
  if [ -n "$BASE_FILE_ID" ]; then
    sql "DELETE FROM FileLink WHERE file_id > $BASE_FILE_ID" >/dev/null 2>&1
    sql "DELETE FROM File WHERE id > $BASE_FILE_ID" >/dev/null 2>&1
  fi
  rm -f "$JAR" "$QJAR" "$BADNAME" "${BIGTMP:-}"
  exit "$rc"
}
trap cleanup EXIT
trap 'cleanup 130' INT
trap 'cleanup 143' TERM

oid() { sql "SELECT id FROM Object WHERE name='$1' AND objtype_id=$2 LIMIT 1" | tr -d '\r\n'; }

# install (idempotent: ignore result, verify afterwards)
get -o /dev/null "$BASE?module=redirect&page=plugins&tab=edit&op=install&name=quickphoto"
st="$(sql "SELECT state FROM Plugin WHERE name='quickphoto'" | tr -d '\r\n')"
[ "$st" = "enabled" ] && ok "plugin installed and enabled" || bad "plugin installed and enabled (state='$st')"

RACK="$(oid A-01 1560)"; ROW="$(oid 'Row A' 1561)"; LOC="$(oid 'Building A - Server Room' 1562)"; OBJ="$(oid nc-web-01 4)"

for spec in "rack:rack_id:$RACK" "row:row_id:$ROW" "location:location_id:$LOC" "object:object_id:$OBJ"; do
  IFS=: read -r page key id <<<"$spec"
  body="$(get -w '\n%{http_code}' "$BASE?page=$page&tab=photos&$key=$id")"
  code="${body##*$'\n'}"
  [ "$code" = "200" ] && ok "$page photos tab HTTP 200" || bad "$page photos tab HTTP $code"
  has "$page photos tab has root div" "$body" 'id="quickphoto"'
  has "$page photos tab has add form" "$body" 'class="qp-add"'
  has "$page photos tab has noscript" "$body" '<noscript>'
done

# rack A-01 content
body="$(get "$BASE?page=rack&tab=photos&rack_id=$RACK")"
has   "rack shows front jpg thumbnail" "$body" 'rack-a-01-front-'
hasnt "rack hides PDF" "$body" 'rack-a-01-cabling.pdf'
has   "retake form present" "$body" 'class="qp-replace"'
for f in qp_token file_id expected_mtime expected_size expected_links; do
  has "retake form field $f" "$body" "name=\"$f\""
done
has "thumbnail url has cache-bust" "$body" '&amp;v='
has "file input accept" "$body" 'accept="image/*"'
has "viewport meta on tab" "$body" 'name="viewport"'
body2="$(get "$BASE?page=rack&tab=default&rack_id=$RACK")"
hasnt "no viewport meta on default tab" "$body2" 'name="viewport"'
hasnt "no quickphoto root on default tab" "$body2" 'id="quickphoto"'

# escaping
PHOTO_ID="$(sql "SELECT File.id FROM File JOIN FileLink ON FileLink.file_id=File.id WHERE FileLink.entity_type='rack' AND FileLink.entity_id=$RACK AND File.type='image/jpeg' ORDER BY File.id LIMIT 1" | tr -d '\r\n')"
OLD_COMMENT="$(sql "SELECT QUOTE(comment) FROM File WHERE id=$PHOTO_ID" | tr -d '\r\n')"
sql "UPDATE File SET comment='<script>alert(1)</script>' WHERE id=$PHOTO_ID" >/dev/null
body="$(get "$BASE?page=rack&tab=photos&rack_id=$RACK")"
has   "comment escaped" "$body" '&lt;script&gt;'
hasnt "comment not raw" "$body" '<script>alert(1)'

# ---------------------------------------------------------------- op addPhoto
FX="$HERE/fixtures/out"
BASE_FILE_ID="$(sql "SELECT COALESCE(MAX(id),0) FROM File" | tr -d '\r\n')"
nfiles() { sql "SELECT COUNT(*) FROM File" | tr -d '\r\n'; }
tab_page() { get "$BASE?page=rack&tab=photos&rack_id=$1"; }
token_of() { printf '%s' "$1" | grep -o 'class="qp-add"[^>]*>[^<]*<input type="hidden" name="qp_token" value="[0-9a-f]*"' | grep -o 'value="[0-9a-f]*"' | head -1 | cut -d'"' -f2; }
action_of() { printf '%s' "$1" | grep -o '<form class="qp-add"[^>]*action="[^"]*"' | sed 's/.*action="//; s/"$//; s/&amp;/\&/g'; }
# usage: add_photo <rack_id> <file> <token> <comment> [extra curl args...]; prints the page after redirect
add_photo() {
  local rid="$1" file="$2" tok="$3" cmt="$4"; shift 4
  local page act
  page="$(tab_page "$rid")"
  act="$(action_of "$page")"
  case "$act" in http*) ;; /*) act="http://127.0.0.1:8090$act" ;; *) act="http://127.0.0.1:8090/$act" ;; esac
  ${GETTER:-get} -L "$@" -F "qp_token=$tok" --form-string "comment=$cmt" -F "file=@$file" "$act"
}

page="$(tab_page "$RACK")"
TOK="$(token_of "$page")"
[ -n "$TOK" ] && ok "scraped csrf token" || bad "scraped csrf token"

n0="$(nfiles)"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" '<b>hi</b>')"
n1="$(nfiles)"
[ "$n1" -eq $((n0+1)) ] && ok "valid upload adds one file" || bad "valid upload adds one file ($n0 -> $n1)"
row="$(sql "SELECT CONCAT(id,'|',name,'|',type,'|',comment) FROM File WHERE id > $BASE_FILE_ID ORDER BY id LIMIT 1" | tr -d '\r\n')"
IFS='|' read -r fid fname ftype fcomment <<<"$row"
printf '%s' "$fname" | grep -Eq '^A-01_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(_[0-9]+)?\.jpg$' && ok "name pattern ($fname)" || bad "name pattern ($fname)"
[ "$ftype" = "image/jpeg" ] && ok "type image/jpeg" || bad "type image/jpeg ($ftype)"
[ "$fcomment" = "<b>hi</b>" ] && ok "comment stored as given" || bad "comment stored as given ($fcomment)"
lk="$(sql "SELECT COUNT(*) FROM FileLink WHERE file_id=$fid AND entity_type='rack' AND entity_id=$RACK" | tr -d '\r\n')"
[ "$lk" = "1" ] && ok "linked to rack" || bad "linked to rack ($lk)"
has "success message shown after redirect" "$out" 'Photo added'
has "new photo listed on tab" "$out" "$fname"
hasnt "comment escaped on tab" "$out" '<b>hi</b>'

# several uploads in a row: names stay unique, same-second ones get a suffix
for i in 1 2 3 4 5; do add_photo "$RACK" "$FX/small.jpg" "$TOK" "" >/dev/null; done
names="$(sql "SELECT name FROM File WHERE id > $BASE_FILE_ID" | tr -d '\r')"
total="$(printf '%s\n' "$names" | grep -c .)"; uniq_n="$(printf '%s\n' "$names" | sort -u | grep -c .)"
[ "$total" -eq 6 ] && [ "$uniq_n" -eq 6 ] && ok "6 uploads, 6 unique names" || bad "6 uploads, unique names ($total/$uniq_n)"
printf '%s\n' "$names" | grep -Eq '_2\.jpg$' && ok "same-second upload gets _2" || bad "same-second upload gets _2"

# rejected requests: no new row
n0="$(nfiles)"
out="$(add_photo "$RACK" "$FX/small.jpg" "" "x")"
has "missing token rejected" "$out" 'security token'
out="$(add_photo "$RACK" "$FX/small.jpg" "deadbeef" "x")"
has "wrong token rejected" "$out" 'security token'
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "x" -H 'Sec-Fetch-Site: cross-site')"
has "cross-site rejected" "$out" 'Cross-site'
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "x" -H 'Origin: https://evil.example')"
has "foreign origin rejected" "$out" 'Cross-site'
[ "$(nfiles)" -eq "$n0" ] && ok "no rows from rejected requests" || bad "no rows from rejected requests"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "same origin" -H 'Origin: http://127.0.0.1:8090' -H 'Sec-Fetch-Site: same-origin')"
[ "$(nfiles)" -eq $((n0+1)) ] && ok "same-origin headers accepted" || bad "same-origin headers accepted"

# custom name field
UTF_NAME="A-01 pohled zep$(printf '\xc5\x99')edu"
UTF_STORED="$UTF_NAME.jpg"
name_hex_exists() { sql "SELECT COUNT(*) FROM File WHERE id > $BASE_FILE_ID AND name=UNHEX('$(hexs "$1")')" | tr -d '\r\n'; }
n0="$(nfiles)"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" --form-string "name=$UTF_NAME")"
[ "$(name_hex_exists "$UTF_STORED")" = "1" ] && ok "custom name stored exactly (utf-8 bytes)" || bad "custom name stored exactly"
has "custom name success message" "$out" 'Photo added'
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" --form-string "name=$UTF_NAME")"
[ "$(name_hex_exists "$UTF_NAME""_2.jpg")" = "1" ] && ok "same custom name again gets _2" || bad "same custom name again gets _2"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" --form-string "name=$UTF_NAME.JPEG")"
[ "$(name_hex_exists "$UTF_NAME""_3.jpg")" = "1" ] && ok "custom name with .JPEG suffix normalised, gets _3" || bad "custom name with .JPEG suffix"
n1="$(nfiles)"
[ "$n1" -eq $((n0+3)) ] && ok "three custom-name uploads, three rows" || bad "three custom-name uploads ($n0 -> $n1)"
n0="$n1"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" --form-string "name=   ")"
row="$(sql "SELECT name FROM File WHERE id > $BASE_FILE_ID ORDER BY id DESC LIMIT 1" | tr -d '\r\n')"
printf '%s' "$row" | grep -Eq '^A-01_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(_[0-9]+)?\.jpg$' && ok "blank name uses automatic pattern ($row)" || bad "blank name uses automatic pattern ($row)"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" --form-string 'name=<script>alert(1)</script>')"
[ "$(name_hex_exists '-script-alert(1)--script-.jpg')" = "1" ] && ok "script name stored sanitised" || bad "script name stored sanitised"
page="$(tab_page "$RACK")"
hasnt "script name not rendered raw" "$page" '<script>alert(1)'
has "script name rendered escaped" "$page" '-script-alert(1)--script-.jpg'
n0="$(nfiles)"
printf '\xff\xfe\xfd' > "$BADNAME"
out="$(add_photo "$RACK" "$FX/small.jpg" "$TOK" "" -F "name=<$BADNAME")"
has "invalid utf-8 name shows error" "$out" 'not valid text'
[ "$(nfiles)" -eq "$n0" ] && ok "invalid utf-8 name saves nothing" || bad "invalid utf-8 name saves nothing"
page="$(tab_page "$RACK")"
sug="$(printf '%s' "$page" | grep -o 'data-name-suggestion="[^"]*"' | head -1 | sed 's/^[^"]*"//; s/"$//')"
printf '%s' "$sug" | grep -Eq '^A-01_[0-9]{4}-[0-9]{2}-[0-9]{2}$' && ok "tab has name suggestion ($sug)" || bad "tab has name suggestion ($sug)"

# validation failures
n0="$(nfiles)"
out="$(add_photo "$RACK" "$FX/over.jpg" "$TOK" "")"; has "over.jpg message" "$out" "$(printf '4032 pixels')"
out="$(add_photo "$RACK" "$FX/exif.jpg" "$TOK" "")"; has "exif.jpg message" "$out" 'EXIF data'
out="$(add_photo "$RACK" "$FX/pic.png" "$TOK" "")";  has "pic.png message" "$out" 'not a JPEG'
out="$(add_photo "$RACK" "$FX/huge.bin" "$TOK" "")"; has "huge.bin message" "$out" 'larger than 8 MB'
[ "$(nfiles)" -eq "$n0" ] && ok "no rows from invalid files" || bad "no rows from invalid files"

# over upload_max_filesize (33 MB): clear message, no PHP warnings
BIGTMP="$(mktemp)"; head -c 34603008 /dev/zero > "$BIGTMP"
warn_count() { (cd "$DEV" && docker compose logs web 2>&1 | grep -cE 'PHP (Warning|Notice|Deprecated)'); }
w0="$(warn_count)"
out="$(add_photo "$RACK" "$BIGTMP" "$TOK" "")"
has "33 MB upload message" "$out" 'larger than 8 MB'
[ "$(nfiles)" -eq "$n0" ] && ok "no row from 33 MB upload" || bad "no row from 33 MB upload"
w1="$(warn_count)"
[ "$w1" -eq "$w0" ] && ok "no PHP warnings in web log" || bad "no PHP warnings in web log ($w0 -> $w1)"
rm -f "$BIGTMP"

# atomicity: FileLink insert fails for rack A-02 -> no orphan File row
RACK2="$(oid A-02 1560)"
TRIGGER_ON=1
sql "CREATE TRIGGER qp_test_fail BEFORE INSERT ON FileLink FOR EACH ROW SET NEW.entity_id = IF(NEW.entity_type='rack' AND NEW.entity_id=$RACK2, NULL, NEW.entity_id)" >/dev/null
page2="$(tab_page "$RACK2")"; TOK2="$(token_of "$page2")"
n0="$(nfiles)"
out="$(add_photo "$RACK2" "$FX/small.jpg" "$TOK2" "")"
has "failed link insert shows error" "$out" 'could not be saved'
hasnt "no raw db text" "$out" 'SQLSTATE'
[ "$(nfiles)" -eq "$n0" ] && ok "rollback leaves no orphan file" || bad "rollback leaves no orphan file"
sql "DROP TRIGGER IF EXISTS qp_test_fail" >/dev/null; TRIGGER_ON=""

# ---------------------------------------------------------------- op replacePhoto
w0="$(warn_count)"
RACK3="$(oid A-03 1560)"; RACK4="$(oid A-04 1560)"
XSS_NAME='"><img src=x onerror=alert(1)>.jpg'
XSS_OBJ='<img src=y onerror=alert(2)>'
# leftovers of an interrupted run
sql "DELETE FROM FileLink WHERE file_id IN (SELECT id FROM File WHERE name LIKE 'qp-test-%' OR name=UNHEX('$(hexs "$XSS_NAME")'))" >/dev/null
sql "DELETE FROM File WHERE name LIKE 'qp-test-%' OR name=UNHEX('$(hexs "$XSS_NAME")')" >/dev/null
sql "DELETE FROM FileLink WHERE file_id=40 AND entity_type='rack'" >/dev/null

row_of() { sql "SELECT CONCAT(MD5(contents),'|',type,'|',name,'|',size,'|',mtime,'|',IF(thumbnail IS NULL,'nothumb','thumb')) FROM File WHERE id=$1" | tr -d '\r\n'; }
# value of hidden field <name> in the retake form of file <fid>
rfield() { printf '%s' "$1" | grep -o "name=\"file_id\" value=\"$2\">.*" | head -1 | grep -o "name=\"$3\" value=\"[^\"]*\"" | head -1 | sed 's/.*value="//; s/"$//; s/&amp;/\&/g'; }
# data-warning attribute (still escaped) of the retake form of file <fid>
rwarn() { printf '%s' "$1" | grep -o "data-warning=\"[^\"]*\"><input type=\"hidden\" name=\"qp_token\" value=\"[0-9a-f]*\"><input type=\"hidden\" name=\"file_id\" value=\"$2\"" | head -1 | sed 's/^data-warning="//; s/"><input.*//'; }
qget() { curl -s -b "$QJAR" -c "$QJAR" -u "qptest:$QP_PW" "$@"; }
# usage: replace_photo <getter> <rack_id> <file_id> <token> <mtime> <size> <links> <file> [curl args]
replace_photo() {
  local g="$1" rid="$2" fid="$3" tok="$4" mt="$5" sz="$6" lk="$7" f="$8"; shift 8
  "$g" -L "$@" --form-string "qp_token=$tok" --form-string "file_id=$fid" --form-string "expected_mtime=$mt" \
    --form-string "expected_size=$sz" --form-string "expected_links=$lk" -F "file=@$f;type=image/jpeg" \
    "$BASE?module=redirect&page=rack&tab=photos&op=replacePhoto&rack_id=$rid"
}
# replace with the values the getter currently sees on the tab
replace_now() {
  local g="$1" rid="$2" fid="$3" f="$4" pg; shift 4
  pg="$("$g" "$BASE?page=rack&tab=photos&rack_id=$rid")"
  replace_photo "$g" "$rid" "$fid" "$(token_of "$pg")" "$(rfield "$pg" "$fid" expected_mtime)" \
    "$(rfield "$pg" "$fid" expected_size)" "$(rfield "$pg" "$fid" expected_links)" "$f" "$@"
}

# a PNG photo on rack A-03, one day old
sql "INSERT INTO File (name,type,size,ctime,mtime,atime,contents,comment) VALUES ('qp-test-png.png','image/png',0,NOW()-INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY,NOW(),UNHEX('$(xxd -p "$FX/pic.png" | tr -d '\n')'),'')" >/dev/null
PNG="$(sql "SELECT id FROM File WHERE name='qp-test-png.png'" | tr -d '\r\n')"
sql "UPDATE File SET size=LENGTH(contents) WHERE id=$PNG; INSERT INTO FileLink (file_id,entity_type,entity_id) VALUES ($PNG,'rack',$RACK3)" >/dev/null
get -o /dev/null "$BASE?module=image&img=preview&file_id=$PNG"
before="$(row_of "$PNG")"
case "$before" in *'|image/png|qp-test-png.png|3674|'*'|thumb') ok "png fixture with cached thumbnail" ;; *) bad "png fixture with cached thumbnail ($before)" ;; esac

page="$(tab_page "$RACK3")"
TOK3="$(token_of "$page")"; MT="$(rfield "$page" "$PNG" expected_mtime)"; SZ="$(rfield "$page" "$PNG" expected_size)"; LK="$(rfield "$page" "$PNG" expected_links)"
[ -n "$MT" ] && [ "$SZ" = "3674" ] && [ ${#LK} -eq 64 ] && ok "retake form values scraped" || bad "retake form values scraped ($MT/$SZ/$LK)"

# rejected: stale values, bad requests, wrong file
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "2000-01-01 00:00:00" "$SZ" "$LK" "$FX/small.jpg")"
has "stale mtime rejected" "$out" 'changed meanwhile'
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "999" "$LK" "$FX/small.jpg")"
has "stale size rejected" "$out" 'changed meanwhile'
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$(printf '%064d' 0)" "$FX/small.jpg")"
has "stale links fingerprint rejected" "$out" 'changed meanwhile'
sql "INSERT INTO FileLink (file_id,entity_type,entity_id) VALUES ($PNG,'rack',$RACK4)" >/dev/null
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "link added after opening the tab rejected" "$out" 'changed meanwhile'
sql "DELETE FROM FileLink WHERE file_id=$PNG AND entity_id=$RACK4" >/dev/null
out="$(replace_photo get "$RACK3" "$PNG" "" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "replace without token rejected" "$out" 'security token'
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg" -H 'Origin: https://evil.example')"
has "replace from foreign origin rejected" "$out" 'Cross-site'
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/exif.jpg")"
has "replace with EXIF jpeg rejected" "$out" 'EXIF data'
out="$(replace_photo get "$RACK2" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "file not linked to the entity rejected" "$out" 'not on this page'
out="$(replace_photo get "$RACK3" "abc" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "bad file_id rejected" "$out" 'not on this page'
PDF="$(sql "SELECT id FROM File WHERE name='rack-a-03-cabling.pdf'" | tr -d '\r\n')"
pdf_before="$(row_of "$PDF")"
out="$(replace_photo get "$RACK3" "$PDF" "$TOK3" "x" "1" "$LK" "$FX/small.jpg")"
has "non-image file rejected" "$out" 'not on this page'
[ "$(row_of "$PDF")" = "$pdf_before" ] && ok "pdf unchanged" || bad "pdf unchanged"
hasnt "no raw db text on rejects" "$out" 'SQLSTATE'
[ "$(row_of "$PNG")" = "$before" ] && ok "png unchanged after rejected requests" || bad "png unchanged after rejected requests"

# admin replaces the PNG
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "replace success message" "$out" 'Photo replaced'
after="$(row_of "$PNG")"
IFS='|' read -r amd5 atype aname asize amtime athumb <<<"$after"
[ "$amd5" = "$(md5q "$FX/small.jpg")" ] && ok "contents replaced" || bad "contents replaced"
[ "$atype" = "image/jpeg" ] && ok "type now image/jpeg" || bad "type now image/jpeg ($atype)"
[ "$aname" = "qp-test-png.png" ] && ok "name kept" || bad "name kept ($aname)"
[ "$asize" = "56685" ] && ok "size updated" || bad "size updated ($asize)"
[ "$athumb" = "nothumb" ] && ok "thumbnail cleared" || bad "thumbnail cleared ($athumb)"
[ "$amtime" != "$MT" ] && ok "mtime changed" || bad "mtime changed"
[ "$(sql "SELECT COUNT(*) FROM File WHERE id=$PNG" | tr -d '\r\n')" = "1" ] && ok "same file id" || bad "same file id"
out="$(replace_photo get "$RACK3" "$PNG" "$TOK3" "$MT" "$SZ" "$LK" "$FX/small.jpg")"
has "replaying the old form rejected" "$out" 'changed meanwhile'

# shared photo and escaping
SHARED_LINK=1
sql "INSERT INTO FileLink (file_id,entity_type,entity_id) VALUES (40,'rack',$RACK)" >/dev/null
REN_OBJ="$(sql "SELECT id FROM Object WHERE objtype_id=4 AND name IN ('nc-dns-08', UNHEX('$(hexs "$XSS_OBJ")')) LIMIT 1" | tr -d '\r\n')"
REN_OLD_HEX="$(hexs nc-dns-08)"
sql "UPDATE Object SET name=UNHEX('$(hexs "$XSS_OBJ")') WHERE id=$REN_OBJ" >/dev/null
sql "INSERT INTO File (name,type,size,ctime,mtime,atime,contents,comment) VALUES (UNHEX('$(hexs "$XSS_NAME")'),'image/jpeg',4,NOW(),NOW(),NOW(),UNHEX('FFD8FFD9'),'')" >/dev/null
XSSF="$(sql "SELECT id FROM File WHERE name=UNHEX('$(hexs "$XSS_NAME")')" | tr -d '\r\n')"
sql "INSERT INTO FileLink (file_id,entity_type,entity_id) VALUES ($XSSF,'rack',$RACK),($XSSF,'object',$REN_OBJ)" >/dev/null
page="$(tab_page "$RACK")"
has "shared photo warns with Row A" "$(rwarn "$page" 40)" 'Row A'
has "shared warning says it changes everywhere" "$(rwarn "$page" 40)" 'everywhere'
[ -z "$(rwarn "$page" 1)" ] && ok "unshared photo has no warning" || bad "unshared photo has no warning"
has   "xss file name escaped on tab" "$page" '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;.jpg'
hasnt "xss file name not raw on tab" "$page" '<img src=x onerror'
has   "xss entity name escaped in warning" "$(rwarn "$page" "$XSSF")" '&lt;img src=y onerror=alert(2)&gt;'
hasnt "xss entity name not raw on tab" "$page" '<img src=y onerror'

# ---------------------------------------------------------------- RackCode: restricted user qptest
MARK=$'\n# qp-test-begin'
RC_MD5="$(sql "SELECT MD5(SUBSTRING_INDEX(script_text, UNHEX('$(hexs "$MARK")'), 1)) FROM Script WHERE script_name='RackCode'" | tr -d '\r\n')"
RC_HEX="$(sql "SELECT HEX(SUBSTRING_INDEX(script_text, UNHEX('$(hexs "$MARK")'), 1)) FROM Script WHERE script_name='RackCode'" | tr -d '\r\n')"
[ -n "$RC_HEX" ] && ok "RackCode backed up" || bad "RackCode backed up"
QP_PW="$(openssl rand -hex 16)"; QP_USER=1
sql "DELETE FROM UserConfig WHERE user='qptest'; DELETE FROM UserAccount WHERE user_name='qptest'" >/dev/null
sql "INSERT INTO UserAccount (user_name,user_password_hash,user_realname) VALUES ('qptest',SHA1('$QP_PW'),'Quick Photo test')" >/dev/null
set_rules() {
  local body="$MARK" r
  for r in "$@"; do body="$body"$'\n'"$r"; done
  sql "UPDATE Script SET script_text=CONCAT(UNHEX('$RC_HEX'), UNHEX('$(hexs "$body")')) WHERE script_name='RackCode'" >/dev/null
  clear_rc_cache
}
ALLOW_RACK='allow {$username_qptest} and {$page_rack}'
ALLOW_FILE='allow {$username_qptest} and {$page_file}'

# 1: everything on racks and files, nothing on rows
set_rules "$ALLOW_RACK" "$ALLOW_FILE"
qpage="$(qget -w '\n%{http_code}' "$BASE?page=rack&tab=photos&rack_id=$RACK")"
[ "${qpage##*$'\n'}" = "200" ] && ok "qptest sees photos tab" || bad "qptest sees photos tab (${qpage##*$'\n'})"
has   "qptest gets retake when allowed" "$qpage" 'class="qp-replace"'
QTOK0="$(token_of "$qpage")"
has   "qptest warning counts hidden row" "$(rwarn "$qpage" 40)" '1 other place'
hasnt "qptest warning hides row name" "$(rwarn "$qpage" 40)" 'Row A'
out="$(replace_now qget "$RACK3" "$PNG" "$FX/small.jpg")"
has "qptest may replace when allowed" "$out" 'Photo replaced'

# 2: file replace denied
set_rules 'deny {$username_qptest} and {$page_file} and {$tab_replaceData}' "$ALLOW_RACK" "$ALLOW_FILE"
qpage="$(qget "$BASE?page=rack&tab=photos&rack_id=$RACK3")"
has   "qptest still sees the photo" "$qpage" "qp-test-png.png"
hasnt "qptest gets no retake form" "$qpage" 'class="qp-replace"'
sql "UPDATE File SET contents=UNHEX('$(xxd -p "$FX/pic.png" | tr -d '\n')'), size=LENGTH(contents), type='image/png' WHERE id=$PNG" >/dev/null
apage="$(tab_page "$RACK3")"
before="$(row_of "$PNG")"
out="$(replace_photo qget "$RACK3" "$PNG" "$(token_of "$qpage")" "$(rfield "$apage" "$PNG" expected_mtime)" \
  "$(rfield "$apage" "$PNG" expected_size)" "$(rfield "$apage" "$PNG" expected_links)" "$FX/small.jpg")"
has "qptest direct replace rejected" "$out" 'not allowed to replace'
[ "$(row_of "$PNG")" = "$before" ] && ok "contents unchanged after denied replace" || bad "contents unchanged after denied replace"

# 3: file download denied
set_rules 'deny {$username_qptest} and {$page_file} and {$tab_download}' "$ALLOW_RACK" "$ALLOW_FILE"
qpage="$(qget "$BASE?page=rack&tab=photos&rack_id=$RACK")"
has   "qptest tab still renders" "$qpage" 'id="quickphoto"'
hasnt "qptest without download sees no photo" "$qpage" 'rack-a-01-front-'

# 4: rack allowed but the Photos tab is not
set_rules 'deny {$username_qptest} and {$page_rack} and {$tab_photos}' "$ALLOW_RACK" "$ALLOW_FILE"
qpage="$(qget "$BASE?page=rack&tab=photos&rack_id=$RACK")"
hasnt "qptest without tab_photos gets no photos tab" "$qpage" 'id="quickphoto"'
n0="$(nfiles)"
out="$(GETTER=qget add_photo "$RACK" "$FX/small.jpg" "$QTOK0" "denied")"
hasnt "qptest without tab_photos cannot add" "$out" 'Photo added'
[ "$(nfiles)" -eq "$n0" ] && ok "no row from refused add" || bad "no row from refused add"

# 5: only the plugin permissions: tab_photos + op_addPhoto
set_rules 'allow {$username_qptest} and {$tab_photos}' 'allow {$username_qptest} and {$op_addPhoto}'
qpage="$(qget "$BASE?page=rack&tab=photos&rack_id=$RACK")"
has "qptest with plugin perms only sees photos tab" "$qpage" 'id="quickphoto"'
QTOK="$(token_of "$qpage")"
n0="$(nfiles)"
out="$(GETTER=qget add_photo "$RACK" "$FX/small.jpg" "$QTOK" "by qptest")"
has "qptest with plugin perms only can add" "$out" 'Photo added'
[ "$(nfiles)" -eq $((n0+1)) ] && ok "restricted add created one file" || bad "restricted add created one file"
lastf="$(sql "SELECT id FROM File WHERE comment='by qptest' ORDER BY id DESC LIMIT 1" | tr -d '\r\n')"
[ -n "$lastf" ] && [ "$(sql "SELECT COUNT(*) FROM FileLink WHERE file_id=$lastf AND entity_type='rack' AND entity_id=$RACK" | tr -d '\r\n')" = "1" ] && ok "restricted add linked to rack" || bad "restricted add linked to rack"

# restore now (the trap does it again on any exit)
sql "UPDATE Script SET script_text=UNHEX('$RC_HEX') WHERE script_name='RackCode'" >/dev/null; clear_rc_cache
[ "$(sql "SELECT MD5(script_text) FROM Script WHERE script_name='RackCode'" | tr -d '\r\n')" = "$RC_MD5" ] && ok "RackCode restored" || bad "RackCode restored"
w1="$(warn_count)"
[ "$w1" -eq "$w0" ] && ok "no PHP warnings in web log (replace)" || bad "no PHP warnings in web log (replace) ($w0 -> $w1)"

echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ]
