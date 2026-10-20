<?php
declare(strict_types=1);
function le_image_derivative(string $url): void {
    $file=engine_upload_path($url);if(!$file||!extension_loaded('gd')||!function_exists('imagewebp')||empty(gd_info()['WebP Support']))return;
    $info=getimagesize($file);if(!$info||$info[2]===IMAGETYPE_GIF)return;
    $img=match($info[2]){IMAGETYPE_JPEG=>@imagecreatefromjpeg($file),IMAGETYPE_PNG=>@imagecreatefrompng($file),IMAGETYPE_WEBP=>@imagecreatefromwebp($file),default=>false};if(!$img)throw new RuntimeException(le_text('Nie można odczytać obrazu. Plik jest uszkodzony lub ma nieobsługiwany format.'));
    $scale=min(1,640/max($info[0],$info[1]));$out=imagecreatetruecolor(max(1,(int)round($info[0]*$scale)),max(1,(int)round($info[1]*$scale)));imagealphablending($out,false);imagesavealpha($out,true);
    imagecopyresampled($out,$img,0,0,0,0,imagesx($out),imagesy($out),$info[0],$info[1]);$thumb=substr($file,0,strrpos($file,'.')).'.thumb.webp';
    if(!imagewebp($out,$thumb,82)){imagedestroy($out);imagedestroy($img);throw new RuntimeException('Nie można utworzyć miniatury.');}imagedestroy($out);imagedestroy($img);
}
function le_image_thumb_url(string $url): string {
    $file=engine_upload_path($url);if(!$file)return $url;$thumb=substr($file,0,strrpos($file,'.')).'.thumb.webp';return is_file($thumb)?substr($url,0,strrpos($url,'.')).'.thumb.webp':$url;
}
