<?php
/**
 * Plugin Name: Generar Collage Evento Inmobiliario
 * Description: Plantilla profesional para eventos inmobiliarios corporativos con diseño moderno
 * Version: 2.1.0
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
 * Función de redimensionado seguro (Cover logic) - Asegura que la imagen CUBRA la dimensión objetivo (puede cortar los bordes).
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
    error_log('🚀 Iniciando plugin Evento Inmobiliario Pro - Nuevo Diseño');

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
    $bg = $payload['canvas']['background'] ?? '#f0f0f0'; // Fondo por defecto más claro para el nuevo diseño

    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $montserratBlackPath = $base_dir . '/fonts/Montserrat-Black.ttf';
    $fontPath = file_exists($montserratBlackPath) ? $montserratBlackPath : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';


    // 🖼️ Crear lienzo base con fondo que COBRE TODO
    $img = new Imagick();
    $img->newImage($W, $H, new ImagickPixel($bg));
    $img->setImageFormat('png');


    // 🔽 Descargar imágenes (CON USER-AGENT AGREGADO)
    $download_image = function(string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
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

    // --- 1. BANNER SUPERIOR (100% ANCHO) ---
    $bannerH = intval($H * 0.25);
    $bannerY = 0;
    
    // 🖼️ Imagen de fondo del banner
    $bannerImageUrl = $payload['banner_image']['photo'] ?? null;
    $bannerTitle = $payload['banner_title'] ?? 'Evento Corporativo Inmobiliario'; 

    if ($bannerImageUrl) {
        $bg_image = $download_image($bannerImageUrl);
        if ($bg_image) {
            $bg_image = safe_thumbnail($bg_image, $W, $bannerH, $bannerImageUrl, 'banner_top');
            
            // Capa negra semi-transparente para mejorar la legibilidad del texto
            $overlay = new Imagick();
            $overlay->newImage($W, $bannerH, new ImagickPixel('rgba(0,0,0,0.55)')); 
            $bg_image->compositeImage($overlay, Imagick::COMPOSITE_OVER, 0, 0);
            $overlay->destroy();
            
            $img->compositeImage($bg_image, Imagick::COMPOSITE_OVER, 0, $bannerY);
            $bg_image->destroy();
            error_log("🖼️ Banner de imagen de fondo aplicado.");
        } else {
             // Fallback de color sólido si la URL falla
             $solidBanner = new Imagick();
             $solidBanner->newImage($W, $bannerH, new ImagickPixel('#1a1a1a'));
             $img->compositeImage($solidBanner, Imagick::COMPOSITE_OVER, 0, $bannerY);
             $solidBanner->destroy();
             error_log("⚠️ Fallback: Banner de color sólido aplicado.");
        }
    } else {
         // Fallback de color sólido si no hay URL
         $solidBanner = new Imagick();
         $solidBanner->newImage($W, $bannerH, new ImagickPixel('#1a1a1a'));
         $img->compositeImage($solidBanner, Imagick::COMPOSITE_OVER, 0, $bannerY);
         $solidBanner->destroy();
         error_log("⚠️ Fallback: Banner de color sólido aplicado (sin URL).");
    }

    // ✍️ Texto del Banner (Título principal)
    $drawTitle = new ImagickDraw();
    if (file_exists($fontPath)) $drawTitle->setFont($fontPath);
    $drawTitle->setFillColor('#FFFFFF');
    $drawTitle->setFontSize(70); 
    $drawTitle->setFontWeight(900);
    $drawTitle->setTextAlignment(Imagick::ALIGN_CENTER);

    $metricsTitle = $img->queryFontMetrics($drawTitle, $bannerTitle);
    $titleY = $bannerY + ($bannerH / 2) + ($metricsTitle['textHeight'] / 2) - 10;
    $img->annotateImage($drawTitle, $W / 2, $titleY, 0, $bannerTitle);
    error_log("✍️ Título del banner superpuesto: $bannerTitle");


    // 📅 Detalles del evento (Justo debajo del banner)
    $eventDetails = $payload['event_details'] ?? '6 NOVIEMBRE 2026 | 9:00H | SILKEN PUERTA VALENCIA';
    $detailsH = 80;
    $detailsY = $bannerY + $bannerH;

    $detailsCanvas = new Imagick();
    $detailsCanvas->newImage($W, $detailsH, new ImagickPixel('#333333')); // Fondo gris oscuro
    $detailsCanvas->setImageFormat('png');

    $drawDetails = new ImagickDraw();
    if (file_exists($fontPath)) $drawDetails->setFont($fontPath);
    $drawDetails->setFillColor('#FFFFFF');
    $drawDetails->setFontSize(35); 
    $drawDetails->setFontWeight(600);
    $drawDetails->setTextAlignment(Imagick::ALIGN_CENTER);

    $metricsDetails = $detailsCanvas->queryFontMetrics($drawDetails, $eventDetails);
    $detailsTextY = ($detailsH / 2) + ($metricsDetails['textHeight'] / 2);
    $detailsCanvas->annotateImage($drawDetails, $W / 2, $detailsTextY, 0, $eventDetails); 
    
    $img->compositeImage($detailsCanvas, Imagick::COMPOSITE_OVER, 0, $detailsY);
    $detailsCanvas->destroy();
    error_log("📅 Detalles del evento añadidos: $eventDetails");
    
    // --- 2. GRID DE TARJETAS (2x3) ---

    $speakers = $payload['speakers'] ?? [];
    $totalSpeakers = count($speakers);
    
    if ($totalSpeakers > 0) {
        $cols = 3;
        $rows = 2; // Fijo 2 filas
        $maxSpeakers = $cols * $rows; 
        
        $gridYStart = $detailsY + $detailsH + 40; // Comienza después de los detalles
        $gridYEnd = $H - 40; // 40px de margen inferior

        $gridW = intval($W * 0.90); // Ancho del 90%
        $gridX = ($W - $gridW) / 2;
        $gridH = $gridYEnd - $gridYStart;

        $gapX = 40; // Espaciado horizontal
        $gapY = 50; // Espaciado vertical

        $cardW = intval(($gridW - ($cols - 1) * $gapX) / $cols);
        $cardH = intval(($gridH - ($rows - 1) * $gapY) / $rows);
        
        // Dimensiones internas de la tarjeta
        $photoDiameter = intval($cardW * 0.40); // 40% del ancho de la tarjeta
        $logoH = 40; // Altura fija para el logo
        $logoW = intval($cardW * 0.70); // Ancho máximo para el logo
        
        $nameFontSize = 28;
        $roleFontSize = 20;

        $index = 0;
        for ($r = 0; $r < $rows; $r++) {
            $y = $gridYStart + $r * ($cardH + $gapY);
            for ($c = 0; $c < $cols; $c++) {
                if ($index >= $totalSpeakers || $index >= $maxSpeakers) break 2; // Salir de ambos bucles
                
                $sp = $speakers[$index++] ?? null;
                if (!$sp) continue;

                $cardCanvas = new Imagick();
                $cardCanvas->newImage($cardW, $cardH, new ImagickPixel('#FFFFFF'));
                $cardCanvas->setImageFormat('png');
                
                // 🖌️ Redondear esquinas y aplicar sombra
                $cornerRadius = 20; 
                $cardCanvas = gi_round_corners($cardCanvas, $cornerRadius);
                $cardCanvas->setImageBackgroundColor(new ImagickPixel('rgba(0, 0, 0, 0)'));
                $cardCanvas->shadowImage(80, 5, 0, 0); // Radio, sigma, x-offset, y-offset

                $x = $gridX + $c * ($cardW + $gapX);
                
                $currentY = 30; // Margen superior interno
                
                // 📷 Foto Circular
                $photoUrl = $sp['photo'] ?? null;
                $photoBase = $download_image($photoUrl);

                if ($photoBase) {
                    $photoBase = safe_thumbnail($photoBase, $photoDiameter, $photoDiameter, $photoUrl, 'speaker_circular');
                    if ($photoBase) {
                        $photoBase = gi_circular_mask($photoBase);
                        $photoX = ($cardW - $photoDiameter) / 2;
                        $cardCanvas->compositeImage($photoBase, Imagick::COMPOSITE_OVER, intval($photoX), $currentY);
                        $photoBase->destroy();
                        $currentY += $photoDiameter + 20; 
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
                
                $metricsName = $cardCanvas->queryFontMetrics($drawName, $name);
                $nameY = $currentY + $metricsName['textHeight'] / 2;
                $cardCanvas->annotateImage($drawName, $cardW / 2, $nameY, 0, $name);
                $currentY += $metricsName['textHeight'] + 10; 
                
                // ✍️ Rol (Ocupación)
                $drawRole = new ImagickDraw();
                if (file_exists($fontPath)) $drawRole->setFont($fontPath);
                $drawRole->setFillColor('#555555'); 
                $drawRole->setFontSize($roleFontSize); 
                $drawRole->setFontWeight(600);
                $drawRole->setTextAlignment(Imagick::ALIGN_CENTER);
                $role = trim($sp['role'] ?? 'Cargo en la Empresa');
                
                $roleLines = gi_word_wrap_text($drawRole, $cardCanvas, $role, $cardW - 40);
                $lineHeight = 25; 
                
                foreach ($roleLines as $i => $line) {
                    $cardCanvas->annotateImage($drawRole, $cardW / 2, $currentY + ($i * $lineHeight), 0, $line);
                }
                $currentY += count($roleLines) * $lineHeight + 15; 
                
                
                // 🏢 Logo de Empresa (Patrocinador/Organización)
                $logoUrl = $sp['logo'] ?? null;
                $logoBase = $download_image($logoUrl);
                
                if ($logoBase) {
                    $logoBase = gi_safe_contain_logo($logoBase, $logoW, $logoH, $logoUrl, 'logo_speaker');
                    if ($logoBase) {
                        $logoX = ($cardW - $logoBase->getImageWidth()) / 2;
                        // Centrar verticalmente el logo en el espacio restante de la tarjeta
                        $remainingSpace = $cardH - $currentY - 20; 
                        $logoY = $currentY + ($remainingSpace - $logoBase->getImageHeight()) / 2;
                        
                        $cardCanvas->compositeImage($logoBase, Imagick::COMPOSITE_OVER, intval($logoX), intval($logoY));
                        $logoBase->destroy();
                    }
                }

                // 🖼️ Componer la tarjeta en el lienzo principal
                $img->compositeImage($cardCanvas, Imagick::COMPOSITE_OVER, intval($x), intval($y));
                $cardCanvas->destroy();
            }
        }
        error_log("🎤 Grid de tarjetas 2x3 generado con éxito.");
    } else {
        error_log("⚠️ No hay datos de 'speakers' para generar el grid.");
    }


    // 📤 Exportar
    // (Lógica de exportación sin cambios)
    $format = strtolower($payload['output']['format'] ?? 'jpg');
    $filename = sanitize_file_name(($payload['output']['filename'] ?? 'evento_inmobiliario').'_new_design.'.$format);

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

    error_log("✅ Imagen generada (Nuevo Diseño): $url");

    return new WP_REST_Response(['url'=>$url,'attachment_id'=>$attach_id], 200);
}