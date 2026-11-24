<?php
// Quick Photo - pure helpers. Must not call any RackTables function.

defined('QP_MAX_BYTES') || define('QP_MAX_BYTES', 8388608);
defined('QP_MAX_DIM') || define('QP_MAX_DIM', 4032);

if (!function_exists('qp_esc')) {

function qp_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Walk JPEG markers from SOI to SOS. Returns 'ok' (reached SOS, no Exif APP1),
// 'exif' (an APP1 segment carries Exif data) or 'bad' (anything unexpected before
// SOS: fails closed, a malformed file must never pass).
function qp_jpeg_scan(string $bytes): string
{
    $len = strlen($bytes);
    if ($len < 4 || substr($bytes, 0, 2) !== "\xff\xd8") {
        return 'bad';
    }
    $pos = 2;
    while ($pos < $len) {
        if ($bytes[$pos] !== "\xff") {
            return 'bad';
        }
        if ($pos + 1 >= $len) {
            return 'bad';
        }
        $marker = ord($bytes[$pos + 1]);
        if ($marker === 0xff) { // fill byte
            $pos++;
            continue;
        }
        if ($marker === 0xda) { // SOS: headers done
            return 'ok';
        }
        if ($marker === 0x00 || $marker === 0xd9 || $marker === 0xd8) { // stuffed zero, EOI or SOI before SOS
            return 'bad';
        }
        if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) { // standalone
            $pos += 2;
            continue;
        }
        if ($pos + 4 > $len) {
            return 'bad';
        }
        $seglen = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
        if ($seglen < 2 || $pos + 2 + $seglen > $len) {
            return 'bad';
        }
        if ($marker === 0xe1 && substr($bytes, $pos + 4, 6) === "Exif\x00\x00") {
            return 'exif';
        }
        $pos += 2 + $seglen;
    }
    return 'bad';
}

// True only for a well-formed JPEG header that carries an Exif APP1 segment.
function qp_jpeg_has_exif(string $bytes): bool
{
    return qp_jpeg_scan($bytes) === 'exif';
}

// True when PHP discarded the whole body because it exceeded post_max_size
// (both $_POST and $_FILES are empty then).
function qp_post_too_large(array $post, array $files, int $content_length, int $post_max_bytes): bool
{
    return count($post) === 0 && count($files) === 0 && $post_max_bytes > 0 && $content_length > $post_max_bytes;
}

// Returns null if OK, else an error code.
function qp_validate_upload(?array $file, int $content_length, int $post_max_bytes): ?string
{
    if ($file === null) {
        return ($post_max_bytes > 0 && $content_length > $post_max_bytes) ? 'too_large_post' : 'no_file';
    }
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'too_big';
    }
    if ($err !== UPLOAD_ERR_OK) {
        return 'upload_error';
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || (!defined('QP_TESTING') && !is_uploaded_file($tmp)) || !is_file($tmp)) {
        return 'upload_error';
    }
    $size = filesize($tmp);
    if ($size === false || $size > QP_MAX_BYTES) {
        return 'too_big';
    }
    $info = @getimagesize($tmp);
    if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
        return 'not_jpeg';
    }
    if ($info[0] <= 0 || $info[1] <= 0 || max($info[0], $info[1]) > QP_MAX_DIM) {
        return 'too_many_pixels';
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        return 'upload_error';
    }
    $scan = qp_jpeg_scan($bytes);
    if ($scan === 'exif') {
        return 'has_exif';
    }
    if ($scan !== 'ok') {
        return 'bad_jpeg';
    }
    return null;
}

function qp_error_text(string $code): string
{
    $resize = ' Please add the photo on the Photos tab in a browser with JavaScript enabled, which resizes it first.';
    switch ($code) {
        case 'too_large_post':
            return 'The upload is too large for the server limit.';
        case 'no_file':
            return 'No photo was received.';
        case 'upload_error':
            return 'The upload failed.';
        case 'too_big':
            return 'The photo is larger than 8 MB.';
        case 'not_jpeg':
            return 'The file is not a JPEG image.' . $resize;
        case 'too_many_pixels':
            return 'The photo is larger than ' . QP_MAX_DIM . ' pixels on its longer side.' . $resize;
        case 'has_exif':
            return 'The photo contains EXIF data.' . $resize;
        case 'bad_jpeg':
            return 'The JPEG file is damaged or malformed.' . $resize;
    }
    return 'Unknown error.';
}

function qp_name_label(string $name, string $realm, int $id): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($s === false) {
        $s = $name;
    }
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '') {
        return $realm . '-' . $id;
    }
    return substr($s, 0, 80);
}

function qp_photo_name(string $label, int $timestamp, int $attempt): string
{
    $base = $label . '_' . date('Y-m-d_His', $timestamp);
    if ($attempt > 1) {
        $base .= '_' . min($attempt, 20);
    }
    return $base . '.jpg';
}

// True when a user supplied file name is well-formed UTF-8.
function qp_user_name_valid_utf8(string $input): bool
{
    return preg_match('//u', $input) === 1;
}

// File name for a user supplied name, or null when nothing is left after
// sanitising (the caller then uses the automatic name). Input must be valid
// UTF-8 (check qp_user_name_valid_utf8 first). Attempt 2..20 adds _N.
function qp_user_photo_name(string $input, int $attempt): ?string
{
    if (!qp_user_name_valid_utf8($input)) {
        return null;
    }
    $s = preg_replace('/\s+/u', ' ', $input);
    $s = preg_replace('/[\p{Cc}\/\\\\:*?"<>|]/u', '-', $s);
    $s = trim($s);
    $s = trim(preg_replace('/\.jpe?g$/iu', '', $s));
    $s = trim(mb_substr($s, 0, 100, 'UTF-8'));
    if ($s === '') {
        return null;
    }
    if ($attempt > 1) {
        $s .= '_' . min($attempt, 20);
    }
    return $s . '.jpg';
}

// Prefilled value of the Name field on the Add sheet.
function qp_name_suggestion(string $label, int $timestamp): string
{
    return $label . '_' . date('Y-m-d', $timestamp);
}

// Tag colour (TagTree.color, mediumint unsigned or NULL) as "#rrggbb", or null.
function qp_tag_color($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_string($value)) {
        if (!ctype_digit($value) || strlen($value) > 10) {
            return null;
        }
        $value = (int) $value;
    }
    if (!is_int($value) || $value < 0 || $value > 0xFFFFFF) {
        return null;
    }
    return '#' . str_pad(dechex($value), 6, '0', STR_PAD_LEFT);
}

// Posted "taglist" value -> list of unique positive tag ids (first occurrence
// order), or null when it is not an array of plain positive integers.
// Missing (null) means no tags.
function qp_parse_tag_ids($raw): ?array
{
    if ($raw === null) {
        return [];
    }
    if (!is_array($raw)) {
        return null;
    }
    $ids = [];
    foreach ($raw as $v) {
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 10 || (int) $v < 1) {
            return null;
        }
        $ids[(int) $v] = true;
    }
    return array_keys($ids);
}

function qp_link_fingerprint(array $links): string
{
    $parts = [];
    foreach ($links as $l) {
        $parts[] = $l['entity_type'] . ':' . $l['entity_id'];
    }
    sort($parts, SORT_STRING);
    return hash('sha256', implode(',', $parts));
}

}
