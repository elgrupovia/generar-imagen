<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario - Instagram Optimizado
 * Description: Versión optimizada para Instagram con ponentes más pequeños y barra horizontal de sponsor.
 * Version: 3.1.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Plugin Carátula Instagram Optimizado');

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_instagram_pro',
        'permission_callback' => '__return_true',
    ]);
});

// ----------------------------------------------------------------------------------
// FUNCIONES AUXILIARES
// ----------------------------------------------------------------------------------

function gi_download_image($url) {
    if (!$url) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $data = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$data || $status != 200) return null;

    $tmp = wp_tempnam();
    file_put_contents($tmp, $data);

    try { $m = new Imagick($tmp); } catch (Exception $e) { @unlink($tmp); return null; }
    @unlink($tmp);
    return $m;
}

function safe_thumbnail($imagick, $w, $h, $url = '', $context = '') {
    if (!$imagick) return null;

    try {
        $iw = $imagick->getImageWidth();
        $ih = $imagick->getImageHeight();
        if ($iw <= 0 || $ih <= 0) return null;

        $scale = max($w / $iw, $h / $ih);
        $newW = (int)($iw * $scale);
        $newH = (int)($ih * $scale);
        $imagick->scaleImage($newW, $newH);

        $x = (int)(($newW - $w) / 2);

        if ($context === 'speaker') {
            $y = (int)(($newH - $h) * 0.15);
        } else {
            $y = (int)(($newH - $h) / 2);
        }

        $imagick->cropImage($w, $h, $x, max(0,$y));
        $imagick->setImagePage($w, $h, 0, 0);
        return $imagick;
    } catch(Exception $e) {
        return null;
    }
}

function gi_safe_contain_logo($imagick, $mw, $mh) {
    if (!$imagick) return null;
    try {
        $iw = $imagick->getImageWidth();
        $ih = $imagick->getImageHeight();
        if ($iw <= 0 || $ih <= 0) return null;

        $scale = min($mw / $iw, $mh / $ih, 1);
        $imagick->scaleImage((int)($iw*$scale), (int)($ih*$scale));
        return $imagick;
    } catch(Exception $e) {
        return null;
    }
}

function gi_round_corners($imagick, $r) {
    if (!$imagick) return $imagick;

    try {
        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();

        $mask = new Imagick();
        $mask->newImage($w, $h, 'transparent');
        $mask->setImageFormat('png');

        $draw = new ImagickDraw();
        $draw->setFillColor('white');
        $draw->roundRectangle(0, 0, $w, $h, $r, $r);
        $mask->drawImage($draw);

        $imagick->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        return $imagick;
    } catch (Exception $e) {
        return $imagick;
    }
}

// ----------------------------------------------------------------------------------
// GENERADOR PRINCIPAL
// ----------------------------------------------------------------------------------

