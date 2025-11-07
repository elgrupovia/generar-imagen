<?php
/**
 * Plugin Name: Generar Collage Speakers con Logs
 * Description: Genera un collage tipo cartel de evento con speakers, logos, sponsors, banner y logotipo superior.
 * Version: 1.2.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Generar Collage Speakers con Logs');
add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_logs',
        'permission_callback' => '__return_true',
    ]);
});

function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('✅ Generando collage con banner superior y logo');

    if (!class_exists('Imagick')) {
        return new WP_REST_Response(['error'=>'Imagick no disponible'], 500);
    }

    $token = $request->get_param('token');
    if ($token !== 'SECRETO') {
        return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!$payload) {
        return new WP_REST_Response(['error'=>'No se recibió payload'], 400);
    }

    $W = intval($payload['canvas']['width'] ?? 1600);
    $H = intval($payload['canvas']['height'] ?? 2200);
    $bg = $payload['canvas']['background'] ?? '#ffffff';

    // 🖼️ Crear lienzo base
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        $bg_image = new Imagick();
        $bg_image->readImage($bg);
        $bg_image->resizeImage($W, $H, Imagick::FILTER_LANCZOS, 1);
        $img = $bg_image;
        error_log("🖼️ Fondo de imagen aplicado: $bg");
    } else {
        $img->newImage($W, $H, new ImagickPixel($bg));
    }
    $img->setImageFormat('png');

    // 🔽 Función para descargar imágenes
    $download_image = function(string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$data || $status != 200) return null;
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        $m = new Imagick($tmp);
        @unlink($tmp);
        return $m;
    };

    $padding = intval($payload['autoLayout']['padding'] ?? 80);
    $gutter  = intval($payload['autoLayout']['gutter'] ?? 30);

    // 🏁 Banner superior (imagen completa)
    if (!empty($payload['banner'])) {
        $bannerUrl = $payload['banner']['photo'] ?? null;
        if ($bannerUrl) {
            $banner = $download_image($bannerUrl);
            if ($banner) {
                $bannerHeight = intval($H * 0.18);
                $banner->resizeImage($W, $bannerHeight, Imagick::FILTER_LANCZOS, 1);
                $img->compositeImage($banner, Imagick::COMPOSITE_OVER, 0, 0);
                error_log("🏁 Banner superior agregado ($bannerUrl)");
            }
        }
    }

    // 🖋️ Logo superior derecho
    if (!empty($payload['header_logo'])) {
        $logoUrl = $payload['header_logo']['photo'] ?? null;
        if ($logoUrl) {
            $headerLogo = $download_image($logoUrl);
            if ($headerLogo) {
                $logoW = intval($W * 0.12);
                $headerLogo->thumbnailImage($logoW, 0, true);
                $x = $W - $logoW - 50;
                $y = 40;
                $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                error_log("✨ Logo superior derecho agregado ($logoUrl)");
            }
        }
    }

    // 🏷️ Título encima del banner
    if (!empty($payload['event_title'])) {
        $draw = new ImagickDraw();
        $draw->setFillColor('#FFFFFF');
        $draw->setFontSize(90);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $img->annotateImage($draw, $W / 2, 150, 0, $payload['event_title']);
        error_log("📝 Título agregado sobre el banner: ".$payload['event_title']);
    }

    // 👤 Speakers — dos filas de 3 centradas
    $speakers = $payload['speakers'] ?? [];
    $cols = 3;
    $rows = ceil(count($speakers) / 3);
    $photoW = 380;
    $photoH = 380;
    $startY = 500;
    $index = 0;

    for ($r = 0; $r < $rows; $r++) {
        $y = $startY + $r * ($photoH + $gutter);
        $numInRow = min($cols, count($speakers) - $index);
        $rowW = $numInRow * $photoW + ($numInRow - 1) * $gutter;
        $x = ($W - $rowW) / 2;
        for ($c = 0; $c < $numInRow; $c++) {
            $sp = $speakers[$index++] ?? null;
            if (!$sp) continue;
            $photo = $download_image($sp['photo']);
            if (!$photo) continue;
            $photo->thumbnailImage($photoW, $photoH, true);
            $cell = new Imagick();
            $cell->newImage($photoW, $photoH, new ImagickPixel('#ffffff'));
            $offX = intval(($photoW - $photo->getImageWidth()) / 2);
            $offY = intval(($photoH - $photo->getImageHeight()) / 2);
            $cell->compositeImage($photo, Imagick::COMPOSITE_OVER, $offX, $offY);
            $img->compositeImage($cell, Imagick::COMPOSITE_OVER, $x, $y);
            $x += $photoW + $gutter;
        }
    }

    // 💼 Logos — fila centrada
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $logoY = $H - 500;
        $maxW = min(220, intval(($W - (count($logos) - 1) * 30) / count($logos)));
        $totalW = count($logos) * $maxW + (count($logos) - 1) * 30;
        $x = ($W - $totalW) / 2;
        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            if (!$m) continue;
            $m->thumbnailImage($maxW, 100, true);
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($logoY));
            $x += $maxW + 30;
        }
    }

    // 🤝 Sponsors — debajo de logos
    $sponsors = $payload['sponsors'] ?? [];
    if (!empty($sponsors)) {
        $sponsorY = $H - 300;
        $maxW = min(300, intval(($W - (count($sponsors) - 1) * 50) / count($sponsors)));
        $totalW = count($sponsors) * $maxW + (count($sponsors) - 1) * 50;
        $x = ($W - $totalW) / 2;
        foreach ($sponsors as $sp) {
            $m = $download_image($sp['photo']);
            if (!$m) continue;
            $m->thumbnailImage($maxW, 120, true);
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($sponsorY));
            $x += $maxW + 50;
        }
    }

    // 📤 Exportar y subir a Medios
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'collage_evento').'.'.$format);
    if ($format === 'jpg') {
        $bg_layer = new Imagick();
        $bg_layer->newImage($W, $H, new ImagickPixel('#ffffff'));
        $bg_layer->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);
        $img = $bg_layer;
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(90);
    }

    $blob = $img->getImagesBlob();
    $img->destroy();

    $upload = wp_upload_bits($filename, null, $blob);
    if (!empty($upload['error'])) {
        return new WP_REST_Response(['error'=>'Fallo subiendo a Medios'], 500);
    }

    $filetype = wp_check_filetype($upload['file']);
    $attach_id = wp_insert_attachment([
        'post_mime_type'=>$filetype['type'],
        'post_title'=>preg_replace('/\.[^.]+$/','',$filename),
        'post_status'=>'inherit'
    ], $upload['file']);
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    error_log("✅ Imagen final generada: $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}
