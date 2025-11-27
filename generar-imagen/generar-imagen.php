<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario
 * Description: Plantilla profesional para eventos inmobiliarios corporativos con diseño moderno
 * Version: 2.0.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Caratula evento');

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_logs',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Función de redimensionado seguro (Cover logic) - Para SPEAKERS y BANNERS.
 * Asegura que la imagen CUBRA la dimensión objetivo (puede cortar los bordes).
 * MODIFICADO: Prioriza el recorte vertical desde la parte superior (y_offset ajustado).
 */
function safe_thumbnail($imagick, $w, $h, $url, $context) {
    if (!$imagick) return null;

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($w > 0 && $h > 0) {
                $scaleRatio = max($w / $imagick->getImageWidth(), $h / $imagick->getImageHeight());
                $newW = (int)($imagick->getImageWidth() * $scaleRatio);
                $newH = (int)($imagick->getImageHeight() * $scaleRatio);

                $imagick->scaleImage($newW, $newH);

                $x_offset = (int)(($newW - $w) / 2);
                
                // CORRECCIÓN CLAVE: Ajustamos el offset vertical.
                // Si la imagen es para un 'speaker', intentamos recortar desde la parte superior (cabeza).
                if ($context === 'speaker') {
                    // Mantenemos el recorte cerca de la parte superior (0-20% de desplazamiento)
                    $y_offset = (int)(($newH - $h) * 0.20); 
                } else {
                    // Para otros usos (ej. banner), centramos.
                    $y_offset = (int)(($newH - $h) / 2); 
                }
                
                $imagick->cropImage($w, $h, $x_offset, $y_offset);
                $imagick->setImagePage($w, $h, 0, 0);
            } elseif ($w > 0) {
                $imagick->thumbnailImage($w, 0, true);
            } elseif ($h > 0) {
                $imagick->thumbnailImage(0, $h, true);
            }
            return $imagick;
        } else {
            error_log("⚠️ Imagen inválida en $context: $url - Geometría 0x0.");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Error safe_thumbnail ($context): ".$e->getMessage());
        return null;
    }
}

/**
 * Nueva función de redimensionado para LOGOS (Contain/Ajustar) - Mantiene 16:9 y no CORTA.
 * La imagen se ajusta para que quepa completamente dentro de las dimensiones.
 */
function gi_safe_contain_logo($imagick, $targetW, $targetH, $url, $context) {
    if (!$imagick) return null;

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($targetW > 0 && $targetH > 0) {
                // Usar scaleImage con el factor de escala MIN para asegurar que quepa (contain)
                $scaleRatio = min($targetW / $imagick->getImageWidth(), $targetH / $imagick->getImageHeight());
                $newW = (int)($imagick->getImageWidth() * $scaleRatio);
                $newH = (int)($imagick->getImageHeight() * $scaleRatio);

                $imagick->scaleImage($newW, $newH);

                // La imagen redimensionada ahora se ajusta a las dimensiones $newW x $newH
                return $imagick;
            }
            return $imagick;
        } else {
            error_log("⚠️ Imagen inválida en $context: $url - Geometría 0x0.");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Error gi_safe_contain_logo ($context): ".$e->getMessage());
        return null;
    }
}


/**
 * Aplica esquinas redondeadas a una imagen Imagick.
 */
function gi_round_corners($imagick, $radius) {
    if (!$imagick) return $imagick;

    try {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        $mask = new Imagick();
        $mask->newImage($width, $height, new ImagickPixel('transparent'));
        $mask->setImageFormat('png');

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->roundRectangle(0, 0, $width - 1, $height - 1, $radius, $radius);
        $mask->drawImage($draw);
        
        $imagick->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0); 
        $mask->destroy();
        
        return $imagick;
    } catch (Exception $e) {
        error_log("❌ Error al redondear esquinas: ".$e->getMessage());
        return $imagick;
    }
}

/**
 * Envuelve el texto a una anchura máxima utilizando las métricas de Imagick para evitar el recorte.
 * @param ImagickDraw $draw Objeto ImagickDraw con la fuente y el tamaño establecidos.
 * @param Imagick $imagick Objeto Imagick para obtener métricas.
 * @param string $text Texto a envolver.
 * @param int $maxWidth Ancho máximo permitido en píxeles.
 * @return array Líneas de texto envueltas.
 */
function gi_word_wrap_text($draw, $imagick, $text, $maxWidth) {
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';

    foreach ($words as $word) {
        // Intentar agregar la palabra a la línea actual
        $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
        
        // Obtener las métricas para verificar el ancho
        $metrics = $imagick->queryFontMetrics($draw, $testLine);

        if ($metrics['textWidth'] <= $maxWidth) {
            $currentLine = $testLine;
        } else {
            // La nueva palabra no cabe, guardar la línea actual y empezar una nueva
            if ($currentLine) {
                $lines[] = $currentLine;
            }
            // Si la palabra sola ya excede el ancho, la mantenemos como línea única para evitar un bucle infinito
            $currentLine = $word;
        }
    }
    // Añadir la última línea si existe
    if ($currentLine) {
        $lines[] = $currentLine;
    }
    return $lines;
}