function gi_generate_collage_instagram_pro(WP_REST_Request $req) {

    $token = $req->get_param('token');
    if ($token !== 'SECRETO') return new WP_REST_Response(['error'=>'Unauthorized'],401);

    $p = $req->get_json_params();
    if (!$p) return new WP_REST_Response(['error'=>'No payload'],400);

    // FORZAR INSTAGRAM 1080x1080
    $W = 1080;
    $H = 1080;

    $bg = $p['canvas']['background'] ?? '#FFFFFF';

    $img = new Imagick();
    $img->newImage($W, $H, new ImagickPixel($bg));
    $img->setImageFormat('png');

    // ----------------------------------------------------------------------------
    //  BANNER SUPERIOR (más pequeño para dejar aire)
    // ----------------------------------------------------------------------------
    $bannerUrl = $p['banner_image']['photo'] ?? null;
    $bannerH = (int)($H * 0.38); // más pequeño que antes

    if ($bannerUrl) {
        $banner = gi_download_image($bannerUrl);
        if ($banner) {
            $banner = safe_thumbnail($banner, $W, $bannerH, $bannerUrl, 'banner');
            $img->compositeImage($banner, Imagick::COMPOSITE_OVER, 0, 0);
        }
    }

    // ----------------------------------------------------------------------------
    //  TEXTOS
    // ----------------------------------------------------------------------------
    $titulo   = $p['texts']['titulo']   ?? '';
    $titulo2  = $p['texts']['titulo2']  ?? '';
    $sub      = $p['texts']['subtitulo']?? '';
    $fecha    = $p['texts']['fecha']    ?? '';

    $draw = new ImagickDraw();
    $draw->setFillColor('#ffffff');
    $draw->setFontSize(70);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);

    $img->annotateImage($draw, $W/2, $bannerH + 70, 0, $titulo);

    $draw2 = new ImagickDraw();
    $draw2->setFillColor('#4D8AC7');
    $draw2->setFontSize(85);
    $draw2->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($draw2, $W/2, $bannerH + 150, 0, $titulo2);

    $drawSub = new ImagickDraw();
    $drawSub->setFillColor('#4D8AC7');
    $drawSub->setFontSize(40);
    $drawSub->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawSub, $W/2, $bannerH + 210, 0, $sub);

    $drawFecha = new ImagickDraw();
    $drawFecha->setFillColor('#ffffff');
    $drawFecha->setFontSize(32);
    $drawFecha->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawFecha, $W/2, $bannerH + 260, 0, $fecha);

    // ----------------------------------------------------------------------------
    // CARDS DE PONENTES — MÁS PEQUEÑOS
    // ----------------------------------------------------------------------------
    $speakers = $p['speakers'] ?? [];
    if (count($speakers) === 0) return new WP_REST_Response(['error'=>'No speakers'],400);

    // Ajuste más compacto
    $cols = 3;
    $rows = 2;

    $cardsTop = (int)($bannerH + 310);

    $gap = 25;
    $cardW = (int)(($W - $gap*($cols+1)) / $cols);
    $cardH = 240; // más pequeño

    $i = 0;
    foreach ($speakers as $sp) {
        $r = intdiv($i,3);
        $c = $i % 3;
        if ($r >= 2) break;

        $x = $gap + $c * ($cardW + $gap);
        $y = $cardsTop + $r * ($cardH + $gap);

        // card
        $card = new Imagick();
        $card->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
        $card->setImageFormat('png');
        $card = gi_round_corners($card, 18);

        // foto cuadrada arriba
        $fotoH = 150;
        $fotoW = $cardW;

        if (!empty($sp['photo'])) {
            $pf = gi_download_image($sp['photo']);
            if ($pf) {
                $pf = safe_thumbnail($pf, $fotoW, $fotoH, $sp['photo'], 'speaker');
                $card->compositeImage($pf, Imagick::COMPOSITE_OVER, 0, 0);
            }
        }

        // nombre
        $dn = new ImagickDraw();
        $dn->setFillColor('#111');
        $dn->setFontSize(26);
        $dn->setTextAlignment(Imagick::ALIGN_CENTER);
        $card->annotateImage($dn, $cardW/2, $fotoH + 35, 0, $sp['name']);

        // logo empresa
        if (!empty($sp['logo'])) {
            $lg = gi_download_image($sp['logo']);
            if ($lg) {
                $lg = gi_safe_contain_logo($lg, (int)($cardW*0.55), 50);
                $lx = (int)(($cardW - $lg->getImageWidth())/2);
                $ly = $fotoH + 60;
                $card->compositeImage($lg, Imagick::COMPOSITE_OVER, $lx, $ly);
            }
        }

        $img->compositeImage($card, Imagick::COMPOSITE_OVER, $x, $y);

        $i++;
    }

    // ----------------------------------------------------------------------------
    // BARRA HORIZONTAL DE SPONSOR
    // ----------------------------------------------------------------------------
    $sponsorUrl = $p['sponsor_image'] ?? null;

    $barH = 90;
    $barY = $H - $barH - 20;

    $bar = new Imagick();
    $bar->newImage($W - 80, $barH, new ImagickPixel('#FFFFFF'));
    $bar->setImageFormat('png');
    $bar = gi_round_corners($bar, 18);

    // texto Sponsor:
    $ds = new ImagickDraw();
    $ds->setFillColor('#000000');
    $ds->setFontSize(32);
    $ds->setTextAlignment(Imagick::ALIGN_LEFT);
    $bar->annotateImage($ds, 20, 55, 0, 'Sponsor:');

    if ($sponsorUrl) {
        $sp = gi_download_image($sponsorUrl);
        if ($sp) {
            $sp = gi_safe_contain_logo($sp, 260, 60);
            $lx = 200;
            $ly = (int)(($barH - $sp->getImageHeight())/2);
            $bar->compositeImage($sp, Imagick::COMPOSITE_OVER, $lx, $ly);
        }
    }

    // pegar barra
    $img->compositeImage($bar, Imagick::COMPOSITE_OVER, 40, $barY);


    // Export
    $filename = 'caratula_instagram.png';
    $blob = $img->getImagesBlob();

    $upload = wp_upload_bits($filename, null, $blob);

    $filetype = wp_check_filetype($upload['file']);

    $id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'],
        'post_title' => 'caratula_instagram',
        'post_status' => 'inherit'
    ], $upload['file']);

    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($id, $upload['file']);

    return new WP_REST_Response([
        'url'=>wp_get_attachment_url($id),
        'attachment_id'=>$id
    ],200);
}
?>
