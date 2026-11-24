<?php
// Local check of the logo stamp (includes/img-stamp.php) in WordPress Playground. Writes three uploads, then the
// caller requests their copies, e.g. /?upx_img=upx-test/photo.jpg&w=960:
//   photo.jpg      opaque photo stand-in      -> logo in the bottom-left corner
//   cutout.png     object on transparency     -> logo in the middle, fainter
//   site-logo.png  a name the stamp skips     -> no logo
// Open /upx-seed/stamp-test.php once to create them; it prints what this server can stamp with.
require_once '/wordpress/wp-load.php';
$dir = wp_get_upload_dir()['basedir'] . '/upx-test';
wp_mkdir_p($dir);

$photo = imagecreatetruecolor(2400, 1600);
for ($y = 0; $y < 1600; $y += 4) {
	imagefilledrectangle($photo, 0, $y, 2399, $y + 3, imagecolorallocate($photo, 40 + ($y * 3) % 180, 150, 230 - ($y % 200)));
}
imagejpeg($photo, $dir . '/photo.jpg', 90);

$cut = imagecreatetruecolor(2000, 1200);
imagealphablending($cut, false);
imagesavealpha($cut, true);
imagefilledrectangle($cut, 0, 0, 1999, 1199, imagecolorallocatealpha($cut, 0, 0, 0, 127));
imagefilledellipse($cut, 1000, 600, 1100, 700, imagecolorallocatealpha($cut, 200, 40, 40, 0));
imagepng($cut, $dir . '/cutout.png');
imagepng($cut, $dir . '/site-logo.png');

header('Content-Type: text/plain');
list($ok, $text) = upx_wm_status();
echo ($ok ? 'OK  ' : 'NO  '), $text, "\n";
foreach (array('image/jpeg', 'image/png', 'image/webp') as $mime) {
	echo $mime, ': ', upx_wm_engine($mime) ?: 'none', "\n";
}
echo 'wanted photo.jpg@960: ', var_export(upx_wm_wanted('upx-test/photo.jpg', 960, 'image/webp'), true), "\n";
echo 'wanted photo.jpg@160: ', var_export(upx_wm_wanted('upx-test/photo.jpg', 160, 'image/webp'), true), "\n";
echo 'wanted site-logo.png@960: ', var_export(upx_wm_wanted('upx-test/site-logo.png', 960, 'image/webp'), true), "\n";
