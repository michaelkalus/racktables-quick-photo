<?php
// Unit tests for quickphoto/lib.php. Run inside the dev web container.
define('QP_TESTING', 1);
$lib = __DIR__ . '/../quickphoto/lib.php';
if (!is_file($lib)) {
    $lib = '/var/www/racktables/plugins/quickphoto/lib.php';
}
require $lib;

$n = 0;
function check($cond, string $msg): void
{
    global $n;
    $n++;
    if (!$cond) {
        fwrite(STDERR, "FAIL #$n: $msg\n");
        exit(1);
    }
}
function eq($actual, $expected, string $msg): void
{
    check($actual === $expected, $msg . ' (got ' . var_export($actual, true) . ', want ' . var_export($expected, true) . ')');
}

$fx = __DIR__ . '/fixtures/out';
function f(string $name): array
{
    global $fx;
    $p = "$fx/$name";
    return ['error' => 0, 'size' => filesize($p), 'tmp_name' => $p];
}

eq(qp_jpeg_has_exif(file_get_contents("$fx/small.jpg")), false, 'small has no exif');
eq(qp_jpeg_has_exif(file_get_contents("$fx/exif.jpg")), true, 'exif.jpg has exif');
eq(qp_jpeg_has_exif('garbage'), false, 'garbage no exif');
eq(qp_jpeg_scan('garbage'), 'bad', 'garbage is bad');
eq(qp_jpeg_scan(file_get_contents("$fx/small.jpg")), 'ok', 'small scan ok');
$small = file_get_contents("$fx/small.jpg");
$exifseg = "\xff\xe1\x00\x0aExif\x00\x00\x00\x00";
$app0 = "\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
$sos = "\xff\xda\x00\x02\x00";
eq(qp_jpeg_scan("\xff\xd8" . $app0 . $exifseg . $sos), 'exif', 'exif after APP0 detected');
eq(qp_jpeg_scan("\xff\xd8" . $app0 . $sos), 'ok', 'APP0 then SOS ok');
eq(qp_jpeg_scan("\xff\xd8" . $app0 . "XX" . $sos), 'bad', 'garbage between segments rejected');
eq(qp_jpeg_scan("\xff\xd8" . $app0 . "\xff\xe1\x00\x40Exif"), 'bad', 'truncated segment rejected');
eq(qp_jpeg_scan(substr($small, 0, 30)), 'bad', 'truncated jpeg rejected');
eq(qp_jpeg_scan("\xff\xd8" . $app0 . "\xff\xe2\x00\x01" . $sos), 'bad', 'segment length < 2 rejected');
eq(qp_jpeg_scan("\xff\xd8" . $app0), 'bad', 'no SOS rejected');

eq(qp_validate_upload(null, 40000000, 35651584), 'too_large_post', 'post too large');
eq(qp_validate_upload(null, 100, 35651584), 'no_file', 'no file');
eq(qp_validate_upload(f('small.jpg'), 100, 35651584), null, 'small ok');
eq(qp_validate_upload(f('over.jpg'), 100, 35651584), 'too_many_pixels', 'over');
eq(qp_validate_upload(f('big.jpg'), 100, 35651584), null, 'big 4000 px ok');
eq(qp_validate_upload(f('exif.jpg'), 100, 35651584), 'has_exif', 'exif');
eq(qp_validate_upload(f('pic.png'), 100, 35651584), 'not_jpeg', 'png');
eq(qp_validate_upload(f('huge.bin'), 100, 35651584), 'too_big', 'huge');
$e = f('small.jpg');
$e['error'] = UPLOAD_ERR_PARTIAL;
eq(qp_validate_upload($e, 100, 35651584), 'upload_error', 'upload error');
$e['error'] = UPLOAD_ERR_INI_SIZE;
eq(qp_validate_upload($e, 100, 35651584), 'too_big', 'ini size is too_big');
$e['error'] = UPLOAD_ERR_FORM_SIZE;
eq(qp_validate_upload($e, 100, 35651584), 'too_big', 'form size is too_big');
$tmpbad = tempnam(sys_get_temp_dir(), 'qpbad');
file_put_contents($tmpbad, $small);
$mal = substr($small, 0, 2) . 'XX' . substr($small, 2);
file_put_contents($tmpbad, $mal);
check(qp_validate_upload(['error' => 0, 'size' => strlen($mal), 'tmp_name' => $tmpbad], 100, 35651584) !== null, 'malformed jpeg never passes');
unlink($tmpbad);

eq(qp_post_too_large([], [], 40000000, 35651584), true, 'empty post+files, big CL');
eq(qp_post_too_large(['a' => 'b'], [], 40000000, 35651584), false, 'post non-empty');
eq(qp_post_too_large([], ['file' => []], 40000000, 35651584), false, 'files non-empty');
eq(qp_post_too_large([], [], 100, 35651584), false, 'small CL');
eq(qp_post_too_large([], [], 40000000, 0), false, 'no limit');

