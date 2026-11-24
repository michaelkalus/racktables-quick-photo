<?php
ini_set('memory_limit', '1024M');
// Generates fixture files into tests/fixtures/out/ (git-ignored).
$out = __DIR__ . '/out';
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}

function mk_img(int $w, int $h)
{
    $im = imagecreatetruecolor($w, $h);
    $c = imagecolorallocate($im, 40, 120, 200);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $c);
    $d = imagecolorallocate($im, 250, 250, 250);
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 3), intdiv($h, 3), $d);
    return $im;
}

$im = mk_img(2000, 1500);
imagejpeg($im, "$out/small.jpg", 80);
$im = mk_img(4000, 3000);
imagejpeg($im, "$out/big.jpg", 80);
$im = mk_img(5000, 3750);
imagejpeg($im, "$out/over.jpg", 60);
$im = mk_img(6000, 4500);
imagejpeg($im, "$out/down.jpg", 60);
$im = mk_img(800, 600);
imagepng($im, "$out/pic.png");

// exif.jpg: small.jpg with an APP1 Exif segment inserted right after SOI
$jpg = file_get_contents("$out/small.jpg");
$tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
$payload = "Exif\x00\x00" . $tiff;
$seg = "\xff\xe1" . pack('n', strlen($payload) + 2) . $payload;
file_put_contents("$out/exif.jpg", substr($jpg, 0, 2) . $seg . substr($jpg, 2));

// portrait-exif6.jpg: 3000x2000 stored pixels + EXIF orientation 6 (rotate 90 cw) => displayed 2000x3000
$im = mk_img(3000, 2000);
$c = imagecolorallocate($im, 220, 40, 40);
imagefilledrectangle($im, 0, 0, 600, 600, $c); // marker in stored top-left
imagejpeg($im, "$out/landscape.jpg", 80);
$jpg = file_get_contents("$out/landscape.jpg");
unlink("$out/landscape.jpg");
$im = mk_img(6000, 4500);
$c = imagecolorallocate($im, 220, 40, 40);
imagefilledrectangle($im, 0, 0, 900, 900, $c);
imagejpeg($im, "$out/landscape.jpg", 60);
$big = file_get_contents("$out/landscape.jpg");
unlink("$out/landscape.jpg");
file_put_contents("$out/portrait-big-exif6.jpg", substr($big, 0, 2) . $seg . substr($big, 2));
file_put_contents("$out/portrait-exif6.jpg", substr($jpg, 0, 2) . $seg . substr($jpg, 2));

file_put_contents("$out/notimage.jpg", random_bytes(20000));
file_put_contents("$out/huge.bin", random_bytes(9 * 1024 * 1024));
echo "fixtures ok\n";
