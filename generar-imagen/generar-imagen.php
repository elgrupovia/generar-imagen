<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario - Instagram
 * Description: Versión adaptada para publicaciones cuadradas (Instagram) y ponentes en formato cuadrado.
 * Version: 3.0.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Caratula evento (Instagram)');

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_instagram',
        'permission_callback' => '__return_true',
    ]);
});

// ------------------------
// Funciones auxiliares
// ------------------------

/**
 * Redimensionado tipo COVER (cubre y recorta) — usado para banner y fotos de ponentes.
 * Contexto 'speaker' mantiene ajuste vertical un poco hacia arriba para favorecer la cabeza.
 */
function safe_thumbnail($imagick, $w, $h, $url = '', $context = '') {
    if (!$imagick) return null;
    try {
        $iw = $imagick->getImageWidth();
        $ih = $imagick->getImageHeight();
        if ($iw <= 0 || $ih <= 0) {
            error_log("⚠️ Imagen inválida en $context: $url - Geometría 0x0.");
            return null;
        }

        if ($w > 0 && $h > 0) {
            $scaleRatio = max($w / $iw, $h / $ih);
            $newW = (int)($iw * $scaleRatio);
            $newH = (int)($ih * $scaleRatio);
            $imagick->scaleImage($newW, $newH);

            $x_offset = (int)(($newW - $w) / 2);

            if ($context === 'speaker') {
                // favorecemos el recorte levemente desde arriba (20%) para no cortar cabezas
                $y_offset = (int)(($newH - $h) * 0.18);
                if ($y_offset < 0) $y_offset = 0;
            } else {
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
    } catch (Exception $e) {
        error_log("❌ Error safe_thumbnail ($context): " . $e->getMessage());
        return null;
    }
}

/**
 * Contain para logos: se escala para que quepa dentro del área sin recortar
 */
function gi_safe_contain_logo($imagick, $targetW, $targetH, $url = '', $context = '') {
    if (!$imagick) return null;
    try {
        $iw = $imagick->getImageWidth();
        $ih = $imagick->getImageHeight();
        if ($iw <= 0 || $ih <= 0) {
            error_log("⚠️ Imagen inválida en $context: $url - Geometría 0x0.");
            return null;
        }
        $scaleRatio = min($targetW / $iw, $targetH / $ih, 1);
        $newW = (int)($iw * $scaleRatio);
        $newH = (int)($ih * $scaleRatio);
        $imagick->scaleImage($newW, $newH);
        return $imagick;
    } catch (Exception $e) {
        error_log("❌ Error gi_safe_contain_logo ($context): " . $e->getMessage());
        return null;
    }
}

/**
 * Aplica esquinas redondeadas a una imagen Imagick (opcional)
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
        error_log("❌ Error gi_round_corners: " . $e->getMessage());
        return $imagick;
    }
}

/**
 * Descarga una imagen externa a Imagick (usa curl y wp_tempnam)
 */
function gi_download_image($url) {
    if (!$url) return null;
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
        @unlink($tmp);
        return null;
    }
    @unlink($tmp);
    return $m;
}

// ------------------------
// Endpoint principal
// ------------------------
function gi_generate_collage_instagram(WP_REST_Request $request) {
    error_log('🚀 Generando carátula Instagram (cuadrada)');

    if (!class_exists('Imagick')) {
        return new WP_REST_Response(['error' => 'Imagick no disponible'], 500);
    }

    $token = $request->get_param('token');
    if ($token !== 'SECRETO') {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!$payload) {
        return new WP_REST_Response(['error' => 'No payload'], 400);
    }

    // ------------------------
    // Canvas para Instagram (por defecto 1080x1080)
    // ------------------------
    $W = intval($payload['canvas']['width'] ?? 1080);
    $H = intval($payload['canvas']['height'] ?? 1080);
    // Forzar cuadrado si no coincide
    if ($W !== $H) {
        $min = min($W, $H);
        $W = $H = $min;
    }

    $bg = $payload['canvas']['background'] ?? '#FFFFFF';

    // Fuentes
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $montserratBlackPath = $base_dir . '/fonts/Montserrat-Black.ttf';
    $montserratSemiPath  = $base_dir . '/fonts/Montserrat-SemiBold.ttf';
    $montserratRegPath   = $base_dir . '/fonts/Montserrat-Regular.ttf';

    $fontBold = file_exists($montserratBlackPath) ? $montserratBlackPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontSemi = file_exists($montserratSemiPath)  ? $montserratSemiPath  : $fontBold;
    $fontReg  = file_exists($montserratRegPath)   ? $montserratRegPath   : $fontBold;

    // ------------------------
    // Crear lienzo base
    // ------------------------
    $img = new Imagick();

    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        try {
            $bg_image = gi_download_image($bg);
            if ($bg_image) {
                $scaleRatio = max($W / $bg_image->getImageWidth(), $H / $bg_image->getImageHeight());
                $newW = (int)($bg_image->getImageWidth() * $scaleRatio);
                $newH = (int)($bg_image->getImageHeight() * $scaleRatio);
                $bg_image->scaleImage($newW, $newH);
                $x_offset = (int)(($newW - $W) / 2);
                $y_offset = (int)(($newH - $H) / 2);
                $bg_image->cropImage($W, $H, $x_offset, $y_offset);
                $img = $bg_image;
            } else {
                $img->newImage($W, $H, new ImagickPixel('#FFFFFF'));
            }
        } catch (Exception $e) {
            $img->newImage($W, $H, new ImagickPixel('#FFFFFF'));
        }
    } else {
        $img->newImage($W, $H, new ImagickPixel($bg));
    }
    $img->setImageFormat('png');

    // ------------------------
    // Banner superior (opcional) - ocupando 45% altura
    // ------------------------
    $bannerH = (int)($H * 0.45);
    $bannerUrl = $payload['banner_image']['photo'] ?? null;

    if ($bannerUrl) {
        $banner = gi_download_image($bannerUrl);
        if ($banner) {
            $banner = safe_thumbnail($banner, $W, $bannerH, $bannerUrl, 'banner');
            $img->compositeImage($banner, Imagick::COMPOSITE_OVER, 0, 0);
            $banner->destroy();
        }
    }

    // ------------------------
    // Textos (titulo/subtitulo/fecha)
    // ------------------------
    $titulo   = $payload['texts']['titulo']   ?? 'Gran Debate';
    $titulo2  = $payload['texts']['titulo2']  ?? 'HOTELERO';
    $subtitulo= $payload['texts']['subtitulo']?? 'Gran Canaria';
    $fechaTxt = $payload['texts']['fecha']    ?? '20 Junio 2024 · Lopesan Costa Meloneras';

    // Caja título central (blanco semi) — ajustada para cuadrado
    $titleBoxW = (int)($W * 0.86);
    $titleBoxH = (int)($H * 0.13);
    $titleBoxX = (int)(($W - $titleBoxW)/2);
    $titleBoxY = (int)($bannerH * 0.10);

    $titleBox = new Imagick();
    $titleBox->newImage($titleBoxW, $titleBoxH, new ImagickPixel('#FFFFFF'));
    $titleBox->setImageFormat('png');
    $titleBox = gi_round_corners($titleBox, 18);
    $img->compositeImage($titleBox, Imagick::COMPOSITE_OVER, $titleBoxX, $titleBoxY);
    $titleBox->destroy();

    $drawT = new ImagickDraw();
    $drawT->setFont($fontSemi);
    $drawT->setFillColor('#2F6EA9');
    $drawT->setFontSize((int)($titleBoxH * 0.38));
    $drawT->setTextAlignment(Imagick::ALIGN_LEFT);
    $img->annotateImage($drawT, $titleBoxX + 40, $titleBoxY + (int)($titleBoxH * 0.45), 0, $titulo);

    $drawT2 = new ImagickDraw();
    $drawT2->setFont($fontBold);
    $drawT2->setFillColor('#4D8AC7');
    $drawT2->setFontSize((int)($titleBoxH * 0.60));
    $drawT2->setTextAlignment(Imagick::ALIGN_LEFT);
    $img->annotateImage($drawT2, $titleBoxX + 40, $titleBoxY + (int)($titleBoxH * 0.88), 0, $titulo2);

    // subtitulo pequeño
    $drawSub = new ImagickDraw();
    $drawSub->setFont($fontReg);
    $drawSub->setFillColor('#4D8AC7');
    $drawSub->setFontSize((int)($titleBoxH * 0.38));
    $drawSub->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawSub, $W/2, $titleBoxY + $titleBoxH + 40, 0, $subtitulo);

    // fecha
    $drawF = new ImagickDraw();
    $drawF->setFont($fontSemi);
    $drawF->setFillColor('#FFFFFF');
    $drawF->setFontSize(22);
    $drawF->setTextAlignment(Imagick::ALIGN_CENTER);
    $img->annotateImage($drawF, $W/2, $titleBoxY + $titleBoxH + 78, 0, $fechaTxt);

    // ------------------------
    // Cards de ponentes (3x2) — ocupan la parte inferior y se sobrelapan levemente al banner
    // Fotos cuadradas (no circulares), se adaptan completamente al recuadro (cover)
    // ------------------------
    $speakers = $payload['speakers'] ?? [];
    if (count($speakers) < 1) {
        return new WP_REST_Response(['error' => 'No speakers'], 400);
    }

    $cols = 3;
    $rows = 2;

    $cardsTop = $bannerH - (int)($H * 0.06); // se montan sobre el banner

    $gapX = (int)($W * 0.033); // 3.3% margen
    $gapY = (int)($H * 0.033);

    $areaH = $H - $cardsTop;
    $cardW = (int)(($W - $gapX * ($cols + 1)) / $cols);
    $cardH = (int)(($areaH - $gapY * ($rows + 1)) / $rows);

    $i = 0;
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if (!isset($speakers[$i])) break;
            $sp = $speakers[$i++];
            $name = trim($sp['name'] ?? '');
            $photoUrl = $sp['photo'] ?? null;
            $logoUrl  = $sp['logo']  ?? null;

            $x1 = $gapX + $c * ($cardW + $gapX);
            $y1 = $cardsTop + $gapY + $r * ($cardH + $gapY);

            // sombra (sutil)
            $shadow = new Imagick();
            $shadow->newImage($cardW, $cardH, new ImagickPixel('rgba(0,0,0,0.12)'));
            $shadow->setImageFormat('png');
            $shadow = gi_round_corners($shadow, 16);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, $x1 + 6, $y1 + 8);
            $shadow->destroy();

            // card blanca
            $card = new Imagick();
            $card->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
            $card->setImageFormat('png');
            $card = gi_round_corners($card, 16);

            // area foto cuadrada (ocupando ~58% del alto del card)
            $fotoAreaH = (int)($cardH * 0.58);
            $fotoAreaW = $cardW;
            $fotoX = 0; // dentro del card
            $fotoY = 0;

            if ($photoUrl) {
                $foto = gi_download_image($photoUrl);
                if ($foto) {
                    // adaptamos la foto para que cubra completamente el área cuadrada (cover)
                    $foto = safe_thumbnail($foto, $fotoAreaW, $fotoAreaH, $photoUrl, 'speaker');

                    // pegamos la foto al card (en la parte superior)
                    $card->compositeImage($foto, Imagick::COMPOSITE_OVER, $fotoX, $fotoY);
                    $foto->destroy();
                }
            }

            // nombre (debajo de la foto)
            $drawN = new ImagickDraw();
            $drawN->setFont($fontBold);
            $drawN->setFillColor('#111111');
            $drawN->setFontSize(20);
            $drawN->setTextAlignment(Imagick::ALIGN_CENTER);
            $card->annotateImage($drawN, $cardW/2, $fotoAreaH + 40, 0, $name);

            // logo (contain) en la parte inferior dentro de margen
            if ($logoUrl) {
                $logo = gi_download_image($logoUrl);
                if ($logo) {
                    $maxLogoW = (int)($cardW * 0.6);
                    $maxLogoH = (int)($cardH * 0.14);
                    $logo = gi_safe_contain_logo($logo, $maxLogoW, $maxLogoH, $logoUrl, 'logo speaker');
                    $lx = (int)(($cardW - $logo->getImageWidth()) / 2);
                    $ly = (int)($cardH - $logo->getImageHeight() - 18);
                    $card->compositeImage($logo, Imagick::COMPOSITE_OVER, $lx, $ly);
                    $logo->destroy();
                }
            }

            // pegar card al lienzo
            $img->compositeImage($card, Imagick::COMPOSITE_OVER, $x1, $y1);
            $card->destroy();
        }
    }

    // ------------------------
    // Exportar (png/jpg)
    // ------------------------
    $format = strtolower($payload['output']['format'] ?? 'png');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'caratula_evento_instagram') . '.' . $format);

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
        return new WP_REST_Response(['error' => 'Fallo en upload: ' . $upload['error']], 500);
    }

    $filetype = wp_check_filetype($upload['file']);
    $attach_id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'],
        'post_title' => pathinfo($filename, PATHINFO_FILENAME),
        'post_status' => 'inherit'
    ], $upload['file']);

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    error_log("✅ Imagen generada: $url");
    return new WP_REST_Response(['url' => $url, 'attachment_id' => $attach_id], 200);
}
?>