foreach (['too_large_post', 'no_file', 'upload_error', 'too_big', 'not_jpeg', 'too_many_pixels', 'has_exif', 'bad_jpeg'] as $c) {
    check(qp_error_text($c) !== '', "error text $c");
}
foreach (['not_jpeg', 'too_many_pixels', 'has_exif', 'bad_jpeg'] as $c) {
    check(strpos(qp_error_text($c), 'Photos tab') !== false && strpos(qp_error_text($c), 'JavaScript') !== false, "resize hint $c");
}

eq(qp_name_label("Rozvad\u{011B}\u{010D} 2.NP", 'rack', 5), 'Rozvadec-2.NP', 'label translit');
eq(qp_name_label('', 'object', 288), 'object-288', 'label empty');
eq(qp_name_label('<b>x</b>', 'rack', 1), 'b-x-b', 'label tags');
eq(qp_name_label('<<<', 'rack', 7), 'rack-7', 'label only invalid chars');
eq(strlen(qp_name_label(str_repeat('a', 200), 'rack', 1)), 80, 'label max 80');

$ts = mktime(20, 31, 12, 11, 24, 2026);
eq(qp_photo_name('A-01', $ts, 1), 'A-01_2026-11-24_203112.jpg', 'photo name');
eq(qp_photo_name('A-01', $ts, 3), 'A-01_2026-11-24_203112_3.jpg', 'photo name attempt');
eq(qp_photo_name('A-01', $ts, 20), 'A-01_2026-11-24_203112_20.jpg', 'photo name attempt 20');

// user supplied names
eq(qp_user_photo_name("Pohled zep\u{0159}edu", 1), "Pohled zep\u{0159}edu.jpg", 'user name keeps diacritics and spaces');
eq(qp_user_photo_name('a/b\\c:d*e?f"g<h>i|j', 1), 'a-b-c-d-e-f-g-h-i-j.jpg', 'user name forbidden chars');
eq(qp_user_photo_name("  a \t\n  b   c  ", 1), 'a b c.jpg', 'user name whitespace collapsed and trimmed');
eq(qp_user_photo_name("a\x01b\x7fc", 1), 'a-b-c.jpg', 'user name control chars');
eq(qp_user_photo_name('x.JPG', 1), 'x.jpg', 'user name trailing .JPG');
eq(qp_user_photo_name('x.jpeg', 1), 'x.jpg', 'user name trailing .jpeg');
eq(qp_user_photo_name('x.jpg.jpg', 1), 'x.jpg.jpg', 'user name strips only one extension');
eq(qp_user_photo_name('x.JpEg ', 1), 'x.jpg', 'user name mixed case ext with trailing space');
eq(qp_user_photo_name(str_repeat('a', 150), 1), str_repeat('a', 100) . '.jpg', 'user name max 100');
eq(qp_user_photo_name(str_repeat("\u{0159}", 150), 1), str_repeat("\u{0159}", 100) . '.jpg', 'user name max 100 multibyte');
eq(qp_user_photo_name('', 1), null, 'user name empty');
eq(qp_user_photo_name("  \t ", 1), null, 'user name whitespace only');
eq(qp_user_photo_name('.jpg', 1), null, 'user name only extension');
eq(qp_user_photo_name('name', 1), 'name.jpg', 'user name attempt 1');
eq(qp_user_photo_name('name', 2), 'name_2.jpg', 'user name attempt 2');
eq(qp_user_photo_name('name', 3), 'name_3.jpg', 'user name attempt 3');
eq(qp_user_photo_name('name', 20), 'name_20.jpg', 'user name attempt 20');
eq(qp_user_photo_name('name', 25), 'name_20.jpg', 'user name attempt capped');
eq(qp_user_name_valid_utf8("A-01 zep\u{0159}edu"), true, 'valid utf8');
eq(qp_user_name_valid_utf8("bad\xff\xfe"), false, 'invalid utf8');
eq(qp_user_name_valid_utf8(''), true, 'empty is valid utf8');
eq(qp_name_suggestion('A-01', $ts), 'A-01_2026-11-24', 'name suggestion');

$a = ['entity_type' => 'object', 'entity_id' => 1];
$b = ['entity_type' => 'rack', 'entity_id' => 2];
$c = ['entity_type' => 'row', 'entity_id' => 3];
eq(qp_link_fingerprint([$a, $b]), qp_link_fingerprint([$b, $a]), 'fp order independent');
check(qp_link_fingerprint([$a, $b]) !== qp_link_fingerprint([$a, $b, $c]), 'fp changes on add');
eq(strlen(qp_link_fingerprint([$a])), 64, 'fp sha256 hex');

eq(qp_esc('<a href="x">\''), '&lt;a href=&quot;x&quot;&gt;&#039;', 'esc');

echo "OK $n\n";
