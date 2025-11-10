<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario
 * Description: Plantilla profesional para eventos inmobiliarios corporativos con diseño moderno
 * Version: 2.0.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Evento Inmobiliario Pro');

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
            error_log("⚠️ Imagen inválida en $context: $url");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Error safe_thumbnail ($context): ".$e->getMessage());
        return null;
    }
}

function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('✅ Generando plantilla evento inmobiliario profesional');

    if (!class_exists('Imagick')) {
        return new WP_REST_Response(['error'=>'Imagick no disponible'], 500);
    }

    $token = $request->get_param('token');
    if ($token !== 'SECRETO') {
        return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!$payload) {
        return new WP_REST_Response(['error'=>'No payload'], 400);
    }

    $W = intval($payload['canvas']['width'] ?? 1600);
    $H = intval($payload['canvas']['height'] ?? 2400);
    $bg = $payload['canvas']['background'] ?? '#1a1a1a';

    // 🖼️ Crear lienzo base con fondo
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        $bg_image = new Imagick();
        $bg_image->readImage($bg);
        $bg_image = safe_thumbnail($bg_image, $W, $H, $bg, 'fondo');
        if ($bg_image) {
            $img = $bg_image;
            // Difuminar ligeramente el fondo
            $img->blurImage(2, 1);
            error_log("🖼️ Fondo aplicado y difuminado");
        } else {
            $img->newImage($W, $H, new ImagickPixel($bg));
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
            error_log("⚠️ No se descargó: $url (status $status)");
            return null;
        }
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        try {
            $m = new Imagick($tmp);
        } catch (Exception $e) {
            error_log("❌ Error leyendo $url");
            @unlink($tmp);
            return null;
        }
        @unlink($tmp);
        return $m;
    };

    // 📐 Zonas de diseño
    $headerStart = 0;
    $headerEnd = intval($H * 0.15);
    $eventInfoStart = $headerEnd;
    $eventInfoEnd = intval($H * 0.22);
    $speakersStart = $eventInfoEnd;
    $speakersEnd = intval($H * 0.73);
    $ponentsStart = intval($H * 0.73);
    $ponentsEnd = intval($H * 0.85);
    $sponsorsStart = intval($H * 0.85);

    // 🟢 Banner verde centrado con borde redondeado
    $bannerBoxW = intval($W * 0.65);
    $bannerBoxH = intval($headerEnd * 0.80);
    
    // Crear rectángulo redondeado
    $draw = new ImagickDraw();
    $draw->setFillColor('#2ecc71');
    $draw->setStrokeColor('none');
    $draw->setStrokeWidth(0);
    $radius = 40;
    $draw->roundRectangle(0, 0, $bannerBoxW, $bannerBoxH, $radius, $radius);
    
    // Crear imagen con esquinas redondeadas
    $headerBox = new Imagick();
    $headerBox->newImage($bannerBoxW, $bannerBoxH, new ImagickPixel('transparent'));
    $headerBox->drawImage($draw);
    $headerBox->setImageFormat('png');
    
    // Posicionar en el centro horizontalmente
    $bannerX = intval(($W - $bannerBoxW) / 2);
    $bannerY = intval(($headerEnd - $bannerBoxH) / 2) + 20;
    $img->compositeImage($headerBox, Imagick::COMPOSITE_OVER, $bannerX, $bannerY);
    error_log("🟢 Banner verde centrado agregado");

    // 📝 Título "FLEX LIVING" centrado en el banner
    $montserratPath = '/usr/share/fonts/truetype/google-fonts/Montserrat-Black.ttf';
    $fontPath = file_exists($montserratPath) ? $montserratPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    $draw = new ImagickDraw();
    if (file_exists($fontPath)) $draw->setFont($fontPath);
    $draw->setFillColor('#FFFFFF');
    $draw->setFontSize(76);
    $draw->setFontWeight(900);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($draw, $W / 2, intval($bannerY + $bannerBoxH / 2 - 50), 0, $payload['header_title'] ?? 'FLEX LIVING');

    // 📝 Subtítulo "BOOM! PROYECTOS"
    $draw = new ImagickDraw();
    if (file_exists($fontPath)) $draw->setFont($fontPath);
    $draw->setFillColor('#FFFFFF');
    $draw->setFontSize(36);
    $draw->setFontWeight(700);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($draw, $W / 2, intval($bannerY + $bannerBoxH / 2 + 10), 0, $payload['header_subtitle'] ?? 'BOOM! PROYECTOS INMOBILIARIOS');

    // 📝 Ciudad "Valencia"
    $draw = new ImagickDraw();
    if (file_exists($fontPath)) $draw->setFont($fontPath);
    $draw->setFillColor('#FFFFFF');
    $draw->setFontSize(28);
    $draw->setFontWeight(600);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($draw, $W / 2, intval($bannerY + $bannerBoxH / 2 + 50), 0, $payload['header_city'] ?? 'Valencia');

    // ✨ Logo superior derecho (fuera del banner verde)
    if (!empty($payload['header_logo'])) {
        $logoUrl = $payload['header_logo']['photo'] ?? null;
        if ($logoUrl) {
            $headerLogo = $download_image($logoUrl);
            if ($headerLogo) {
                $headerLogo = safe_thumbnail($headerLogo, intval($W * 0.14), 0, $logoUrl, 'logo header');
                if ($headerLogo) {
                    $x = $W - $headerLogo->getImageWidth() - 40;
                    $y = 30;
                    $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                    error_log("✨ Logo header agregado en esquina superior derecha");
                }
            }
        }
    }

    // 📅 Detalles del evento
    $draw = new ImagickDraw();
    if (file_exists($fontPath)) $draw->setFont($fontPath);
    $draw->setFillColor('#FFFFFF');
    $draw->setFontSize(32);
    $draw->setFontWeight(600);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
    $eventDetails = $payload['event_details'] ?? '6 noviembre 2025 9:00h - Silken Puerta Valencia';
    $img->annotateImage($draw, $W / 2, $eventInfoStart + 40, 0, $eventDetails);
    error_log("📅 Detalles: $eventDetails");

    // 👤 Speakers con recuadros redondeados
    $speakers = $payload['speakers'] ?? [];
    if (!empty($speakers)) {
        error_log("🎤 Procesando ".count($speakers)." speakers");

        $totalSpeakers = count($speakers);
        $cols = 3;
        $rows = ceil($totalSpeakers / $cols);
        $photoW = intval($W / 3.5);
        $photoH = intval($photoW);
        $gapX = 70;
        $gapY = 80;
        $textHeight = 110;

        $availableHeight = $speakersEnd - $speakersStart;
        $totalHeight = $rows * ($photoH + $textHeight) + ($rows - 1) * $gapY;
        $startY = $speakersStart + intval(($availableHeight - $totalHeight) / 2);

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

                // Borde redondeado
                $photo->borderImage(new ImagickPixel('white'), 6, 6);
                
                // Sombra suave
                $shadow = $photo->clone();
                $shadow->shadowImage(80, 3, 0, 0);
                $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($x) - 3, intval($y) + 3);
                
                // Foto
                $img->compositeImage($photo, Imagick::COMPOSITE_OVER, intval($x), intval($y));

                // Texto bajo foto
                try {
                    $draw = new ImagickDraw();
                    if (file_exists($fontPath)) $draw->setFont($fontPath);
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->setFillColor('#FFFFFF');
                    $draw->setFontSize(38);
                    $draw->setFontWeight(900);

                    $centerX = $x + ($photoW / 2);
                    $nameY = $y + $photoH + 45;

                    if ($name) {
                        $img->annotateImage($draw, $centerX, $nameY, 0, $name);
                    }

                    if ($role) {
                        $drawRole = new ImagickDraw();
                        if (file_exists($fontPath)) $drawRole->setFont($fontPath);
                        $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                        $drawRole->setFillColor('#cccccc');
                        $drawRole->setFontSize(26);
                        $drawRole->setFontWeight(600);
                        $img->annotateImage($drawRole, $centerX, $nameY + 42, 0, $role);
                    }
                } catch (Exception $e) {
                    error_log("💥 Error texto: ".$e->getMessage());
                }

                $x += $photoW + $gapX;
            }
        }
        error_log("🎤 Grid: $rows filas x $cols columnas");
    }

    // 🏷️ Logos de Ponentes
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#FFFFFF');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $img->annotateImage($draw, 50, $ponentsStart + 42, 0, 'Ponentes:');

        $logosHeight = $ponentsEnd - $ponentsStart - 15;
        $logoMaxH = intval($logosHeight * 0.80);
        $logoY = $ponentsStart + intval(($logosHeight - $logoMaxH) / 2);
        
        $maxW = min(180, intval(($W - 280 - (count($logos) - 1) * 20) / count($logos)));
        $x = 280;

        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            $m = safe_thumbnail($m, $maxW, $logoMaxH, $logo['photo'], 'logo');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($logoY));
            $x += $maxW + 20;
        }
        error_log("💼 ".count($logos)." logos ponentes");
    }

    // 🤝 Patrocinadores con fondo blanco
    $sponsors = $payload['sponsors'] ?? [];
    if (!empty($sponsors)) {
        // Fondo blanco para sponsors
        $sponsorBg = new Imagick();
        $sponsorBg->newImage($W, $H - $sponsorsStart, new ImagickPixel('#FFFFFF'));
        $img->compositeImage($sponsorBg, Imagick::COMPOSITE_OVER, 0, $sponsorsStart);

        // Título
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#000000');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $img->annotateImage($draw, $W / 2, $sponsorsStart + 42, 0, 'Patrocina:');

        // Logos sponsors
        $sponsorHeight = $H - $sponsorsStart - 60;
        $sponsorMaxH = intval($sponsorHeight * 0.75);
        $sponsorY = $sponsorsStart + 60 + intval(($sponsorHeight - $sponsorMaxH) / 2);
        
        $maxW = min(300, intval(($W - (count($sponsors) - 1) * 60) / count($sponsors)));
        $totalW = count($sponsors) * $maxW + (count($sponsors) - 1) * 60;
        $x = ($W - $totalW) / 2;

        foreach ($sponsors as $sp) {
            $m = $download_image($sp['photo']);
            $m = safe_thumbnail($m, $maxW, $sponsorMaxH, $sp['photo'], 'sponsor');
            if (!$m) continue;
            $img->compositeImage($m, Imagick::COMPOSITE_OVER, intval($x), intval($sponsorY));
            $x += $maxW + 60;
        }
        error_log("🤝 ".count($sponsors)." patrocinadores");
    }

    // 📤 Exportar
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'evento_inmobiliario').'.'.$format);

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
        return new WP_REST_Response(['error'=>'Fallo en upload'], 500);
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

    error_log("✅ Imagen generada: $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}