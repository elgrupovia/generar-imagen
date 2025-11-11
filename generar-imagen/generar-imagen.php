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
 * Función de redimensionado seguro. Mantenemos el thumbnailImage original,
 * pero usaremos lógica de coverImage en la función principal para el fondo.
 */
function safe_thumbnail($imagick, $w, $h, $url, $context) {
    try {
        if ($imagick && $imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
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
            error_log("⚠️ Imagen inválida en $context: $url");
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

    // 🖼️ Crear lienzo base con fondo que COBRE TODO
    $img = new Imagick();
    if (filter_var($bg, FILTER_VALIDATE_URL)) {
        $bg_image = new Imagick();
        try {
            $bg_image->readImage($bg);

            if ($bg_image->getImageWidth() > 0 && $bg_image->getImageHeight() > 0) {
                // Escalar para cubrir todo (cover logic)
                $scaleRatio = max($W / $bg_image->getImageWidth(), $H / $bg_image->getImageHeight());
                $newW = (int)($bg_image->getImageWidth() * $scaleRatio);
                $newH = (int)($bg_image->getImageHeight() * $scaleRatio);

                $bg_image->scaleImage($newW, $newH);

                // Recortar si es necesario
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

    // 📐 Zonas de diseño (REAJUSTADO para Altura de Patrocinadores = Altura de Ponentes)
    $headerStart = 0;
    $headerEnd = intval($H * 0.15);
    $eventInfoStart = $headerEnd;
    $eventInfoEnd = intval($H * 0.22);
    $speakersStart = $eventInfoEnd;
    $speakersEnd = intval($H * 0.65); // Final de la zona de speakers (65% de la altura total)
    
    // Altura del lienzo hasta donde terminaba Patrocinadores en el código anterior (H * 0.95)
    $finalAreaEnd = intval($H * 0.95); 
    
    // Gaps (separación entre speakers/ponentes, ponentes/patrocinadores)
    $gapSize = 20; 
    $totalGapsBetweenBoxes = $gapSize * 2; // Dos gaps entre los tres elementos (Speakers/Ponentes, Ponentes/Patrocinadores)

    // Altura total disponible para los dos recuadros (Ponentes y Patrocinadores)
    $availableHeightForBoxes = $finalAreaEnd - $speakersEnd - $totalGapsBetweenBoxes;
    
    // Altura exacta que deben tener ambos rectángulos para que sean IDÉNTICOS
    $equalBoxHeight = intval($availableHeightForBoxes / 2); 
    
    // --- DEFINICIÓN DE ZONAS CON ALTURA IGUALADA Y GRANDE ---
    
    // 1. Zona Ponentes (Rectángulo con título arriba)
    $sectionPonentesStart = $speakersEnd + $gapSize; 
    $sectionPonentesEnd = $sectionPonentesStart + $equalBoxHeight; 
    
    // 2. Zona Patrocinadores (Rectángulo con título arriba y fotos)
    $sectionPatrocinadoresStart = $sectionPonentesEnd + $gapSize; 
    $sectionPatrocinadoresEnd = $sectionPatrocinadoresStart + $equalBoxHeight; 
    
    // Corrección por posibles errores de redondeo de 1px
    if ($sectionPatrocinadoresEnd > $finalAreaEnd) {
        $sectionPatrocinadoresEnd = $finalAreaEnd;
        $sectionPonentesEnd = $sectionPatrocinadoresStart - $gapSize;
        $equalBoxHeight = $sectionPonentesEnd - $sectionPonentesStart;
    }
    // Fin del ajuste de zonas. Ambos recuadros tendrán la misma altura ($equalBoxHeight).


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

    // ✨ Logo superior derecho
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

    // 👤 Speakers con recuadros redondeados (Ajuste para alineación)
    $speakers = $payload['speakers'] ?? [];
    if (!empty($speakers)) {
        error_log("🎤 Procesando ".count($speakers)." speakers");

        $totalSpeakers = count($speakers);
        $cols = 3;
        $rows = ceil($totalSpeakers / $cols);
        
        // Ajustes para hacer los speakers más pequeños y el texto dentro
        $photoW = intval($W / 4.5); 
        $photoH = intval($photoW * 1.2); // Altura total del recuadro del speaker (foto + texto)
        $gapX = 50; 
        $gapY = 60; 
        $textHeightInternal = intval($photoH * 0.3); // Altura para el fondo blanco con texto
        $photoImageHeight = $photoH - $textHeightInternal; // Altura real de la imagen de la persona

        $availableHeight = $speakersEnd - $speakersStart;
        $totalHeight = $rows * $photoH + ($rows - 1) * $gapY;
        $startY = $speakersStart + intval(($availableHeight - $totalHeight) / 2);

        $index = 0;
        for ($r = 0; $r < $rows; $r++) {
            $y = $startY + $r * ($photoH + $gapY); // y usa la altura total del recuadro
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
                
                // Usamos safe_thumbnail con ancho y alto fijos para forzar la cobertura exacta
                $photoBase = safe_thumbnail($photoBase, $photoW, $photoImageHeight, $photoUrl, 'speaker');
                if (!$photoBase) continue;

                // Crear un nuevo lienzo para el speaker con el tamaño total ($photoW, $photoH)
                $speakerCanvas = new Imagick();
                $speakerCanvas->newImage($photoW, $photoH, new ImagickPixel('transparent'));
                $speakerCanvas->setImageFormat('png');

                // Componer la imagen del speaker en la parte superior del canvas del speaker
                $speakerCanvas->compositeImage($photoBase, Imagick::COMPOSITE_OVER, 0, 0);

                // Crear el fondo blanco para el nombre y cargo
                $whiteBg = new Imagick();
                // El fondo blanco DEBE tener el ancho exacto $photoW
                $whiteBg->newImage($photoW, $textHeightInternal, new ImagickPixel('#FFFFFF'));
                $whiteBg->setImageFormat('png');
                $speakerCanvas->compositeImage($whiteBg, Imagick::COMPOSITE_OVER, 0, $photoImageHeight); // Posicionar debajo de la foto

                // Dibujar texto (nombre y cargo) encima del fondo blanco
                try {
                    $draw = new ImagickDraw();
                    if (file_exists($fontPath)) $draw->setFont($fontPath);
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->setFillColor('#000000'); // Texto oscuro sobre fondo blanco
                    $draw->setFontSize(32); 
                    $draw->setFontWeight(900);

                    $centerX = $photoW / 2;
                    // Ajuste vertical para centrar el texto en el fondo blanco
                    $nameY = $photoImageHeight + intval($textHeightInternal / 2) - 15; 

                    if ($name) {
                        $speakerCanvas->annotateImage($draw, $centerX, $nameY, 0, $name);
                    }

                    if ($role) {
                        $drawRole = new ImagickDraw();
                        if (file_exists($fontPath)) $drawRole->setFont($fontPath);
                        $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                        $drawRole->setFillColor('#555555'); 
                        $drawRole->setFontSize(22); 
                        $drawRole->setFontWeight(600);
                        $speakerCanvas->annotateImage($drawRole, $centerX, $nameY + 30, 0, $role);
                    }
                } catch (Exception $e) {
                    error_log("💥 Error texto en speaker canvas: ".$e->getMessage());
                }

                // Redondear esquinas del speakerCanvas completo
                $cornerRadius = 30; 
                $speakerCanvas = gi_round_corners($speakerCanvas, $cornerRadius);
                if (!$speakerCanvas) continue; 
                 
                // Sombra suave (aplicada al speakerCanvas completo)
                try {
                    $shadow = new Imagick();
                    $shadow->readImageBlob($speakerCanvas->getImageBlob());
                    $shadow->shadowImage(80, 3, 0, 0);
                    $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($x) - 3, intval($y) + 3);
                } catch (Exception $e) {
                    error_log("⚠️ Sombra no aplicada: ".$e->getMessage());
                }
                
                // Componer el speakerCanvas completo en la imagen principal
                $img->compositeImage($speakerCanvas, Imagick::COMPOSITE_OVER, intval($x), intval($y));

                $x += $photoW + $gapX;
            }
        }
        error_log("🎤 Grid: $rows filas x $cols columnas");
    }

    // 🏷️ Sección de Ponentes (Rectángulo blanco redondeado con altura igualada)
    $logos = $payload['logos'] ?? [];
    if (!empty($logos)) {
        $sectionPonentesW = $W - 80; // Ancho del recuadro (con 40px de margen a cada lado)
        // Usamos $equalBoxHeight que se calculó para ser idéntica a la de patrocinadores
        $sectionPonentesH = $equalBoxHeight; 
        $sectionPonentesX = ($W - $sectionPonentesW) / 2;
        $sectionPonentesY = $sectionPonentesStart;

        // Crear el lienzo para la sección de ponentes
        $ponPonentessCanvas = new Imagick();
        $ponPonentessCanvas->newImage($sectionPonentesW, $sectionPonentesH, new ImagickPixel('#FFFFFF'));
        $ponPonentessCanvas->setImageFormat('png');

        // Redondear las esquinas del recuadro de ponentes
        $cornerRadius = 30; 
        $ponPonentessCanvas = gi_round_corners($ponPonentessCanvas, $cornerRadius);
        if (!$ponPonentessCanvas) {
            error_log("❌ No se pudo redondear el canvas de ponentes.");
            return new WP_REST_Response(['error'=>'Failed to round corners for ponentes section'], 500);
        }

        // Título "Ponentes:"
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#000000');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        
        // Posicionar el título "Ponentes:" centrado en la parte superior del recuadro
        $titlePonentesY = 40; // Desde el top del canvas de ponentes
        $ponPonentessCanvas->annotateImage($draw, $sectionPonentesW / 2, $titlePonentesY, 0, 'Ponentes:');

        // Calcular la zona de los logos dentro del recuadro
        $logosAreaTop = $titlePonentesY + 30; // Debajo del título
        $logosAreaHeight = $sectionPonentesH - $logosAreaTop - 20; // Altura restante para logos, con un margen inferior
        $logoMaxH = intval($logosAreaHeight * 0.80); // Máx altura para los logos dentro del recuadro
        
        $totalLogosInRow = count($logos);
        $gapBetweenLogos = 40; 
        $horizontalPadding = 60; // Padding horizontal dentro del recuadro para los logos

        $availableLogosWidth = $sectionPonentesW - ($horizontalPadding * 2);
        $calculatedMaxW = ($availableLogosWidth - ($totalLogosInRow - 1) * $gapBetweenLogos) / $totalLogosInRow;
        $maxW = min(180, (int)$calculatedMaxW); 

        // Calcular el ancho total que ocuparán los logos para centrarlos
        $actualLogosWidth = $totalLogosInRow * $maxW + ($totalLogosInRow - 1) * $gapBetweenLogos;
        $startLogoXInsideBox = $horizontalPadding + intval(($availableLogosWidth - $actualLogosWidth) / 2);

        $currentX = $startLogoXInsideBox;

        foreach ($logos as $logo) {
            $m = $download_image($logo['photo']);
            $m = safe_thumbnail($m, $maxW, $logoMaxH, $logo['photo'], 'logo');
            if (!$m) continue;
            
            // Componer el logo en el canvas de la sección de ponentes
            $ponPonentessCanvas->compositeImage($m, Imagick::COMPOSITE_OVER, intval($currentX), intval($logosAreaTop + ($logosAreaHeight - $m->getImageHeight()) / 2));
            $currentX += $maxW + $gapBetweenLogos;
        }

        // Sombra suave para el recuadro de ponentes
        try {
            $shadow = new Imagick();
            $shadow->readImageBlob($ponPonentessCanvas->getImageBlob());
            $shadow->shadowImage(80, 3, 0, 0);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($sectionPonentesX) - 3, intval($sectionPonentesY) + 3);
        } catch (Exception $e) {
            error_log("⚠️ Sombra no aplicada al recuadro de ponentes: ".$e->getMessage());
        }

        // Componer el recuadro de ponentes completo en la imagen principal
        $img->compositeImage($ponPonentessCanvas, Imagick::COMPOSITE_OVER, $sectionPonentesX, $sectionPonentesY);
        error_log("💼 ".count($logos)." logos ponentes en recuadro redondeado con título encima.");
    }

    // 🤝 Sección de Patrocinadores (Rectángulo blanco redondeado con altura igualada)
    $sponsors = $payload['sponsors'] ?? [];
    $closingImages = $payload['closing_images'] ?? []; 
    
    if (!empty($sponsors) || !empty($closingImages)) {
        $sectionPatrocinadoresW = $W - 80; 
        // Usamos $equalBoxHeight que se calculó para ser idéntica a la de ponentes
        $sectionPatrocinadoresH = $equalBoxHeight; 
        $sectionPatrocinadoresX = ($W - $sectionPatrocinadoresW) / 2;
        $sectionPatrocinadoresY = $sectionPatrocinadoresStart;

        $patrocinadoresCanvas = new Imagick();
        $patrocinadoresCanvas->newImage($sectionPatrocinadoresW, $sectionPatrocinadoresH, new ImagickPixel('#FFFFFF'));
        $patrocinadoresCanvas->setImageFormat('png');

        // Redondear las esquinas del recuadro de patrocinadores
        $cornerRadius = 30; 
        $patrocinadoresCanvas = gi_round_corners($patrocinadoresCanvas, $cornerRadius);
        if (!$patrocinadoresCanvas) {
            error_log("❌ No se pudo redondear el canvas de patrocinadores.");
            return new WP_REST_Response(['error'=>'Failed to round corners for patrocinadores section'], 500);
        }

        $currentContentY = 40; // Empieza el contenido dentro del canvas de patrocinadores

        // Título "Patrocina:"
        $draw = new ImagickDraw();
        if (file_exists($fontPath)) $draw->setFont($fontPath);
        $draw->setFillColor('#000000');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $patrocinadoresCanvas->annotateImage($draw, $sectionPatrocinadoresW / 2, $currentContentY, 0, 'Patrocina:');
        $currentContentY += 60; // Espacio después del título

        // Calcular el espacio disponible para Logos y para Imágenes, de forma más equitativa
        $remainingHeight = $sectionPatrocinadoresH - $currentContentY - 20; // Altura total disponible para ambos bloques
        $blockHeight = intval($remainingHeight / 2); // Cada bloque (logos y fotos) ocupa la mitad

        // Logos de Patrocinadores
        if (!empty($sponsors)) {
            $logosAreaHeight = $blockHeight; // Altura fija para los logos
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
                
                // Posicionar verticalmente centrado en el blockHeight
                $patrocinadoresCanvas->compositeImage($m, Imagick::COMPOSITE_OVER, intval($currentX), intval($currentContentY + ($logosAreaHeight - $m->getImageHeight()) / 2)); 
                $currentX += $maxW + $gapBetweenSponsors;
            }
            error_log("🤝 ".count($sponsors)." patrocinadores en recuadro.");
        }
        $currentContentY += $blockHeight + 10; // Mover hacia abajo, con un pequeño gap

        // Las 2 Fotos Finales
        if (!empty($closingImages) && count($closingImages) >= 2) {
            $imagesAreaHeight = $blockHeight; // Altura fija para las imágenes
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
                // Posicionar verticalmente centrado en el blockHeight
                $patrocinadoresCanvas->compositeImage($img1, Imagick::COMPOSITE_OVER, $startClosingImageX, intval($currentContentY + ($imagesAreaHeight - $img1->getImageHeight()) / 2));
            }
            if ($img2) {
                // Posicionar verticalmente centrado en el blockHeight
                $patrocinadoresCanvas->compositeImage($img2, Imagick::COMPOSITE_OVER, $startClosingImageX + ($img1 ? $img1->getImageWidth() : 0) + 60, intval($currentContentY + ($imagesAreaHeight - $img2->getImageHeight()) / 2));
            }
            error_log("🖼️ 2 imágenes finales agregadas.");
        }

        // Sombra suave para el recuadro de patrocinadores
        try {
            $shadow = new Imagick();
            $shadow->readImageBlob($patrocinadoresCanvas->getImageBlob());
            $shadow->shadowImage(80, 3, 0, 0);
            $img->compositeImage($shadow, Imagick::COMPOSITE_OVER, intval($sectionPatrocinadoresX) - 3, intval($sectionPatrocinadoresY) + 3);
        } catch (Exception $e) {
            error_log("⚠️ Sombra no aplicada al recuadro de patrocinadores: ".$e->getMessage());
        }

        // Componer el recuadro de patrocinadores completo en la imagen principal
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