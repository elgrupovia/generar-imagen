<?php
/**
 * Plugin Name: Generar Collage Speakers con Logs
 * Description: Genera un collage tipo cartel de evento con speakers, logos, sponsors, banner y logotipo superior.
 * Version: 1.6.0
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

/**
 * Crea miniaturas seguras evitando errores de geometría inválida
 */
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

/**
 * Función principal del collage
 */
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

    // 🔽 Función para descargar imágenes con compatibilidad robusta
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

    // 🏁 Banner centrado (55% del ancho, margen superior)
    if (!empty($payload['banner'])) {
        $bannerUrl = $payload['banner']['photo'] ?? null;
        if ($bannerUrl) {
            $banner = $download_image($bannerUrl);
            $bannerW = intval($W * 0.55);
            $bannerH = intval($H * 0.20);
            $banner = safe_thumbnail($banner, $bannerW, $bannerH, $bannerUrl, 'banner centrado');
            if ($banner) {
                $x = intval(($W - $banner->getImageWidth()) / 2);
                $y = 60;
                $img->compositeImage($banner, Imagick::COMPOSITE_OVER, $x, $y);
                error_log("🏁 Banner centrado agregado ($bannerUrl)");
            }
        }
    }

    // ✨ Logo superior derecho (robusto y compatible con picsum)
    if (!empty($payload['header_logo'])) {
        $logoUrl = $payload['header_logo']['photo'] ?? null;
        if ($logoUrl) {
            try {
                $tmpFile = wp_tempnam();
                $ch = curl_init($logoUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $data = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($data && $status == 200) {
                    file_put_contents($tmpFile, $data);
                    $headerLogo = new Imagick($tmpFile);
                    @unlink($tmpFile);

                    if ($headerLogo && $headerLogo->getImageWidth() > 0 && $headerLogo->getImageHeight() > 0) {
                        $targetW = intval($W * 0.18);
                        $headerLogo = safe_thumbnail($headerLogo, $targetW, 0, $logoUrl, 'logo superior derecho');
                        if ($headerLogo) {
                            $x = $W - $headerLogo->getImageWidth() - 80;
                            $y = 60;
                            $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                            error_log("✨ Logo superior derecho agregado correctamente ($logoUrl)");
                        }
                    } else {
                        error_log("⚠️ Logo descargado pero vacío ($logoUrl)");
                    }
                } else {
                    error_log("⚠️ No se pudo descargar logo superior derecho: $logoUrl (status $status)");
                }
            } catch (Exception $e) {
                error_log("❌ Error al procesar logo superior derecho: ".$e->getMessage());
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

    // 👤 Speakers (2 filas de 4 con texto debajo y logs)
$speakers = $payload['speakers'] ?? [];
if (!empty($speakers)) {
    error_log("🎤 Procesando ".count($speakers)." speakers...");

    $cols = 4; // 4 por fila
    $rows = ceil(count($speakers) / $cols);
    $photoW = intval($W / 4.5);
    $photoH = intval($photoW);
    $startY = intval($H * 0.40);
    $index = 0;
    $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    // Si la fuente no existe, WordPress lo registra
    if (!file_exists($fontPath)) {
        error_log("⚠️ Fuente no encontrada: $fontPath");
        $fontPath = null; // evitamos crash
    }

    for ($r = 0; $r < $rows; $r++) {
        $y = $startY + $r * ($photoH + 160);
        $numInRow = min($cols, count($speakers) - $index);
        $rowW = $numInRow * $photoW + ($numInRow - 1) * 30;
        $x = ($W - $rowW) / 2;

        for ($c = 0; $c < $numInRow; $c++) {
            $sp = $speakers[$index++] ?? null;
            if (!$sp) continue;
            $photoUrl = $sp['photo'] ?? null;
            $name = trim($sp['name'] ?? '');
            $role = trim($sp['role'] ?? '');

            error_log("👤 Speaker #$index: $name ($photoUrl)");

            $photo = $download_image($photoUrl);
            $photo = safe_thumbnail($photo, $photoW, $photoH, $photoUrl, 'speaker');
            if (!$photo) {
                error_log("❌ No se pudo cargar la foto de $name");
                continue;
            }

            // 🖼️ Colocar foto
            $img->compositeImage($photo, Imagick::COMPOSITE_OVER, intval($x), intval($y));

            // ✍️ Añadir texto debajo
            try {
                $draw = new ImagickDraw();
                if ($fontPath) $draw->setFont($fontPath);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->setFillColor('#000000');
                $draw->setFontSize(30);
                $draw->setFontWeight(700);

                // Sombra blanca
                $shadow = clone $draw;
                $shadow->setFillColor('#FFFFFF');

                $textY = $y + $photoH + 40;
                $centerX = $x + ($photoW / 2);

                // Nombre
                if ($name) {
                    $img->annotateImage($shadow, $centerX + 2, $textY + 2, 0, $name);
                    $img->annotateImage($draw, $centerX, $textY, 0, $name);
                }

                // Cargo
                if ($role) {
                    $drawRole = new ImagickDraw();
                    if ($fontPath) $drawRole->setFont($fontPath);
                    $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                    $drawRole->setFillColor('#333333');
                    $drawRole->setFontSize(22);
                    $drawRole->setFontWeight(500);
                    $img->annotateImage($drawRole, $centerX, $textY + 40, 0, $role);
                }

                error_log("✅ Texto añadido para $name");

            } catch (Exception $e) {
                error_log("💥 Error añadiendo texto de $name: ".$e->getMessage());
            }

            $x += $photoW + 30;
        }
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

    // 📤 Exportar imagen final
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
