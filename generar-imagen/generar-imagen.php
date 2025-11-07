<?php
/**
 * Plugin Name: Generar Collage Speakers con Logs
 * Description: Genera un collage tipo cartel de evento con speakers, logos, sponsors, banner y logotipo superior.
 * Version: 1.5.0
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

function safe_thumbnail($imagick, $w, $h, $url, $context) {
    try {
        if ($imagick && $imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($w > 0 && $h > 0) {
                $imagick->thumbnailImage($w, $h, true);
            } elseif ($w > 0) {
                $imagick->thumbnailImage($w, 0, true);
            } elseif ($h > 0) {
                $imagick->thumbnailImage(0, $h, true);
            }
            return $imagick;
        } else {
            error_log("⚠️ Imagen inválida o vacía en $context: $url");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Error en safe_thumbnail ($context): ".$e->getMessage()." [$url]");
        return null;
    }
}

function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('✅ Generando collage con banner centrado y logo superior derecho');

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
        $bg_image = safe_thumbnail($bg_image, $W, $H, $bg, 'fondo');
        if ($bg_image) {
            $img = $bg_image;
            error_log("🖼️ Fondo aplicado: $bg");
        } else {
            $img->newImage($W, $H, new ImagickPixel('#ffffff'));
        }
    } else {
        $img->newImage($W, $H, new ImagickPixel($bg));
    }
    $img->setImageFormat('png');

    // 🔽 Descargar imágenes
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
        if (!$data || $status != 200) {
            error_log("⚠️ No se pudo descargar imagen: $url (status $status)");
            return null;
        }
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        try {
            $m = new Imagick($tmp);
        } catch (Exception $e) {
            error_log("❌ Error leyendo imagen $url: ".$e->getMessage());
            @unlink($tmp);
            return null;
        }
        @unlink($tmp);
        return $m;
    };

    $padding = intval($payload['autoLayout']['padding'] ?? 80);
    $gutter  = intval($payload['autoLayout']['gutter'] ?? 30);

    // 🏁 Banner centrado (ocupa 55% del ancho, con margen superior)
    if (!empty($payload['banner'])) {
        $bannerUrl = $payload['banner']['photo'] ?? null;
        if ($bannerUrl) {
            $banner = $download_image($bannerUrl);
            $bannerW = intval($W * 0.55); // 55% del ancho total
            $bannerH = intval($H * 0.20); // 20% de alto
            $banner = safe_thumbnail($banner, $bannerW, $bannerH, $bannerUrl, 'banner centrado');
            if ($banner) {
                $x = intval(($W - $banner->getImageWidth()) / 2);
                $y = 60; // margen superior
                $img->compositeImage($banner, Imagick::COMPOSITE_OVER, $x, $y);
                error_log("🏁 Banner centrado agregado ($bannerUrl)");
            }
        }
    }

    // ✨ Logo superior derecho (pequeño y visible)
    if (!empty($payload['header_logo'])) {
        $logoUrl = $payload['header_logo']['photo'] ?? null;
        if ($logoUrl) {
            $headerLogo = $download_image($logoUrl);
            $headerLogo = safe_thumbnail($headerLogo, intval($W * 0.10), 0, $logoUrl, 'logo superior'); // 10% ancho
            if ($headerLogo) {
                $x = $W - $headerLogo->getImageWidth() - 70;
                $y = 80; // un poco más abajo del borde
                $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                error_log("✨ Logo superior derecho agregado ($logoUrl)");
            } else {
                error_log("⚠️ Logo no pudo cargarse ($logoUrl)");
            }
        }
    }

    // 📝 Título centrado debajo del banner
    if (!empty($payload['event_title'])) {
        $draw = new ImagickDraw();
        $draw->setFillColor('#FFFFFF');
        $draw->setFontSize(80);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $titleY = intval($H * 0.30);
        $img->annotateImage($draw, $W / 2, $titleY, 0, $payload['event_title']);
        error_log("📝 Título agregado debajo del banner: ".$payload['event_title']);
    }

    // 👤 Speakers (2 filas de 3)
    $speakers = $payload['speakers'] ?? [];
    $cols = 3;
    $rows = ceil(count($speakers) / 3);
    $photoW = 380;
    $photoH = 380;
    $startY = intval($H * 0.4);
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
            $photo = safe_thumbnail($photo, $photoW, $photoH, $sp['photo'], 'speaker');
            if (!$photo) continue;
            $cell = new Imagick();
            $cell->newImage($photoW, $photoH, new ImagickPixel('#ffffff'));
            $offX = intval(($photoW - $photo->getImageWidth()) / 2);
            $offY = intval(($photoH - $photo->getImageHeight()) / 2);
            $cell->compositeImage($photo, Imagick::COMPOSITE_OVER, $offX, $offY);
            $img->compositeImage($cell, Imagick::COMPOSITE_OVER, $x, $y);
            $x += $photoW + $gutter;
        }
    }

    // 💼 Logos
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $logoY = $H - 500;
        $maxW = min(220, intval(($W - (count($logos) - 1) * 30) / count($logos)));
        $totalW = count($logos) * $maxW + (count($logos) - 1) * 30;
        $x = ($W - $totalW) / 2;
        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            $m = safe_thumbnail($m, $maxW, 100, $logo['photo'], 'logo');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($logoY));
            $x += $maxW + 30;
        }
    }

    // 🤝 Sponsors
    $sponsors = $payload['sponsors'] ?? [];
    if (!empty($sponsors)) {
        $sponsorY = $H - 300;
        $maxW = min(300, intval(($W - (count($sponsors) - 1) * 50) / count($sponsors)));
        $totalW = count($sponsors) * $maxW + (count($sponsors) - 1) * 50;
        $x = ($W - $totalW) / 2;
        foreach ($sponsors as $sp) {
            $m = $download_image($sp['photo']);
            $m = safe_thumbnail($m, $maxW, 120, $sp['photo'], 'sponsor');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($sponsorY));
            $x += $maxW + 50;
        }
    }

    // 📤 Exportar
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
