<?php
/**
 * Plugin Name: Generar Collage Speakers
 * Description: Endpoint REST para generar un collage de 6 speakers (usa Imagick y sube a Medios).
 * Version: 1.0.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage',
        'permission_callback' => '__return_true',
    ]);
});

function gi_generate_collage(WP_REST_Request $request) {
    if (!class_exists('Imagick')) return new WP_REST_Response(['error'=>'Imagick no disponible'], 500);

    $token = $request->get_param('token');
    if ($token !== 'SECRETO') return new WP_REST_Response(['error'=>'Unauthorized'], 401);

    $payload = $request->get_json_params();
    if (!$payload || empty($payload['speakers'])) return new WP_REST_Response(['error'=>'No se proporcionaron speakers'], 400);

    // Canvas
    $W = intval($payload['canvas']['width'] ?? 1600);
    $H = intval($payload['canvas']['height'] ?? 900);
    $bg = $payload['canvas']['background'] ?? '#ffffff';

    $img = new Imagick();
    $img->newImage($W, $H, new ImagickPixel($bg));
    $img->setImageFormat('png');

    // Función para descargar imagen
    $download_image = function(string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        curl_close($ch);

        if (!$data) return null;
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);

        try { $m = new Imagick($tmp); } catch (\Throwable $e) { @unlink($tmp); return null; }
        @unlink($tmp);
        return $m;
    };

    // Grid 3x2
    $speakers = $payload['speakers'];
    $cols = 3;
    $rows = 2;
    $n = count($speakers);

    $padding = intval($payload['autoLayout']['padding'] ?? 40);
    $gutter  = intval($payload['autoLayout']['gutter'] ?? 20);

    $gridW = $W - 2*$padding;
    $gridH = $H - 2*$padding;
    $cellW = intval(($gridW - ($cols-1)*$gutter)/$cols);
    $cellH = intval(($gridH - ($rows-1)*$gutter)/$rows);

    for ($i=0; $i<$n; $i++) {
        $c = $i % $cols;
        $r = intdiv($i, $cols);
        $x = $padding + $c*($cellW + $gutter);
        $y = $padding + $r*($cellH + $gutter);

        $url = $speakers[$i]['photo'] ?? null;
        if (!$url) continue;

        $photo = $download_image($url);
        if (!$photo) continue;

        $photo->thumbnailImage($cellW, $cellH, true);

        // Fondo blanco
        $bg_cell = new Imagick();
        $bg_cell->newImage($cellW, $cellH, new ImagickPixel('#ffffff'));
        $bg_cell->setImageFormat('png');

        // Centrar la foto
        $offX = intval(($cellW - $photo->getImageWidth()) / 2);
        $offY = intval(($cellH - $photo->getImageHeight()) / 2);
        $bg_cell->compositeImage($photo, Imagick::COMPOSITE_OVER, $offX, $offY);

        $img->compositeImage($bg_cell, Imagick::COMPOSITE_OVER, $x, $y);

        $photo->destroy();
        $bg_cell->destroy();
    }

    // Título opcional
    if (!empty($payload['event_title'])) {
        $draw = new ImagickDraw();
        $draw->setFillColor('#111111');
        $draw->setFontSize(36);
        $img->annotateImage($draw, $padding, $H - 30, 0, $payload['event_title']);
    }

    // Guardar y subir
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $quality = intval($payload['output']['quality'] ?? 90);
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'collage_'.time()).'.'.$format);

    if ($format === 'jpg' || $format === 'jpeg') {
        $bg_layer = new Imagick();
        $bg_layer->newImage($W, $H, new ImagickPixel('#ffffff'));
        $bg_layer->setImageFormat('jpeg');
        $bg_layer->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);
        $img->destroy();
        $img = $bg_layer;
        $img->setImageCompressionQuality($quality);
    } elseif ($format === 'webp') {
        $img->setImageFormat('webp');
        $img->setImageCompressionQuality($quality);
    } else {
        $img->setImageFormat('png');
    }

    $blob = $img->getImagesBlob();
    $img->destroy();

    $upload = wp_upload_bits($filename, null, $blob);
    if (!empty($upload['error'])) return new WP_REST_Response(['error'=>'Fallo subiendo a Medios'], 500);

    $filetype = wp_check_filetype($upload['file']);
    $attach_id = wp_insert_attachment([
        'post_mime_type'=>$filetype['type'],
        'post_title'=>preg_replace('/\.[^.]+$/','',$filename),
        'post_status'=>'inherit'
    ], $upload['file']);
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}