function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('🚀 Generando carátula estilo Gran Debate Hotelero (cards sobre banner)');

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

    // =========================
    // Canvas
    // =========================
    $W = intval($payload['canvas']['width'] ?? 1024);
    $H = intval($payload['canvas']['height'] ?? 1024);
    $bg = $payload['canvas']['background'] ?? '#FFFFFF';

    // Fuente
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $montserratBlackPath = $base_dir . '/fonts/Montserrat-Black.ttf';
    $montserratSemiPath  = $base_dir . '/fonts/Montserrat-SemiBold.ttf';
    $montserratRegPath   = $base_dir . '/fonts/Montserrat-Regular.ttf';

    $fontBold = file_exists($montserratBlackPath) ? $montserratBlackPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontSemi = file_exists($montserratSemiPath)  ? $montserratSemiPath  : $fontBold;
    $fontReg  = file_exists($montserratRegPath)   ? $montserratRegPath   : $fontBold;

    // =========================
    // Crear lienzo base
    // =========================
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        try {
            $bg_image = new Imagick();
            $bg_image->readImage($bg);

            $scaleRatio = max($W / $bg_image->getImageWidth(), $H / $bg_image->getImageHeight());
            $newW = (int)($bg_image->getImageWidth() * $scaleRatio);
            $newH = (int)($bg_image->getImageHeight() * $scaleRatio);

            $bg_image->scaleImage($newW, $newH);

            $x_offset = (int)(($newW - $W) / 2);
            $y_offset = (int)(($newH - $H) / 2);

            $bg_image->cropImage($W, $H, $x_offset, $y_offset);
            $img = $bg_image;

        } catch (Exception $e) {
            $img->newImage($W, $H, new ImagickPixel('#FFFFFF'));
        }
    } else {
        $img->newImage($W, $H, new ImagickPixel($bg));
    }
    $img->setImageFormat('png');

    // Helper descarga (tu versión)
    $download_image = function(string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$data || $status != 200) return null;

        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        try {
            $m = new Imagick($tmp);
        } catch (Exception $e) {
            $m = null;
        }
        @unlink($tmp);
        return $m;
    };

    // =========================
    // Banner superior (50%)
    // =========================
    $bannerH = (int)($H * 0.50);
    $bannerUrl = $payload['banner_image']['photo'] ?? null;

    if ($bannerUrl) {
        $banner = $download_image($bannerUrl);
        if ($banner) {
            $banner = safe_thumbnail($banner, $W, $bannerH, $bannerUrl, 'banner');
            $img->compositeImage($banner, Imagick::COMPOSITE_OVER, 0, 0);
            $banner->destroy();
        }
    }

    // =========================
    // Textos superiores
    // =========================
    $titulo   = $payload['texts']['titulo']   ?? 'Gran Debate';
    $titulo2  = $payload['texts']['titulo2']  ?? 'HOTELERO';
    $subtitulo= $payload['texts']['subtitulo']?? 'Gran Canaria';
    $fechaTxt = $payload['texts']['fecha']    ?? '20 Junio 2024 · Lopesan Costa Meloneras';

    // Caja título blanco
    $titleBoxW = (int)($W * 0.78);
    $titleBoxH = 150;
    $titleBoxX = (int)(($W - $titleBoxW)/2);
    $titleBoxY = 30;

    $titleBox = new Imagick();
    $titleBox->newImage($titleBoxW, $titleBoxH, new ImagickPixel('#FFFFFF'));
    $titleBox->setImageFormat('png');
    $titleBox = gi_round_corners($titleBox, 30);
    $img->compositeImage($titleBox, Imagick::COMPOSITE_OVER, $titleBoxX, $titleBoxY);
    $titleBox->destroy();

    // Texto título
    $drawT = new ImagickDraw();
    $drawT->setFont($fontSemi);
    $drawT->setFillColor('#2F6EA9');
    $drawT->setFontSize(56);
    $drawT->setTextAlignment(Imagick::ALIGN_LEFT);
    $img->annotateImage($drawT, $titleBoxX + 70, $titleBoxY + 70, 0, $titulo);

    $drawT2 = new ImagickDraw();
    $drawT2->setFont($fontBold);
    $drawT2->setFillColor('#4D8AC7');
    $drawT2->setFontSize(78);
    $drawT2->setTextAlignment(Imagick::ALIGN_LEFT);
    $img->annotateImage($drawT2, $titleBoxX + 70, $titleBoxY + 135, 0, $titulo2);

    // Pastilla subtítulo blanco
    $subBoxW = (int)($W * 0.45);
    $subBoxH = 72;
    $subBoxX = (int)(($W - $subBoxW)/2);
    $subBoxY = $titleBoxY + $titleBoxH + 18;

    $subBox = new Imagick();
    $subBox->newImage($subBoxW, $subBoxH, new ImagickPixel('#FFFFFF'));
    $subBox->setImageFormat('png');
    $subBox = gi_round_corners($subBox, 22);
    $img->compositeImage($subBox, Imagick::COMPOSITE_OVER, $subBoxX, $subBoxY);
    $subBox->destroy();

    $drawSub = new ImagickDraw();
    $drawSub->setFont($fontBold);
    $drawSub->setFillColor('#4D8AC7');
    $drawSub->setFontSize(40);
    $drawSub->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawSub, $W/2, $subBoxY + 52, 0, $subtitulo);

    // Fecha
    $drawF = new ImagickDraw();
    $drawF->setFont($fontSemi);
    $drawF->setFillColor('#FFFFFF');
    $drawF->setFontSize(30);
    $drawF->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawF, $W/2, $subBoxY + 120, 0, $fechaTxt);

    // =========================
    // Cards de ponentes (3x2)
    // =========================
    $speakers = $payload['speakers'] ?? [];
    if (count($speakers) < 1) {
        return new WP_REST_Response(['error'=>'No speakers'], 400);
    }

    $cols = 3;
    $rows = 2;

    // El bloque de cards empieza un poco ANTES de acabar banner
    $cardsTop = $bannerH - 40;  // 👈 Esto hace que se monten sobre el banner

    $gapX = 36;
    $gapY = 36;

    $areaH = $H - $cardsTop;
    $cardW = (int)(($W - $gapX*($cols+1)) / $cols);
    $cardH = (int)(($areaH - $gapY*($rows+1)) / $rows);

    $i = 0;
    for ($r=0; $r<$rows; $r++) {
        for ($c=0; $c<$cols; $c++) {

            if (!isset($speakers[$i])) break;

            $sp = $speakers[$i++];
            $name = trim($sp['name'] ?? '');
            $photoUrl = $sp['photo'] ?? null;
            $logoUrl  = $sp['logo']  ?? null;

            $x1 = $gapX + $c*($cardW + $gapX);
            $y1 = $cardsTop + $gapY + $r*($cardH + $gapY);

            // --- sombra ---
            $shadow = new Imagick();
            $shadow->newImage($cardW, $cardH, new ImagickPixel('rgba(0,0,0,0.18)'));
            $shadow->setImageFormat('png');
            $shadow = gi_round_corners($shadow, 24);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, $x1+6, $y1+8);
            $shadow->destroy();

            // --- card blanca ---
            $card = new Imagick();
            $card->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
            $card->setImageFormat('png');
            $card = gi_round_corners($card, 24);

            // foto circular
            $fotoSize = (int)($cardW * 0.55);
            $fotoX = (int)(($cardW - $fotoSize)/2);
            $fotoY = 20;

            if ($photoUrl) {
                $foto = $download_image($photoUrl);
                if ($foto) {
                    $foto = safe_thumbnail($foto, $fotoSize, $fotoSize, $photoUrl, 'speaker');
                    // convertir a círculo
                    $mask = new Imagick();
                    $mask->newImage($fotoSize, $fotoSize, new ImagickPixel('transparent'));
                    $mask->setImageFormat('png');
                    $drawMask = new ImagickDraw();
                    $drawMask->setFillColor('white');
                    $drawMask->circle($fotoSize/2, $fotoSize/2, $fotoSize/2, 0);
                    $mask->drawImage($drawMask);
                    $foto->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
                    $mask->destroy();

                    $card->compositeImage($foto, Imagick::COMPOSITE_OVER, $fotoX, $fotoY);
                    $foto->destroy();
                }
            }

            // nombre
            $drawN = new ImagickDraw();
            $drawN->setFont($fontBold);
            $drawN->setFillColor('#111111');
            $drawN->setFontSize(22);
            $drawN->setTextAlignment(Imagick::ALIGN_CENTER);
            $card->annotateImage($drawN, $cardW/2, $fotoY + $fotoSize + 48, 0, $name);

            // logo (contain)
            if ($logoUrl) {
                $logo = $download_image($logoUrl);
                if ($logo) {
                    $maxLogoW = (int)($cardW * 0.60);
                    $maxLogoH = 70;

                    $logo = gi_safe_contain_logo($logo, $maxLogoW, $maxLogoH, $logoUrl, 'logo speaker');

                    $lx = (int)(($cardW - $logo->getImageWidth())/2);
                    $ly = (int)($cardH - $logo->getImageHeight() - 26);

                    $card->compositeImage($logo, Imagick::COMPOSITE_OVER, $lx, $ly);
                    $logo->destroy();
                }
            }

            // pegar card al lienzo
            $img->compositeImage($card, Imagick::COMPOSITE_OVER, $x1, $y1);
            $card->destroy();
        }
    }

    // =========================
    // Exportar
    // =========================
    $format = strtolower($payload['output']['format'] ?? 'png');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'caratula_evento').'.'.$format);

    if ($format === 'jpg' || $format === 'jpeg') {
        $bg_layer = new Imagick();
        $bg_layer->newImage($W, $H, new ImagickPixel('#ffffff'));
        $bg_layer->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);
        $img = $bg_layer;
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(92);
    } else {
        $img->setImageFormat('png');
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
        'post_title'=>pathinfo($filename, PATHINFO_FILENAME),
        'post_status'=>'inherit'
    ], $upload['file']);

    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    error_log("✅ Imagen generada: $url");
    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}
