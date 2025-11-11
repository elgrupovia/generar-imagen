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

/**
 * Función de redimensionado seguro (Cover logic).
 */
function safe_thumbnail($imagick, $w, $h, $url, $context) {
    if (!$imagick) return null; // Pre-check for null Imagick object

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($w > 0 && $h > 0) {
                // Usar scaleImage + crop para asegurar que cubre y tiene el tamaño exacto (cover)
                $scaleRatio = max($w / $imagick->getImageWidth(), $h / $imagick->getImageHeight());
                $newW = (int)($imagick->getImageWidth() * $scaleRatio);
                $newH = (int)($imagick->getImageHeight() * $scaleRatio);

                $imagick->scaleImage($newW, $newH);

                $x_offset = (int)(($newW - $w) / 2);
                $y_offset = (int)(($newH - $h) / 2);
                $imagick->cropImage($w, $h, $x_offset, $y_offset);
                $imagick->setImagePage($w, $h, 0, 0); // Ajustar el lienzo
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
 * Aplica esquinas redondeadas a una imagen Imagick.
 */
function gi_round_corners($imagick, $radius) {
    if (!$imagick) return $imagick;

    try {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black')); // Color para la máscara
        $draw->setStrokeColor(new ImagickPixel('black'));
        $draw->roundRectangle(0, 0, $width - 1, $height - 1, $radius, $radius);

        $mask = new Imagick();
        $mask->newImage($width, $height, new ImagickPixel('transparent'));
        $mask->drawImage($draw);
        $mask->setImageFormat('png');

        $imagick->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0); // Aplica la máscara
        $mask->destroy();
        
        return $imagick;
    } catch (Exception $e) {
        error_log("❌ Error al redondear esquinas: ".$e->getMessage());
        return $imagick; // Retorna la imagen original si hay error
    }
}


function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('🚀 Iniciando plugin Evento Inmobiliario Pro');

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

    // Ruta a la fuente Montserrat-Black (ajusta si es necesario)
    $montserratBlackPath = '/usr/share/fonts/truetype/google-fonts/Montserrat-Black.ttf'; // O la ruta correcta en tu sistema
    $fontPath = file_exists($montserratBlackPath) ? $montserratBlackPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';


    // 🖼️ Crear lienzo base con fondo que COBRE TODO
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        $bg_image = new Imagick();
        try {
            $bg_image->readImage($bg);

            if ($bg_image->getImageWidth() > 0 && $bg_image->getImageHeight() > 0) {
                $scaleRatio = max($W / $bg_image->getImageWidth(), $H / $bg_image->getImageHeight());
                $newW = (int)($bg_image->getImageWidth() * $scaleRatio);
                $newH = (int)($bg_image->getImageHeight() * $scaleRatio);

                $bg_image->scaleImage($newW, $newH);

                $x_offset = (int)(($newW - $W) / 2);
                $y_offset = (int)(($newH - $H) / 2);
                $bg_image->cropImage($W, $H, $x_offset, $y_offset);

                $img = $bg_image;
                $img->blurImage(2, 1); // Difuminar
                error_log("🖼️ Fondo aplicado, escalado y difuminado para cubrir todo el lienzo");
            } else {
                 error_log("⚠️ Imagen de fondo inválida o no disponible: $bg. Usando color sólido.");
                 $img->newImage($W, $H, new ImagickPixel($bg));
            }
        } catch (Exception $e) {
            error_log("❌ Error leyendo imagen de fondo: ".$e->getMessage());
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
            error_log("⚠️ No se descargó: $url (status $status) - Dato recibido vacío o error HTTP.");
            return null;
        }
        
        $tmp = wp_tempnam();
        file_put_contents($tmp, $data);
        
        try {
            $m = new Imagick($tmp);
            if ($m->getImageWidth() === 0 || $m->getImageHeight() === 0) {
                 $m->destroy();
                 error_log("❌ Error leyendo $url - Imagick leyó el archivo pero la geometría es 0x0.");
                 @unlink($tmp);
                 return null;
            }
        } catch (Exception $e) {
            error_log("❌ Error leyendo $url - Imagick no pudo leer el contenido como imagen: ".$e->getMessage());
            $m = null;
        }
        
        @unlink($tmp);
        return $m;
    };

    // 📐 Zonas de diseño (REAJUSTADO: Banner y contenido superior BAJADO)
    $headerStart = 0;
    $headerEnd = intval($H * 0.20); 
    
    $eventInfoStart = $headerEnd + 20; 
    $eventInfoEnd = intval($H * 0.26); 
    
    $speakersStart = $eventInfoEnd;
    $speakersEnd = intval($H * 0.70); 
    
    $finalAreaEnd = intval($H * 0.95); 
    
    $gapSize = 40; 
    $totalGapsBetweenBoxes = $gapSize * 2; 

    $availableHeightForBoxes = $finalAreaEnd - $speakersEnd - $totalGapsBetweenBoxes;
    $equalBoxHeight = intval($availableHeightForBoxes / 2); 
    
    $sectionPonentesStart = $speakersEnd + $gapSize; 
    $sectionPonentesEnd = $sectionPonentesStart + $equalBoxHeight; 
    
    $sectionPatrocinadoresStart = $sectionPonentesEnd + $gapSize; 
    $sectionPatrocinadoresEnd = $sectionPatrocinadoresStart + $equalBoxHeight; 
    
    if ($sectionPatrocinadoresEnd > $finalAreaEnd) {
        $sectionPatrocinadoresEnd = $finalAreaEnd;
        $sectionPonentesEnd = $sectionPatrocinadoresStart - $gapSize;
        $equalBoxHeight = $sectionPonentesEnd - $sectionPonentesStart;
    }


    // 🖼️ Banner de IMAGEN centrado con borde redondeado
    $bannerBoxW = intval($W * 0.65);
    $bannerBoxH = intval($headerEnd * 0.80); 
    $bannerX = intval(($W - $bannerBoxW) / 2);
    $bannerY = intval(($headerEnd - $bannerBoxH) / 2) + 20; 

    if (!empty($payload['banner_image']) && ($bannerImageUrl = $payload['banner_image']['photo'] ?? null)) {
        $bannerImage = $download_image($bannerImageUrl);
        if ($bannerImage) {
            $bannerImage = safe_thumbnail($bannerImage, $bannerBoxW, $bannerBoxH, $bannerImageUrl, 'banner principal');
            if ($bannerImage) {
                $cornerRadius = 40; 
                $bannerImage = gi_round_corners($bannerImage, $cornerRadius);
                $img->compositeImage($bannerImage, Imagick::COMPOSITE_OVER, $bannerX, $bannerY);
                error_log("🖼️ Banner de imagen principal agregado y redondeado.");
            }
        } else {
             error_log("❌ Error al cargar la imagen de banner: $bannerImageUrl");
        }
    } else {
        error_log("⚠️ No se proporcionó 'banner_image'. Dejando espacio vacío para el banner.");
    }

    // ✨ Logo superior derecho (Ajuste y depuración de carga con FALLBACK de texto)
    $logoWidthTarget = intval($W * 0.14); // Ancho deseado para el logo
    $logoHeightTarget = intval($logoWidthTarget * 0.5); // Altura estimada para el logo o fallback

    if (!empty($payload['header_logo'])) {
        $logoUrl = $payload['header_logo']['photo'] ?? null;
        if ($logoUrl) {
            error_log("🔍 Intentando descargar logo header desde: $logoUrl");
            $headerLogo = $download_image($logoUrl);
            
            if ($headerLogo && $headerLogo->getImageWidth() > 0) {
                $headerLogo = safe_thumbnail($headerLogo, $logoWidthTarget, 0, $logoUrl, 'logo header'); // Altura 0 para mantener proporción
                if ($headerLogo) {
                    $x = $W - $headerLogo->getImageWidth() - 40;
                    $y = 30; 
                    $img->compositeImage($headerLogo, Imagick::COMPOSITE_OVER, $x, $y);
                    error_log("✨ Logo header agregado en esquina superior derecha con éxito.");
                } else {
                     error_log("❌ FALLBACK: Error en redimensionado de logo descargado. Usando texto de fallback.");
                     // Si el redimensionado falla (Imagick lo considera inválido tras descarga)
                     $headerLogo = null; 
                }
            } else {
                 error_log("❌ FALLBACK: No se pudo cargar el logo desde la URL $logoUrl. Usando texto de fallback.");
            }
        } else {
             error_log("⚠️ 'header_logo' está presente en el JSON, pero la URL de la foto está vacía. Usando texto de fallback.");
        }
    }

    // Si el logo no se pudo cargar o no se especificó, crear un fallback de texto.
    if (!isset($headerLogo) || $headerLogo === null) {
        $fallbackLogoCanvas = new Imagick();
        $fallbackLogoCanvas->newImage($logoWidthTarget, $logoHeightTarget, new ImagickPixel('transparent'));
        $fallbackLogoCanvas->setImageFormat('png');

        $drawFallback = new ImagickDraw();
        if (file_exists($fontPath)) $drawFallback->setFont($fontPath);
        $drawFallback->setFillColor('#000000'); // Texto negro para contraste
        $drawFallback->setFontSize(40); // Tamaño visible
        $drawFallback->setFontWeight(900);
        $drawFallback->setTextAlignment(Imagick::ALIGN_CENTER);

        // Calcular posición del texto dentro del canvas de fallback
        $metrics = $fallbackLogoCanvas->queryFontMetrics($drawFallback, 'LOGO');
        $textX = $logoWidthTarget / 2;
        $textY = ($logoHeightTarget + $metrics['textHeight']) / 2; 

        $fallbackLogoCanvas->annotateImage($drawFallback, $textX, $textY, 0, 'LOGO');
        $img->compositeImage($fallbackLogoCanvas, Imagick::COMPOSITE_OVER, $W - $logoWidthTarget - 40, 30);
        error_log("✨ Se ha usado el logo de fallback de texto 'LOGO'.");
    }
    
    // 📅 Detalles del evento
    $draw = new ImagickDraw();
    if (file_exists($fontPath)) $draw->setFont($fontPath); // Usar Montserrat Black
    $draw->setFillColor('#FFFFFF');
    $draw->setFontSize(32);
    $draw->setFontWeight(600);
    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
    $eventDetails = $payload['event_details'] ?? '6 noviembre 2026 9:00h - Silken Puerta Valencia';
    $img->annotateImage($draw, $W / 2, $eventInfoStart + 20, 0, $eventDetails); 
    error_log("📅 Detalles: $eventDetails (reposicionado)");

    // 👤 Speakers con recuadros redondeados
    $speakers = $payload['speakers'] ?? [];
    if (!empty($speakers)) {
        error_log("🎤 Procesando ".count($speakers)." speakers");

        $totalSpeakers = count($speakers);
        $cols = 3;
        $rows = ceil($totalSpeakers / $cols);
        
        $photoW = intval($W / 4.5); 
        $photoH = intval($photoW * 1.2); 
        $gapX = 50; 
        $gapY = 60; 
        $textHeightInternal = intval($photoH * 0.3); 
        $photoImageHeight = $photoH - $textHeightInternal; 

        $availableHeight = $speakersEnd - $speakersStart;
        $totalHeight = $rows * $photoH + ($rows - 1) * $gapY;
        $startY = $speakersStart + intval(($availableHeight - $totalHeight) / 2);

        $index = 0;
        for ($r = 0; $r < $rows; $r++) {
            $y = $startY + $r * ($photoH + $gapY); 
            $numInRow = min($cols, $totalSpeakers - $index);
            $rowW = $numInRow * $photoW + ($numInRow - 1) * $gapX;
            $x = ($W - $rowW) / 2;

            for ($c = 0; $c < $numInRow; $c++) {
                $sp = $speakers[$index++] ?? null;
                if (!$sp) continue;

                $photoUrl = $sp['photo'] ?? null;
                $name = trim($sp['name'] ?? '');
                $role = trim($sp['role'] ?? '');

                $photoBase = $download_image($photoUrl);
                
                $photoBase = safe_thumbnail($photoBase, $photoW, $photoImageHeight, $photoUrl, 'speaker');
                if (!$photoBase) continue;

                $speakerCanvas = new Imagick();
                $speakerCanvas->newImage($photoW, $photoH, new ImagickPixel('transparent'));
                $speakerCanvas->setImageFormat('png');

                $speakerCanvas->compositeImage($photoBase, Imagick::COMPOSITE_OVER, 0, 0);

                $whiteBg = new Imagick();
                $whiteBg->newImage($photoW, $textHeightInternal, new ImagickPixel('#FFFFFF'));
                $whiteBg->setImageFormat('png');
                $speakerCanvas->compositeImage($whiteBg, Imagick::COMPOSITE_OVER, 0, $photoImageHeight); 

                try {
                    $draw = new ImagickDraw();
                    if (file_exists($fontPath)) $draw->setFont($fontPath); // Usar Montserrat Black
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->setFillColor('#000000'); 
                    $draw->setFontSize(32); 
                    $draw->setFontWeight(900);

                    $centerX = $photoW / 2;
                    $nameY = $photoImageHeight + intval($textHeightInternal / 2) - 15; 

                    if ($name) {
                        $speakerCanvas->annotateImage($draw, $centerX, $nameY, 0, $name);
                    }

                    if ($role) {
                        $drawRole = new ImagickDraw();
                        if (file_exists($fontPath)) $drawRole->setFont($fontPath); // Usar Montserrat Black
                        $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                        $drawRole->setFillColor('#555555'); 
                        $drawRole->setFontSize(22); 
                        $drawRole->setFontWeight(600);
                        $speakerCanvas->annotateImage($drawRole, $centerX, $nameY + 30, 0, $role);
                    }
                } catch (Exception $e) {
                    error_log("💥 Error texto en speaker canvas: ".$e->getMessage());
                }

                $cornerRadius = 30; 
                $speakerCanvas = gi_round_corners($speakerCanvas, $cornerRadius);
                if (!$speakerCanvas) continue; 
                 
                try {
                    $shadow = new Imagick();
                    $shadow->readImageBlob($speakerCanvas->getImageBlob());
                    $shadow->shadowImage(80, 3, 0, 0);
                    $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($x) - 3, intval($y) + 3);
                } catch (Exception $e) {
                    error_log("⚠️ Sombra no aplicada: ".$e->getMessage());
                }
                
                $img->compositeImage($speakerCanvas, Imagick::COMPOSITE_OVER, intval($x), intval($y));

                $x += $photoW + $gapX;
            }
        }
        error_log("🎤 Grid: $rows filas x $cols columnas");
    }

    // 🏷️ Sección de Ponentes
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $sectionPonentesW = $W - 80; 
        $sectionPonentesH = $equalBoxHeight; 
        $sectionPonentesX = ($W - $sectionPonentesW) / 2;
        $sectionPonentesY = $sectionPonentesStart;

        $ponPonentessCanvas = new Imagick();
        $ponPonentessCanvas->newImage($sectionPonentesW, $sectionPonentesH, new ImagickPixel('#FFFFFF'));
        $ponPonentessCanvas->setImageFormat('png');

        $cornerRadius = 30; 
        $ponPonentessCanvas = gi_round_corners($ponPonentessCanvas, $cornerRadius);
        if (!$ponPonentessCanvas) {
            error_log("❌ No se pudo redondear el canvas de ponentes.");
            return new WP_REST_Response(['error'=>'Failed to round corners for ponentes section'], 500);
        }

        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath); // Usar Montserrat Black
        $draw->setFillColor('#000000');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        
        $titlePonentesY = 40; 
        $ponPonentessCanvas->annotateImage($draw, $sectionPonentesW / 2, $titlePonentesY, 0, 'Ponentes:');

        $logosAreaTop = $titlePonentesY + 30; 
        $logosAreaHeight = $sectionPonentesH - $logosAreaTop - 20; 
        $logoMaxH = intval($logosAreaHeight * 0.80); 
        
        $totalLogosInRow = count($logos);
        $gapBetweenLogos = 40; 
        $horizontalPadding = 60; 

        $availableLogosWidth = $sectionPonentesW - ($horizontalPadding * 2);
        $calculatedMaxW = ($availableLogosWidth - ($totalLogosInRow - 1) * $gapBetweenLogos) / $totalLogosInRow;
        $maxW = min(180, (int)$calculatedMaxW); 

        $actualLogosWidth = $totalLogosInRow * $maxW + ($totalLogosInRow - 1) * $gapBetweenLogos;
        $startLogoXInsideBox = $horizontalPadding + intval(($availableLogosWidth - $actualLogosWidth) / 2);

        $currentX = $startLogoXInsideBox;

        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            $m = safe_thumbnail($m, $maxW, $logoMaxH, $logo['photo'], 'logo');
            if (!$m) continue;
            
            $ponPonentessCanvas->compositeImage($m, Imagick::COMPOSITE_OVER, intval($currentX), intval($logosAreaTop + ($logosAreaHeight - $m->getImageHeight()) / 2));
            $currentX += $maxW + $gapBetweenLogos;
        }

        try {
            $shadow = new Imagick();
            $shadow->readImageBlob($ponPonentessCanvas->getImageBlob());
            $shadow->shadowImage(80, 3, 0, 0);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($sectionPonentesX) - 3, intval($sectionPonentesY) + 3);
        } catch (Exception $e) {
            error_log("⚠️ Sombra no aplicada al recuadro de ponentes: ".$e->getMessage());
        }

        $img->compositeImage($ponPonentessCanvas, Imagick::COMPOSITE_OVER, $sectionPonentesX, $sectionPonentesY);
        error_log("💼 ".count($logos)." logos ponentes en recuadro redondeado con título encima.");
    }

    // 🤝 Sección de Patrocinadores
    $sponsors = $payload['sponsors'] ?? [];
    $closingImages = $payload['closing_images'] ?? []; 
    
    if (!empty($sponsors) || !empty($closingImages)) {
        $sectionPatrocinadoresW = $W - 80; 
        $sectionPatrocinadoresH = $equalBoxHeight; 
        $sectionPatrocinadoresX = ($W - $sectionPatrocinadoresW) / 2;
        $sectionPatrocinadoresY = $sectionPatrocinadoresStart;

        $patrocinadoresCanvas = new Imagick();
        $patrocinadoresCanvas->newImage($sectionPatrocinadoresW, $sectionPatrocinadoresH, new ImagickPixel('#FFFFFF'));
        $patrocinadoresCanvas->setImageFormat('png');

        $cornerRadius = 30; 
        $patrocinadoresCanvas = gi_round_corners($patrocinadoresCanvas, $cornerRadius);
        if (!$patrocinadoresCanvas) {
            error_log("❌ No se pudo redondear el canvas de patrocinadores.");
            return new WP_REST_Response(['error'=>'Failed to round corners for patrocinadores section'], 500);
        }

        $currentContentY = 40; 

        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath); // Usar Montserrat Black
        $draw->setFillColor('#000000');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $patrocinadoresCanvas->annotateImage($draw, $sectionPatrocinadoresW / 2, $currentContentY, 0, 'Patrocina:');
        $currentContentY += 60; 

        $remainingHeight = $sectionPatrocinadoresH - $currentContentY - 20; 
        $blockHeight = intval($remainingHeight / 2); 

        // Logos de Patrocinadores
        if (!empty($sponsors)) {
            $logosAreaHeight = $blockHeight; 
            $logoMaxH = intval($logosAreaHeight * 0.70); 
            
            $totalSponsorsInRow = count($sponsors);
            $gapBetweenSponsors = 60;
            $horizontalPadding = 60; 

            $availableSponsorsWidth = $sectionPatrocinadoresW - ($horizontalPadding * 2);
            $calculatedMaxW = ($availableSponsorsWidth - ($totalSponsorsInRow - 1) * $gapBetweenSponsors) / $totalSponsorsInRow;
            $maxW = min(300, (int)$calculatedMaxW); 

            $actualSponsorsWidth = $totalSponsorsInRow * $maxW + ($totalSponsorsInRow - 1) * $gapBetweenSponsors;
            $startSponsorXInsideBox = $horizontalPadding + intval(($availableSponsorsWidth - $actualSponsorsWidth) / 2);

            $currentX = $startSponsorXInsideBox;

            foreach ($sponsors as $sp) {
                $m = $download_image($sp['photo']);
                $m = safe_thumbnail($m, $maxW, $logoMaxH, $sp['photo'], 'sponsor');
                if (!$m) continue;
                
                $patrocinadoresCanvas->compositeImage($m, Imagick::COMPOSITE_OVER, intval($currentX), intval($currentContentY + ($logosAreaHeight - $m->getImageHeight()) / 2)); 
                $currentX += $maxW + $gapBetweenSponsors;
            }
            error_log("🤝 ".count($sponsors)." patrocinadores en recuadro.");
        }
        $currentContentY += $blockHeight + 10; 

        // Las 2 Fotos Finales
        if (!empty($closingImages) && count($closingImages) >= 2) {
            $imagesAreaHeight = $blockHeight; 
            $imageMaxH = intval($imagesAreaHeight * 0.80);
            
            $imageW = intval($sectionPatrocinadoresW / 2 - 80); 
            $imageH = $imageMaxH;

            $img1 = $download_image($closingImages[0]['photo'] ?? null);
            $img2 = $download_image($closingImages[1]['photo'] ?? null);

            $img1 = safe_thumbnail($img1, $imageW, $imageH, $closingImages[0]['photo'] ?? '', 'closing_image_1');
            $img2 = safe_thumbnail($img2, $imageW, $imageH, $closingImages[1]['photo'] ?? '', 'closing_image_2');

            $totalClosingImagesWidth = ($img1 ? $img1->getImageWidth() : 0) + ($img2 ? $img2->getImageWidth() : 0) + 60; 
            $startClosingImageX = intval(($sectionPatrocinadoresW - $totalClosingImagesWidth) / 2);

            if ($img1) {
                $patrocinadoresCanvas->compositeImage($img1, Imagick::COMPOSITE_OVER, $startClosingImageX, intval($currentContentY + ($imagesAreaHeight - $img1->getImageHeight()) / 2));
            }
            if ($img2) {
                $patrocinadoresCanvas->compositeImage($img2, Imagick::COMPOSITE_OVER, $startClosingImageX + ($img1 ? $img1->getImageWidth() : 0) + 60, intval($currentContentY + ($imagesAreaHeight - $img2->getImageHeight()) / 2));
            }
            error_log("🖼️ 2 imágenes finales agregadas.");
        }

        try {
            $shadow = new Imagick();
            $shadow->readImageBlob($patrocinadoresCanvas->getImageBlob());
            $shadow->shadowImage(80, 3, 0, 0);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($sectionPatrocinadoresX) - 3, intval($sectionPatrocinadoresY) + 3);
        } catch (Exception $e) {
            error_log("⚠️ Sombra no aplicada al recuadro de patrocinadores: ".$e->getMessage());
        }

        $img->compositeImage($patrocinadoresCanvas, Imagick::COMPOSITE_OVER, $sectionPatrocinadoresX, $sectionPatrocinadoresY);
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
        'post_title'=>pathinfo($filename, PATHINFO_FILENAME), 
        'post_status'=>'inherit'
    ], $upload['file']);
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_generate_attachment_metadata($attach_id, $upload['file']);
    $url = wp_get_attachment_url($attach_id);

    error_log("✅ Imagen generada: $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}