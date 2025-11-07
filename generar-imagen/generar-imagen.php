<?php
/**
 * Plugin Name: Generar Collage Speakers con Logs
 * Description: Endpoint REST para generar un collage de 6 speakers con logs (usa Imagick y sube a Medios).
 * Version: 1.1.0
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Generar Collage Speakers con Logs');
add_action('rest_api_init', function () {
  error_log('📡 Hook rest_api_init ejecutado — registrando /imagen/v1/generar');
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_logs',
        'permission_callback' => '__return_true',
    ]);
});

function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('✅ Plugin Generar Collage cargado');

    if (!class_exists('Imagick')) {
        error_log('❌ Imagick no disponible');
        return new WP_REST_Response(['error'=>'Imagick no disponible'], 500);
    }

    $token = $request->get_param('token');
    if ($token !== 'SECRETO') {
        error_log('❌ Token incorrecto: ' . $token);
        return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!$payload) {
        error_log('❌ No se recibió payload');
        return new WP_REST_Response(['error'=>'No se recibió payload'], 400);
    }

    $W  = intval($payload['canvas']['width'] ?? 1600);
    $H  = intval($payload['canvas']['height'] ?? 2200);
    $bg = $payload['canvas']['background'] ?? '#ffffff';

    // Crear lienzo base
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        error_log("🖼️ Fondo detectado como imagen: $bg");
        $bg_image = new Imagick();
        $bg_image->readImage($bg);
        $bg_image->resizeImage($W, $H, Imagick::FILTER_LANCZOS, 1);
        $img = $bg_image;
    } else {
        $img->newImage($W, $H, new ImagickPixel($bg));
    }
    $img->setImageFormat('png');

    $download_image = function(string $url) {
        error_log("⬇️ Descargando imagen: $url");
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

    // ====== SECCIÓN 1: Título ======
    if (!empty($payload['event_title'])) {
        $draw = new ImagickDraw();
        $draw->setFillColor('#000000');
        $draw->setFontSize(80);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $img->annotateImage($draw, $W/2, 150, 0, $payload['event_title']);
        error_log("📝 Título agregado: ".$payload['event_title']);
    }

    // ====== SECCIÓN 2: SPEAKERS ======
    $speakers = $payload['speakers'] ?? [];
    $n = count($speakers);
    $cols = ($n > 6) ? 4 : 3;
    $rows = ceil($n / $cols);

    $areaH = intval($H * 0.55);
    $startY = intval($H * 0.2);

    $gridW = $W - 2*$padding;
    $gridH = $areaH - 2*$padding;
    $cellW = intval(($gridW - ($cols-1)*$gutter)/$cols);
    $cellH = intval(($gridH - ($rows-1)*$gutter)/$rows);

    for ($i=0; $i<$n; $i++) {
        $c = $i % $cols;
        $r = intdiv($i, $cols);
        $x = $padding + $c*($cellW + $gutter);
        $y = $startY + $r*($cellH + $gutter);

        $photo = $download_image($speakers[$i]['photo']);
        if (!$photo) continue;
        $photo->thumbnailImage($cellW, $cellH, true);

        // Fondo blanco para la celda
        $cell = new Imagick();
        $cell->newImage($cellW, $cellH, new ImagickPixel('#ffffff'));
        $offX = intval(($cellW - $photo->getImageWidth())/2);
        $offY = intval(($cellH - $photo->getImageHeight())/2);
        $cell->compositeImage($photo, Imagick::COMPOSITE_OVER, $offX, $offY);
        $img->compositeImage($cell, Imagick::COMPOSITE_OVER, $x, $y);
    }

    // ====== SECCIÓN 3: LOGOS ======
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $logoAreaH = intval($H * 0.15);
        $yStart = $H - $logoAreaH - 200;
        $maxW = 250;
        $x = $padding;
        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            if (!$m) continue;
            $m->thumbnailImage($maxW, 0);
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, $x, $yStart);
            $x += $maxW + 40;
        }
        error_log("🏷️ Logos agregados");
    }

    // ====== SECCIÓN 4: SPONSORS ======
    $sponsors = $payload['sponsors'] ?? [];
    if (!empty($sponsors)) {
        $yStart = $H - 180;
        $x = $padding;
        $maxW = 200;
        foreach ($sponsors as $sp) {
            $m = $download_image($sp['photo']);
            if (!$m) continue;
            $m->thumbnailImage($maxW, 0);
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, $x, $yStart);
            $x += $maxW + 30;
        }
        error_log("🤝 Sponsors agregados");
    }

    // ====== EXPORTAR ======
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $quality = intval($payload['output']['quality'] ?? 90);
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'collage_'.time()).'.'.$format);

    if ($format === 'jpg') {
        $bg_layer = new Imagick();
        $bg_layer->newImage($W, $H, new ImagickPixel('#ffffff'));
        $bg_layer->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);
        $img = $bg_layer;
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality($quality);
    } else {
        $img->setImageFormat('png');
    }

    $blob = $img->getImagesBlob();
    $img->destroy();

    $upload = wp_upload_bits($filename, null, $blob);
    if (!empty($upload['error'])) {
        error_log("❌ Error subiendo a Medios: ".$upload['error']);
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

    error_log("✅ Imagen generada y subida: $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}
