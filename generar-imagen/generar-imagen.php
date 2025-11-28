<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario
 * Description: Plantilla profesional para eventos inmobiliarios corporativos con diseño A4 Proporcional (35% Banner / 55% Grid 2x3 / 10% Sponsors).
 * Version: 2.5.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Caratula evento - Diseño A4 Proporcional con Sponsors Bar (Fix Composición)');

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_logs',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Función de redimensionado seguro (Cover logic) - Asegura que la imagen CUBRA la dimensión objetivo (puede cortar los bordes).
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
                
                // Recorte optimizado para fotos de personas
                if ($context === 'speaker' || $context === 'speaker_circular') {
                    $y_offset = (int)(($newH - $h) * 0.20); 
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
 * Función de redimensionado para LOGOS (Contain/Ajustar) - Mantiene el ratio y no CORTA.
 */
function gi_safe_contain_logo($imagick, $targetW, $targetH, $url, $context) {
    if (!$imagick) return null;

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($targetW > 0 && $targetH > 0) {
                $scaleRatio = min($targetW / $imagick->getImageWidth(), $targetH / $imagick->getImageHeight());
                $newW = (int)($imagick->getImageWidth() * $scaleRatio);
                $newH = (int)($imagick->getImageHeight() * $scaleRatio);

                $imagick->scaleImage($newW, $newH);
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
 * Aplica una máscara circular a una imagen.
 */
function gi_circular_mask($imagick) {
    if (!$imagick) return $imagick;

    try {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        $radius = min($width, $height) / 2;
        $centerX = $width / 2;
        $centerY = $height / 2;

        $mask = new Imagick();
        $mask->newImage($width, $height, new ImagickPixel('transparent'));
        $mask->setImageFormat('png');

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->circle($centerX, $centerY, $centerX, $centerY - $radius);
        $mask->drawImage($draw);
        
        $imagick->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0); 
        $mask->destroy();
        
        return $imagick;
    } catch (Exception $e) {
        error_log("❌ Error al aplicar máscara circular: ".$e->getMessage());
        return $imagick;
    }
}


/**
 * Envuelve el texto a una anchura máxima.
 */
function gi_word_wrap_text($draw, $imagick, $text, $maxWidth) {
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';

    foreach ($words as $word) {
        $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
        $metrics = $imagick->queryFontMetrics($draw, $testLine);

        if ($metrics['textWidth'] <= $maxWidth) {
            $currentLine = $testLine;
        } else {
            if ($currentLine) {
                $lines[] = $currentLine;
            }
            $currentLine = $word;
        }
    }
    if ($currentLine) {
        $lines[] = $currentLine;
    }
    return $lines;
}


function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('🚀 Ejecutando FIX de composición para A4 con Sponsors Bar');

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

    // --- CONFIGURACIÓN DE LIENZO Y FUENTE ---
    $W = intval($payload['canvas']['width'] ?? 1600);
    $H = intval($payload['canvas']['height'] ?? 2400);
    
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    // Se fuerza a usar Montserrat si existe, si no, se confía en el fallback por defecto
    $montserratBlackPath = $base_dir . '/fonts/Montserrat-Black.ttf';
    $fontPath = file_exists($montserratBlackPath) ? $montserratBlackPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    // 🖼️ Crear lienzo base con fondo gris claro
    $img = new Imagick();
    $img->newImage($W, $H, new ImagickPixel('#f0f0f0')); 
    $img->setImageFormat('png');


    // 🔽 Función de descarga
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
        
        if (!$data || $status != 200) {
            error_log("⚠️ No se descargó: $url (status $status)");
            return null;
        }
        
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        
        try {
            $m = new Imagick($tmp);
            if ($m->getImageWidth() === 0 || $m->getImageHeight() === 0) {
                 $m->destroy();
                 @unlink($tmp);
                 return null;
            }
        } catch (Exception $e) {
            error_log("❌ Error leyendo $url: ".$e->getMessage());
            $m = null;
        }
        
        @unlink($tmp);
        return $m;
    };

    // --- DATOS DEL PAYLOAD ---
    $bannerImageUrl = $payload['banner_image']['photo'] ?? null;
    $speakers = $payload['speakers'] ?? [];
    $totalSpeakers = count($speakers);
    $cols = 3;
    $rows = 2; 
    $maxSpeakers = $cols * $rows; 


    // --- 1. BANNER SUPERIOR (35% H) ---
    $bannerH = intval($H * 0.35); // 840px
    $bannerY = 0;
    
    if ($bannerImageUrl) {
        $bg_image = $download_image($bannerImageUrl);
        if ($bg_image) {
            $bg_image = safe_thumbnail($bg_image, $W, $bannerH, $bannerImageUrl, 'banner_top');
            
            // Capa negra semi-transparente (Opacidad 40%)
            $overlay = new Imagick();
            $overlay->newImage($W, $bannerH, new ImagickPixel('rgba(0,0,0,0.40)')); 
            $bg_image->compositeImage($overlay, Imagick::COMPOSITE_OVER, 0, 0);
            $overlay->destroy();
            
            $img->compositeImage($bg_image, Imagick::COMPOSITE_OVER, 0, $bannerY);
            $bg_image->destroy();
        } else {
             $solidBanner = new Imagick();
             $solidBanner->newImage($W, $bannerH, new ImagickPixel('#1a1a1a'));
             $img->compositeImage($solidBanner, Imagick::COMPOSITE_OVER, 0, $bannerY);
             $solidBanner->destroy();
        }
    }

    // ✍️ Texto del Banner (Título y Detalles)
    $drawTitle = new ImagickDraw();
    if (file_exists($fontPath)) $drawTitle->setFont($fontPath);
    $drawTitle->setFillColor('#FFFFFF');
    $drawTitle->setFontSize(70); 
    $drawTitle->setFontWeight(900);
    $drawTitle->setTextAlignment(Imagick::ALIGN_CENTER);

    $metricsTitle = $img->queryFontMetrics($drawTitle, $bannerTitle);
    
    $drawDetails = new ImagickDraw();
    if (file_exists($fontPath)) $drawDetails->setFont($fontPath);
    $drawDetails->setFillColor('#CCCCCC');
    $drawDetails->setFontSize(35); 
    $drawDetails->setFontWeight(600);
    $drawDetails->setTextAlignment(Imagick::ALIGN_CENTER);
    
    $metricsDetails = $img->queryFontMetrics($drawDetails, $eventDetails);

    $totalTextHeight = $metricsTitle['textHeight'] + 20 + $metricsDetails['textHeight']; 
    $titleY = $bannerY + ($bannerH / 2) - ($totalTextHeight / 2) + $metricsTitle['textHeight'] - 10;
    
    $img->annotateImage($drawTitle, $W / 2, $titleY, 0, $bannerTitle);
    $detailsY = $titleY + 20 + 5; 
    $img->annotateImage($drawDetails, $W / 2, $detailsY, 0, $eventDetails);


    // --- 2. SECCIÓN DE TARJETAS Y SPONSORS (65% H) ---
    $cardsSectionH = $H - $bannerH; // 1560px
    $cardsSectionY = $bannerH; // 840px

    // --- 2a. GRID DE TARJETAS (Aprox. 80% de la sección 65%) ---
    $gridAreaH = intval($cardsSectionH * 0.80); // 1248px
    
    $marginLR = intval($W * 0.05); // 80px
    $gridMarginTB = intval($gridAreaH * 0.03); // 37px
    
    $gridW = $W - 2 * $marginLR; // 1440px
    $gridH = $gridAreaH - 2 * $gridMarginTB; // 1174px

    $gridXStart = $marginLR; // 80px
    $gridYStart = $cardsSectionY + $gridMarginTB; // 877px

    $gapX = intval($W * 0.03); // 48px
    $gapY = intval($gridH * 0.04); // 46px

    $cardW = intval(($gridW - ($cols - 1) * $gapX) / $cols); // 448px
    $cardH = intval(($gridH - ($rows - 1) * $gapY) / $rows); // 564px

    
    // --- Dimensiones Internas de la Tarjeta ---
    $photoDiameter = intval($cardW * 0.55); // **Aumento de la foto al 55%** (246px)
    $photoMarginTop = intval($cardH * 0.10); // 10% de la altura (56px)
    
    $nameFontSize = 40; 
    $roleFontSize = 25; 
    $internalPadding = 30;
    $shadowMargin = 15;

    $index = 0;
    for ($r = 0; $r < $rows; $r++) {
        $baseY = $gridYStart + $r * ($cardH + $gapY);
        for ($c = 0; $c < $cols; $c++) {
            if ($index >= $totalSpeakers || $index >= $maxSpeakers) break 2;
            
            $sp = $speakers[$index++] ?? null;
            if (!$sp) continue;

            $cardCanvas = new Imagick();
            $cardCanvas->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
            $cardCanvas->setImageFormat('png');
            
            // 🖌️ Redondear esquinas y aplicar sombra
            $cornerRadius = 20; 
            $cardCanvas = gi_round_corners($cardCanvas, $cornerRadius);

            $cardWithShadow = new Imagick();
            $cardWithShadow->newImage($cardW + $shadowMargin*2, $cardH + $shadowMargin*2, new ImagickPixel('transparent'));
            $cardWithShadow->setImageFormat('png');

            $cardCanvas->setImageBackgroundColor(new ImagickPixel('rgba(0, 0, 0, 0)'));
            $cardCanvas->shadowImage(80, 5, 0, 0); 

            $cardWithShadow->compositeImage($cardCanvas, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
            $cardCanvas->destroy();
            $cardCanvas = $cardWithShadow; 
            
            $x = $gridXStart + $c * ($cardW + $gapX) - $shadowMargin; 
            $y = $baseY - $shadowMargin;
            
            // --- CONTENIDO INTERNO DE LA TARJETA ---
            $internalCanvas = new Imagick();
            $internalCanvas->newImage($cardW, $cardH, new ImagickPixel('transparent'));
            $internalCanvas->setImageFormat('png');

            $currentY = $photoMarginTop; // 56px
            
            // 📷 Foto Circular
            $photoUrl = $sp['photo'] ?? null;
            $photoBase = $download_image($photoUrl);

            if ($photoBase) {
                $photoBase = safe_thumbnail($photoBase, $photoDiameter, $photoDiameter, $photoUrl, 'speaker_circular');
                if ($photoBase) {
                    $photoBase = gi_circular_mask($photoBase);
                    $photoX = ($cardW - $photoDiameter) / 2;
                    // FIX: Componer la foto
                    $internalCanvas->compositeImage($photoBase, Imagick::COMPOSITE_OVER, intval($photoX), intval($currentY));
                    $photoBase->destroy();
                    $currentY += $photoDiameter + 25; // Aumentamos espacio después de la foto
                }
            }
            
            // ✍️ Nombre
            $drawName = new ImagickDraw();
            if (file_exists($fontPath)) $drawName->setFont($fontPath);
            $drawName->setFillColor('#000000'); 
            $drawName->setFontSize($nameFontSize); 
            $drawName->setFontWeight(900);
            $drawName->setTextAlignment(Imagick::ALIGN_CENTER);
            $name = trim($sp['name'] ?? 'Nombre Apellido');
            
            $metricsName = $internalCanvas->queryFontMetrics($drawName, $name);
            $nameY = $currentY + $metricsName['textHeight'] / 2;
            // FIX: Componer el nombre
            $internalCanvas->annotateImage($drawName, $cardW / 2, $nameY, 0, $name);
            $currentY += $metricsName['textHeight'] + 15; // Espacio después del nombre
            
            // ✍️ Rol (Ocupación)
            $drawRole = new ImagickDraw();
            if (file_exists($fontPath)) $drawRole->setFont($fontPath);
            $drawRole->setFillColor('#555555'); 
            $drawRole->setFontSize($roleFontSize); 
            $drawRole->setFontWeight(600);
            $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
            $role = trim($sp['role'] ?? 'Cargo en la Empresa');
            
            $roleLines = gi_word_wrap_text($drawRole, $internalCanvas, $role, $cardW - $internalPadding * 2);
            $lineHeight = $roleFontSize + 5; 
            
            // Posicionamiento vertical secuencial y robusto del Rol
            foreach ($roleLines as $i => $line) {
                // FIX: Componer el rol
                $internalCanvas->annotateImage($drawRole, $cardW / 2, $currentY + ($i * $lineHeight), 0, $line);
            }

            // 🖼️ Componer el contenido en el canvas con sombra
            $cardCanvas->compositeImage($internalCanvas, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
            $internalCanvas->destroy();

            // 🖼️ Componer la tarjeta con sombra en el lienzo principal
            $img->compositeImage($cardCanvas, Imagick::COMPOSITE_OVER, intval($x), intval($y));
            $cardCanvas->destroy();
        }
    }
    error_log("🎤 Grid de tarjetas 2x3 generado con éxito (FIX aplicado).");

    // --- 2b. BARRA DE SPONSORS (Aprox. 20% de la sección 65%) ---
    $sectionPatrocinadoresH = $cardsSectionH - $gridAreaH; // 312px
    $sectionPatrocinadoresY = $cardsSectionY + $gridAreaH; // 2088px

    $patrocinadoresCanvas = new Imagick();
    $patrocinadoresCanvas->newImage($W, $sectionPatrocinadoresH, new ImagickPixel('#FFFFFF')); 
    $patrocinadoresCanvas->setImageFormat('png');
    
    $sponsorLogos = array_merge($payload['logos'] ?? [], $payload['sponsors'] ?? []);
    
    if (!empty($sponsorLogos)) {
        
        // ✍️ Título "Sponsors:"
        $drawSponsorTitle = new ImagickDraw();
        if (file_exists($fontPath)) $drawSponsorTitle->setFont($fontPath);
        $drawSponsorTitle->setFillColor('#333333'); 
        $drawSponsorTitle->setFontSize(30); 
        $drawSponsorTitle->setFontWeight(700);
        $drawSponsorTitle->setTextAlignment(Imagick::ALIGN_CENTER);
        
        $sponsorTitleText = 'Sponsors:';
        $metricsST = $patrocinadoresCanvas->queryFontMetrics($drawSponsorTitle, $sponsorTitleText);
        
        $titleY = 30 + $metricsST['textHeight'];
        $patrocinadoresCanvas->annotateImage($drawSponsorTitle, $W / 2, $titleY, 0, $sponsorTitleText);

        $logosYStart = $titleY + 10; 
        $logosAreaH = $sectionPatrocinadoresH - $logosYStart - 10; 
        
        $logoMaxH = intval($logosAreaH * 0.80); 
        $logoAreaW = $W - 2 * $marginLR; 
        $logoSpacing = 40; 
        
        $logosToCompose = [];
        $currentXWidth = 0; 

        // 1. Pre-cargar y dimensionar logos para calcular ancho total
        foreach ($sponsorLogos as $logoData) {
            $logoUrl = $logoData['photo'] ?? null;
            if (!$logoUrl) continue;

            $logoBase = $download_image($logoUrl);
            if ($logoBase) {
                $logoBase = gi_safe_contain_logo($logoBase, $logoAreaW, $logoMaxH, $logoUrl, 'sponsor_logo');
                if ($logoBase) {
                    $logoW = $logoBase->getImageWidth();
                    // Limitar a los logos que quepan en una fila
                    if ($currentXWidth + $logoW + ($currentXWidth > 0 ? $logoSpacing : 0) <= $logoAreaW) {
                         $logosToCompose[] = $logoBase;
                         $currentXWidth += $logoW + $logoSpacing;
                    } else {
                         $logoBase->destroy();
                    }
                }
            }
        }
        
        // 2. Componer logos centrados
        if (!empty($logosToCompose)) {
            $currentXWidth -= $logoSpacing; // Eliminar el último espaciado sobrante
            $startX = $marginLR + ($logoAreaW - $currentXWidth) / 2; // Posición de inicio centrada

            $currentX = $startX;

            foreach ($logosToCompose as $logoBase) {
                $logoW = $logoBase->getImageWidth();
                $logoH = $logoBase->getImageHeight();
                
                $logoY = $logosYStart + ($logosAreaH - $logoH) / 2;
                
                $patrocinadoresCanvas->compositeImage($logoBase, Imagick::COMPOSITE_OVER, intval($currentX), intval($logoY));
                $logoBase->destroy();
                
                $currentX += $logoW + $logoSpacing;
            }
            error_log("⭐ Sección de patrocinadores generada con logos centrados.");
        } else {
            error_log("⚠️ No hay logos válidos para generar la barra.");
        }
    }
    
    $img->compositeImage($patrocinadoresCanvas, Imagick::COMPOSITE_OVER, 0, $sectionPatrocinadoresY);
    $patrocinadoresCanvas->destroy();

    // 📤 Exportar
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'evento_a4').'_final_v3.'.$format);

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
        'post_title'=>pathinfo($filename, PATHINFO_FILENAME), 
        'post_status'=>'inherit'
    ], $upload['file']);
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    error_log("✅ Imagen generada (Diseño A4 Final): $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}