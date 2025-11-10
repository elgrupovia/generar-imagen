<?php
/**
 * Plugin Name: Generar Collage Speakers con Logs
 * Description: Genera un collage tipo cartel de evento con speakers, logos, sponsors, banner y logotipo superior.
 * Version: 1.8.0
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
    error_log('✅ Generando collage con estructura de referencia');

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
    $H = intval($payload['canvas']['height'] ?? 2400);
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

    // 📐 Estructura de zonas según referencia
    $headerHeight = intval($H * 0.10);         // Logo superior: 0% - 10%
    $bannerStart = intval($H * 0.10);
    $bannerEnd = intval($H * 0.20);            // Banner: 10% - 20%
    $titleStart = intval($H * 0.20);
    $titleEnd = intval($H * 0.30);             // Título y info: 20% - 30%
    $speakersStart = intval($H * 0.30);        // Speakers: 30% - 75%
    $speakersEnd = intval($H * 0.75);
    $ponentsStart = intval($H * 0.75);         // Sección "Ponentes": 75% - 82%
    $ponentsEnd = intval($H * 0.82);
    $sponsorsStart = intval($H * 0.82);        // Patrocina: 82% - 100%

    error_log("📏 Estructura: Header[$headerHeight] Banner[".($bannerEnd-$bannerStart)."] Título[".($titleEnd-$titleStart)."] Speakers[".($speakersEnd-$speakersStart)."] Ponentes[".($ponentsEnd-$ponentsStart)."] Sponsors[".($H-$sponsorsStart)."]");

    // ✨ Logo superior derecho
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
                        $targetW = intval($W * 0.20);
                        $headerLogo = safe_thumbnail($headerLogo, $targetW, 0, $logoUrl, 'logo superior derecho');
                        if ($headerLogo) {
                            $x = $W - $headerLogo->getImageWidth() - 60;
                            $y = intval($headerHeight * 0.15);
                            $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                            error_log("✨ Logo superior derecho agregado ($logoUrl)");
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("❌ Error procesando logo superior: ".$e->getMessage());
            }
        }
    }

    // 🏁 Banner centrado grande
    if (!empty($payload['banner'])) {
        $bannerUrl = $payload['banner']['photo'] ?? null;
        if ($bannerUrl) {
            $banner = $download_image($bannerUrl);
            $bannerH = $bannerEnd - $bannerStart;
            $bannerW = intval($W * 0.65);
            $banner = safe_thumbnail($banner, $bannerW, $bannerH, $bannerUrl, 'banner');
            if ($banner) {
                $x = intval(($W - $banner->getImageWidth()) / 2);
                $y = $bannerStart;
                $img->compositeImage($banner, Imagick::COMPOSITE_OVER, $x, $y);
                error_log("🏁 Banner centrado agregado");
            }
        }
    }

    // 📝 Título y fecha/evento
    if (!empty($payload['event_title'])) {
        $montserratPath = '/usr/share/fonts/truetype/google-fonts/Montserrat-Black.ttf';
        $fontPath = file_exists($montserratPath) ? $montserratPath : null;

        $draw = new ImagickDraw();
        if ($fontPath) $draw->setFont($fontPath);
        $draw->setFillColor('#FFFFFF');
        $draw->setFontSize(85);
        $draw->setFontWeight(900);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $titleY = $titleStart + 60;
        $img->annotateImage($draw, $W / 2, $titleY, 0, $payload['event_title']);
        error_log("📝 Título: ".$payload['event_title']);
    }

    // 📅 Subtítulo con fecha y lugar
    if (!empty($payload['event_subtitle'])) {
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#FFFFFF');
        $draw->setFontSize(32);
        $draw->setFontWeight(600);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $subtitleY = $titleStart + 130;
        $img->annotateImage($draw, $W / 2, $subtitleY, 0, $payload['event_subtitle']);
        error_log("📅 Subtítulo: ".$payload['event_subtitle']);
    }

    // 👤 Speakers (estructura de grid flexible: 2-3-2 o 2-2-2-2)
    $speakers = $payload['speakers'] ?? [];
    if (!empty($speakers)) {
        error_log("🎤 Procesando ".count($speakers)." speakers");

        $totalSpeakers = count($speakers);
        $photoW = intval($W / 3.3);
        $photoH = intval($photoW);
        $gapX = 60;
        $gapY = 70;
        $textHeight = 100;

        $availableHeight = $speakersEnd - $speakersStart;
        
        // Determinar layout según cantidad de speakers
        if ($totalSpeakers <= 3) {
            $cols = $totalSpeakers;
            $rows = 1;
        } elseif ($totalSpeakers <= 5) {
            $cols = 3;
            $rows = 2;
        } else {
            $cols = 3;
            $rows = ceil($totalSpeakers / 3);
        }

        // Calcular altura total necesaria
        $totalHeight = $rows * ($photoH + $textHeight) + ($rows - 1) * $gapY;
        $startY = $speakersStart + intval(($availableHeight - $totalHeight) / 2);

        $montserratPath = '/usr/share/fonts/truetype/google-fonts/Montserrat-Black.ttf';
        $fontPath = file_exists($montserratPath) ? $montserratPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

        $index = 0;
        for ($r = 0; $r < $rows; $r++) {
            $y = $startY + $r * ($photoH + $textHeight + $gapY);
            $numInRow = min($cols, $totalSpeakers - $index);
            $rowW = $numInRow * $photoW + ($numInRow - 1) * $gapX;
            $x = ($W - $rowW) / 2;

            for ($c = 0; $c < $numInRow; $c++) {
                $sp = $speakers[$index++] ?? null;
                if (!$sp) continue;

                $photoUrl = $sp['photo'] ?? null;
                $name = trim($sp['name'] ?? '');
                $role = trim($sp['role'] ?? '');

                $photo = $download_image($photoUrl);
                $photo = safe_thumbnail($photo, $photoW, $photoH, $photoUrl, 'speaker');
                if (!$photo) continue;

                // Agregar borde blanco
                $photo->borderImage(new ImagickPixel('white'), 8, 8);
                
                $img->compositeImage($photo, Imagick::COMPOSITE_OVER, intval($x), intval($y));

                // Nombres y roles
                try {
                    $draw = new ImagickDraw();
                    if (file_exists($fontPath)) $draw->setFont($fontPath);
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->setFillColor('#000000');
                    $draw->setFontSize(38);
                    $draw->setFontWeight(900);

                    $centerX = $x + ($photoW / 2) + 8;
                    $nameY = $y + $photoH + 48;

                    if ($name) {
                        $img->annotateImage($draw, $centerX, $nameY, 0, $name);
                    }

                    if ($role) {
                        $drawRole = new ImagickDraw();
                        if (file_exists($fontPath)) $drawRole->setFont($fontPath);
                        $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                        $drawRole->setFillColor('#555555');
                        $drawRole->setFontSize(26);
                        $drawRole->setFontWeight(600);
                        $img->annotateImage($drawRole, $centerX, $nameY + 45, 0, $role);
                    }
                } catch (Exception $e) {
                    error_log("💥 Error en texto de $name: ".$e->getMessage());
                }

                $x += $photoW + $gapX;
            }
        }

        error_log("🎤 Speakers: $rows filas de $cols columnas");
    }

    // 🏷️ Sección "PONENTES:" con logos de empresas
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        // Título "Ponentes:"
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#000000');
        $draw->setFontSize(36);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $img->annotateImage($draw, 60, $ponentsStart + 35, 0, 'Ponentes:');

        // Logos de empresas
        $logosHeight = $ponentsEnd - $ponentsStart;
        $logoMaxH = intval($logosHeight * 0.65);
        $logoY = $ponentsStart + intval(($logosHeight - $logoMaxH) / 2);
        
        $maxW = min(200, intval(($W - 320 - (count($logos) - 1) * 25) / count($logos)));
        $totalW = count($logos) * $maxW + (count($logos) - 1) * 25;
        $x = 60 + 200;

        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            $m = safe_thumbnail($m, $maxW, $logoMaxH, $logo['photo'], 'logo');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($logoY));
            $x += $maxW + 25;
        }
        error_log("💼 ".count($logos)." logos de ponentes agregados");
    }

    // 🤝 Patrocina - Sponsors principales
    $sponsors = $payload['sponsors'] ?? [];
    if (!empty($sponsors)) {
        // Título "Patrocina:"
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#000000');
        $draw->setFontSize(36);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $img->annotateImage($draw, $W / 2, $sponsorsStart + 35, 0, 'Patrocina:');

        // Logos de sponsors
        $sponsorsHeight = $H - $sponsorsStart;
        $sponsorMaxH = intval($sponsorsHeight * 0.65);
        $sponsorY = $sponsorsStart + intval(($sponsorsHeight - $sponsorMaxH) / 2);
        
        $maxW = min(320, intval(($W - (count($sponsors) - 1) * 60) / count($sponsors)));
        $totalW = count($sponsors) * $maxW + (count($sponsors) - 1) * 60;
        $x = ($W - $totalW) / 2;

        foreach ($sponsors as $sp) {
            $m = $download_image($sp['photo']);
            $m = safe_thumbnail($m, $maxW, $sponsorMaxH, $sp['photo'], 'sponsor');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($sponsorY));
            $x += $maxW + 60;
        }
        error_log("🤝 ".count($sponsors)." sponsors agregados");
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