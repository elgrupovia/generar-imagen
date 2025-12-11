<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario
 * Description: Plantilla profesional para eventos inmobiliarios corporativos con diseño A4 Proporcional. FIX: Grid 2-3-2-3 forzado para 10 Speakers y ajuste de altura de tarjetas.
 * Version: 2.27.0
 * Author: GrupoVia
 */

if (!defined('ABSPATH')) exit;

error_log('🚀 Iniciando plugin Caratula evento - FIX: Banner Reducido, Grid 2-3-2-3 forzado para 10 Speakers.');

add_action('rest_api_init', function () {
    register_rest_route('imagen/v1', '/generar', [
        'methods' => 'POST',
        'callback' => 'gi_generate_collage_logs',
        'permission_callback' => '__return_true',
    ]);
});

// --- FUNCIONES AUXILIARES (safe_thumbnail, gi_safe_contain_logo, gi_round_corners, gi_word_wrap_text) ---
// *Mantener el código de las funciones auxiliares aquí, ya que no cambian.*

/**
 * Función de redimensionado seguro (Cover logic) - Asegura que la imagen CUBRA la dimensión objetivo (puede cortar los bordes).
 */
function safe_thumbnail($imagick, $w, $h, $url, $context) {
    // ... Código original de safe_thumbnail
    if (!$imagick) return null;

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($w > 0 && $h > 0) {
                $scaleRatio = max($w / $imagick->getImageWidth(), $h / $imagick->getImageHeight());
                $newW = (int)($imagick->getImageWidth() * $scaleRatio);
                $newH = (int)($imagick->getImageHeight() * $scaleRatio);

                $imagick->scaleImage($newW, $newH);

                $x_offset = (int)(($newW - $w) / 2);
                
                // Recorte optimizado para fotos de personas (mantiene el enfoque superior)
                if ($context === 'speaker') { 
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
    // ... Código original de gi_safe_contain_logo
    if (!$imagick) return null;

    try {
        if ($imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0) {
            if ($targetW > 0 && $targetH > 0) {
                // La función de escala MIN asegura que el logo no se corte y respete el targetW x targetH.
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
    // ... Código original de gi_round_corners
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
 * Envuelve el texto a una anchura máxima.
 */
function gi_word_wrap_text($draw, $imagick, $text, $maxWidth) {
    // ... Código original de gi_word_wrap_text
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

// --- FUNCIÓN PRINCIPAL DE GENERACIÓN ---
function gi_generate_collage_logs(WP_REST_Request $request) {
    error_log('🚀 Ejecutando con FIX: Banner Reducido, Grid 2-3-2-3 forzado para 10 Speakers.');

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

    // --- Dimensiones Comunes ---
    $marginLR = intval($W * 0.05); // 80px
    $internalPadding = 30; // 30px
    $shadowMargin = 15; // 15px

    // --- 1. BANNER SUPERIOR (REDUCIDO a 25% H) ---
    $bannerH = intval($H * 0.25); // 600px (en 2400px de altura total)
    $bannerY = 0;
    
    // ... Lógica de composición del Banner
    if ($bannerImageUrl) {
        $bg_image = $download_image($bannerImageUrl);
        if ($bg_image) {
            $bg_image = safe_thumbnail($bg_image, $W, $bannerH, $bannerImageUrl, 'banner_top');
            $img->compositeImage($bg_image, Imagick::COMPOSITE_OVER, 0, $bannerY);
            $bg_image->destroy();
            error_log("🖼️ Banner de imagen de fondo aplicado (Banner 25%).");

        } else {
             $solidBanner = new Imagick();
             $solidBanner->newImage($W, $bannerH, new ImagickPixel('#1a1a1a'));
             $img->compositeImage($solidBanner, Imagick::COMPOSITE_OVER, 0, $bannerY);
             $solidBanner->destroy();
             error_log("⚠️ Fallback: Banner de color sólido aplicado (Banner 25%).");
        }
    }
    
    // ... Lógica de composición del Logo Corporativo
    $logoFileName = 'LOGO_GRUPO_VIA_CMYK_BLANCO.png';
    $logoCorpPath = dirname(__FILE__) . '/' . $logoFileName;
    $logoCorp = null;

    if (file_exists($logoCorpPath)) {
        try {
            $logoCorp = new Imagick($logoCorpPath);
            $logoCorpMaxW = intval($W * 0.15); 
            $logoCorpMaxH = intval($bannerH * 0.18); 
            $logoCorp = gi_safe_contain_logo($logoCorp, $logoCorpMaxW, $logoCorpMaxH, $logoCorpPath, 'corporate_logo');
            
            if ($logoCorp) {
                $logoW = $logoCorp->getImageWidth();
                $logoH = $logoCorp->getImageHeight();
                $logoX = $W - $marginLR - $logoW; 
                $logoY = $internalPadding; 
                
                $img->compositeImage($logoCorp, Imagick::COMPOSITE_OVER, intval($logoX), intval($logoY));
                $logoCorp->destroy();
                error_log("🏢 Logo corporativo compuesto.");
            }
        } catch (Exception $e) {
            error_log("❌ Error cargando/componiendo logo corporativo: " . $e->getMessage());
        }
    } else {
        error_log("⚠️ Logo corporativo no encontrado en la ruta esperada: " . $logoCorpPath);
    }
    // --- FIN LOGO CORPORATIVO ---


    // --- 2. SECCIÓN DE TARJETAS Y SPONSORS (75% H) ---
    $cardsSectionH = $H - $bannerH; 
    $cardsSectionY = $bannerH; 

    // --- 2a. CÁLCULO DE DISTRIBUCIÓN DINÁMICA DE GRID (FIX 10 SPEAKERS) ---
    $maxCols = 3;
    $gridConfig = [];
    $remainingSpeakers = $totalSpeakers;
    
    if ($totalSpeakers === 10) {
        $gridConfig = [2, 3, 2, 3];
        error_log("📐 Distribución FORZADA para 10 speakers: 2-3-2-3");
    } else {
        // Lógica simple (3, 3, 3, 1...) para el resto de casos
        while ($remainingSpeakers > 0) {
            $colsInRow = min($maxCols, $remainingSpeakers);
            $gridConfig[] = $colsInRow;
            $remainingSpeakers -= $colsInRow;
        }
        error_log("📐 Distribución SIMPLE: " . implode('-', array_filter($gridConfig)));
    }


    $gridRows = array_filter($gridConfig);
    $rows = count($gridRows);

    if ($rows > 0) {

        // --- 2a. GRID DE TARJETAS ---
        // Se mantiene el 80% del área inferior para las tarjetas y 20% para sponsors (aproximadamente)
        $gridAreaH = intval($cardsSectionH * 0.80); 
        $gridMarginTB = intval($gridAreaH * 0.03); 
        
        $gridW = $W - 2 * $marginLR; 
        $gridH = $gridAreaH - 2 * $gridMarginTB; 

        $gridXStart = $marginLR; 
        $gapY = intval($gridH * 0.04); 

        // Altura de la tarjeta: Se divide el alto total por el número de filas (Será más pequeña si hay 4 filas)
        $cardH = intval(($gridH - ($rows - 1) * $gapY) / $rows); 
        
        // Ajustamos la altura si hay demasiadas filas para el espacio disponible (ej. si $cardH se vuelve negativo o muy pequeño)
        if ($cardH < 250) { // Valor de seguridad para evitar tarjetas demasiado planas
            $cardH = 250; 
            // Recalcular gridH y gridAreaH para ajustar el espacio de sponsors si es necesario
            $gridH = $rows * $cardH + ($rows - 1) * $gapY;
            $gridAreaH = $gridH + 2 * $gridMarginTB;
            error_log("⚠️ Altura de tarjeta ajustada a $cardH para evitar aplanamientos.");
        }


        // << EFECTO DE ELEVACIÓN MÍNIMA (5%) >>
        $overlapPercentage = 0.05; 
        $overlapAmount = intval($cardH * $overlapPercentage); 
        $gridYStart = $bannerH - $overlapAmount; 

        
        // --- Dimensiones Internas Comunes para Speakers (Se adaptarán a la nueva $cardH) ---
        $photoSizeMax = intval($gridW / 3 * 0.70); // Foto CUADRADA, basada en un ancho de 3 cols
        $photoMarginTop = intval($cardH * 0.05); 
        
        // Reducimos el tamaño de la foto si la nueva altura de tarjeta es muy baja
        $photoMaxHForNewCard = $cardH - $photoMarginTop - 25 - 10 - 10 - 20 - 15 - 15; // Aprox. Altura disponible
        $photoSize = min($photoSizeMax, intval($photoMaxHForNewCard * 0.50)); // Foto toma máx. 50% de la altura disponible
        
        $nameFontSize = 40; 
        $roleFontSize = 20; // Se mantiene en 20px, como en el FIX anterior
        $speakerPhotoCornerRadius = 20; 


        $index = 0;
        $currentGridY = $gridYStart;
        
        foreach ($gridRows as $r => $colsInRow) {
            $baseY = $currentGridY;
            
            // Recalcular dimensiones de la tarjeta para esta fila (principalmente ancho y espaciado X)
            $cols = $colsInRow; // Número de columnas para esta fila
            $gapX = intval($W * 0.03); 
            $cardW = intval(($gridW - ($cols - 1) * $gapX) / $cols); 
            
            // Recalcular elementos internos con el nuevo ancho de tarjeta (especialmente la foto)
            // Aquí usamos el $photoSize ya calculado y ajustado en función de la altura.
            $speakerLogoAreaW = $cardW - $internalPadding * 2; 

            // Se calcula el espacio total ocupado por las tarjetas y gaps en la fila
            $rowWidth = $cols * $cardW + ($cols - 1) * $gapX;
            // Se calcula el offset para centrar la fila
            $rowXOffset = ($gridW - $rowWidth) / 2;
            
            // Iteración sobre las columnas de la fila actual
            for ($c = 0; $c < $cols; $c++) {
                if ($index >= $totalSpeakers) break 2;
                
                $sp = $speakers[$index++] ?? null;
                if (!$sp) continue;

                // 1. Crear el fondo BLANCO y redondear esquinas
                $cardCanvas = new Imagick();
                $cardCanvas->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
                $cardCanvas->setImageFormat('png');
                $cornerRadius = 20; 
                $cardCanvas = gi_round_corners($cardCanvas, $cornerRadius);

                // 2. Crear la sombra y 3. Contenedor final
                $shadowBase = clone $cardCanvas;
                $shadowBase->setImageBackgroundColor(new ImagickPixel('rgba(0, 0, 0, 0)')); 
                $shadowBase->shadowImage(80, 5, 0, 0); 

                $cardContainer = new Imagick();
                $cardContainer->newImage($cardW + $shadowMargin*2, $cardH + $shadowMargin*2, new ImagickPixel('transparent'));
                $cardContainer->setImageFormat('png');

                $cardContainer->compositeImage($shadowBase, Imagick::COMPOSITE_OVER, 0, 0); 
                $shadowBase->destroy();
                $cardContainer->compositeImage($cardCanvas, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
                $cardCanvas->destroy();
                $cardCanvas = $cardContainer; 
                
                // Calculo de posición para el lienzo principal
                $x = $gridXStart + $rowXOffset + $c * ($cardW + $gapX) - $shadowMargin; 
                $y = $baseY - $shadowMargin;
                
                // --- CONTENIDO INTERNO DE LA TARJETA ---
                $internalContentCanvas = new Imagick();
                $internalContentCanvas->newImage($cardW, $cardH, new ImagickPixel('transparent'));
                $internalContentCanvas->setImageFormat('png');

                $currentY = $photoMarginTop; 
                
                // 📷 Foto Cuadrada con Esquinas Redondeadas (usando el $photoSize ajustado)
                $photoUrl = $sp['photo'] ?? null;
                $photoBase = $download_image($photoUrl);

                if ($photoBase) {
                    $photoBase = safe_thumbnail($photoBase, $photoSize, $photoSize, $photoUrl, 'speaker');
                    if ($photoBase) {
                        $photoBase = gi_round_corners($photoBase, $speakerPhotoCornerRadius); 
                        if ($photoBase) {
                            $photoX = ($cardW - $photoSize) / 2;
                            $internalContentCanvas->compositeImage($photoBase, Imagick::COMPOSITE_OVER, intval($photoX), intval($currentY));
                            $photoBase->destroy();
                            $currentY += $photoSize + 25; 
                        }
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
                
                $metricsName = $internalContentCanvas->queryFontMetrics($drawName, $name);
                $nameY = $currentY + $metricsName['textHeight'] / 2;
                $internalContentCanvas->annotateImage($drawName, $cardW / 2, $nameY, 0, $name);
                $currentY += $metricsName['textHeight'] + 10; 

                // ✍️ Rol (Ocupación)
                $drawRole = new ImagickDraw();
                if (file_exists($fontPath)) $drawRole->setFont($fontPath);
                $drawRole->setFillColor('#555555'); 
                $drawRole->setFontSize($roleFontSize); 
                $drawRole->setFontWeight(600);
                $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                $role = trim($sp['role'] ?? 'Cargo en la Empresa');
                
                $roleLines = gi_word_wrap_text($drawRole, $internalContentCanvas, $role, $cardW - $internalPadding * 2);
                $lineHeight = $roleFontSize + 5; 
                
                foreach ($roleLines as $i => $line) {
                    $internalContentCanvas->annotateImage($drawRole, $cardW / 2, $currentY + ($i * $lineHeight), 0, $line);
                }
                $currentY += count($roleLines) * $lineHeight + 10; 

                
                // 🏢 Logo de la Empresa (Escalado al Máximo Disponible)
                $logoUrl = $sp['logo'] ?? null;
                $logoBase = $download_image($logoUrl);

                if ($logoBase) {
                    $logoAreaH = $cardH - $currentY - 15; 
                    
                    if ($logoAreaH > 10) { 
                        $logoBase = gi_safe_contain_logo($logoBase, $speakerLogoAreaW, $logoAreaH, $logoUrl, 'speaker_logo');
                        
                        if ($logoBase) {
                            $logoW = $logoBase->getImageWidth();
                            $logoH = $logoBase->getImageHeight();
                            
                            $totalRemainingSpace = $cardH - $currentY;
                            $logoY = $currentY + ($totalRemainingSpace - $logoH) / 2; 
                            
                            $logoX = ($cardW - $logoW) / 2;
                            $internalContentCanvas->compositeImage($logoBase, Imagick::COMPOSITE_OVER, intval($logoX), intval($logoY));
                            $logoBase->destroy();
                        }
                    }
                }

                // 6. Componer el contenido en el contenedor BLANCO/Sombra
                $cardCanvas->compositeImage($internalContentCanvas, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
                $internalContentCanvas->destroy();

                // 7. Componer la tarjeta completa en el lienzo principal
                $img->compositeImage($cardCanvas, Imagick::COMPOSITE_OVER, intval($x), intval($y));
                $cardCanvas->destroy();
            }
            // Mover la posición Y para la siguiente fila
            $currentGridY = $baseY + $cardH + $gapY;

        }
        error_log("🎤 Grid de tarjetas generado. Distribución final: " . implode('-', $gridRows));
    }
    
    // --- 2b. BARRA DE SPONSORS ---

    // 1. Calcular posición Y: Justo después de la última fila de speakers + un gap
    if ($rows > 0) {
        $lastRowYEnd = $gridYStart + ($rows - 1) * ($cardH + $gapY) + $cardH;
    } else {
        $lastRowYEnd = $cardsSectionY + $internalPadding; 
    }
    
    $gapBeforeSponsors = intval($W * 0.04); 
    $sponsorCardYStart = $lastRowYEnd + $gapBeforeSponsors; 

    // 2. Dimensiones
    $sponsorCardW = $W - 2 * $marginLR; 
    $sponsorCardH = 200; 
    
    $cardX = $marginLR - $shadowMargin;
    $cardY = $sponsorCardYStart - $shadowMargin;
    $cornerRadius = 20;
    
    // ... (Lógica de composición de la tarjeta de Sponsors: Sombra, Tarjeta Blanca)
    $patrocinadoresCard = new Imagick();
    $patrocinadoresCard->newImage($sponsorCardW, $sponsorCardH, new ImagickPixel('#FFFFFF'));
    $patrocinadoresCard->setImageFormat('png');
    $patrocinadoresCard = gi_round_corners($patrocinadoresCard, $cornerRadius);

    $shadowBase = clone $patrocinadoresCard;
    $shadowBase->setImageBackgroundColor(new ImagickPixel('rgba(0, 0, 0, 0)')); 
    $shadowBase->shadowImage(80, 5, 0, 0); 

    $cardContainer = new Imagick();
    $cardContainer->newImage($sponsorCardW + $shadowMargin*2, $sponsorCardH + $shadowMargin*2, new ImagickPixel('transparent'));
    $cardContainer->setImageFormat('png');

    $cardContainer->compositeImage($shadowBase, Imagick::COMPOSITE_OVER, 0, 0); 
    $shadowBase->destroy();
    $cardContainer->compositeImage($patrocinadoresCard, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
    $patrocinadoresCard->destroy();
    $sponsorCanvas = $cardContainer; 

    // --- 2.2. CONTENIDO INTERNO: Solo Logos (Grandes) ---
    $contentCanvas = new Imagick();
    $contentCanvas->newImage($sponsorCardW, $sponsorCardH, new ImagickPixel('transparent'));
    $contentCanvas->setImageFormat('png');

    $sponsorLogos = array_merge($payload['logos'] ?? [], $payload['sponsors'] ?? []);
    
    $logosYStart = $internalPadding; 
    $logosAreaH = $sponsorCardH - 2 * $internalPadding; 
    $logosAreaW = $sponsorCardW - 2 * $internalPadding;

    $logoMaxH = $logosAreaH; 
    $logoSpacing = 40; 
    
    $logosToCompose = [];
    $currentXWidth = 0; 
    $logoCount = 0; 

    foreach ($sponsorLogos as $logoData) {
        $logoUrl = $logoData['photo'] ?? null;
        if (!$logoUrl) continue;

        $logoBase = $download_image($logoUrl);
        if ($logoBase) {
            $logoBase = gi_safe_contain_logo($logoBase, $logosAreaW, $logoMaxH, $logoUrl, 'sponsor_logo');
            if ($logoBase) {
                $logoW = $logoBase->getImageWidth();
                
                if ($currentXWidth + $logoW + ($logoCount > 0 ? $logoSpacing : 0) <= $logosAreaW) {
                    $logosToCompose[] = $logoBase;
                    $currentXWidth += $logoW + ($logoCount > 0 ? $logoSpacing : 0);
                    $logoCount++;
                } else {
                    $logoBase->destroy();
                }
            }
        }
    }
    
    if (!empty($logosToCompose)) {
        $currentXWidth -= ($logoCount > 0 ? $logoSpacing : 0); 
        $remainingSpaceInArea = $logosAreaW - $currentXWidth;
        
        $currentX = $internalPadding + ($remainingSpaceInArea / 2); 
        
        foreach ($logosToCompose as $logoBase) {
            $logoW = $logoBase->getImageWidth();
            $logoH = $logoBase->getImageHeight();
            
            $logoY = $logosYStart + ($logosAreaH - $logoH) / 2; 
            
            $contentCanvas->compositeImage($logoBase, Imagick::COMPOSITE_OVER, intval($currentX), intval($logoY));
            $logoBase->destroy();
            
            $currentX += $logoW + $logoSpacing;
        }
        error_log("⭐ Tarjeta de patrocinadores horizontal generada ($logoCount logos grandes).");
    } else {
        error_log("⚠️ No hay logos válidos para generar la tarjeta de sponsors.");
    }
    
    // 3. Componer Contenido en la Tarjeta
    $sponsorCanvas->compositeImage($contentCanvas, Imagick::COMPOSITE_OVER, $shadowMargin, $shadowMargin);
    $contentCanvas->destroy();

    // 4. Componer la tarjeta completa en el lienzo principal
    $img->compositeImage($sponsorCanvas, Imagick::COMPOSITE_OVER, intval($cardX), intval($cardY));
    $sponsorCanvas->destroy();


    // 📤 Exportar
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'evento_a4').'_final_v_dinamica_v2-2-3-2-3.'.$format);

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

    error_log("✅ Imagen generada (Diseño A4 Dinámico 2-3-2-3): $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}