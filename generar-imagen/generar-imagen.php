<?php
/**
 * Plugin Name: Generar Imagen
 * Description: Endpoint REST para generar imágenes a partir de JSON (usa Imagick y sube el resultado a Medios).
 * Version: 1.2.0
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

// Log de inicio
error_log('✅ Plugin Generar Imagen cargado correctamente');

// Registrar endpoint
add_action('rest_api_init', function () {
  error_log('✅ Registrando ruta imagen/v1/generar');

  register_rest_route('imagen/v1', '/generar', [
    'methods'  => 'POST',
    'callback' => 'gi_render_handler',
    'permission_callback' => '__return_true',
  ]);
});

function gi_render_handler(WP_REST_Request $request) {
  if (!class_exists('Imagick')) {
    return new WP_REST_Response(['error' => 'Imagick no disponible en el servidor'], 500);
  }

  $token = $request->get_param('token');
  if ($token !== 'SECRETO') {
    return new WP_REST_Response(['error' => 'Unauthorized'], 401);
  }

  $payload = $request->get_json_params();
  if (!$payload) return new WP_REST_Response(['error' => 'JSON vacío'], 400);

  // --- Canvas base ---
  $W = min(intval($payload['canvas']['width'] ?? 1600), 4000);
  $H = min(intval($payload['canvas']['height'] ?? 900), 4000);
  $bg = $payload['canvas']['background'] ?? '#ffffff';

  $img = new Imagick();
  $img->newImage($W, $H, new ImagickPixel($bg));
  $img->setImageFormat('png');

  // --- Función para descargar imágenes externas ---
  $download_image = function(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT => 'WordPress/GenerarImagen (+https://grupovia.net)',
    ]);
    $data = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    error_log("📸 Descarga $url → status=$status bytes=" . strlen($data));
    curl_close($ch);

    if (!$data || $status != 200) return null;

    $tmp = wp_tempnam($url);
    file_put_contents($tmp, $data);

    try {
      $m = new Imagick($tmp);
    } catch (\Throwable $e) {
      @unlink($tmp);
      return null;
    }

    @unlink($tmp);
    $m->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
    return $m;
  };

  // --- Procesar LAYERS ---
  if (!empty($payload['layers'])) {
    foreach ($payload['layers'] as $layer) {
      if (($layer['type'] ?? '') === 'image' && !empty($layer['url'])) {
        $lay = $download_image($layer['url']);
        if (!$lay) continue;
        $w = intval($layer['width'] ?? $lay->getImageWidth());
        $h = intval($layer['height'] ?? $lay->getImageHeight());
        $lay->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);

        if (!empty($layer['rotation'])) {
          $lay->rotateImage(new ImagickPixel('transparent'), floatval($layer['rotation']));
        }

        if (isset($layer['opacity'])) {
          $lay->evaluateImage(Imagick::EVALUATE_MULTIPLY, floatval($layer['opacity']), Imagick::CHANNEL_ALPHA);
        }

        $img->compositeImage($lay, Imagick::COMPOSITE_DEFAULT, intval($layer['x'] ?? 0), intval($layer['y'] ?? 0));
        $lay->destroy();
      } elseif (($layer['type'] ?? '') === 'text' && !empty($layer['text'])) {
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel($layer['color'] ?? '#000000'));
        $draw->setFontSize(intval($layer['size'] ?? 40));
        $img->annotateImage($draw, intval($layer['x'] ?? 0), intval($layer['y'] ?? 0), floatval($layer['rotation'] ?? 0), $layer['text']);
      }
    }
  }

  // --- Procesar SPEAKERS ---
  else if (!empty($payload['speakers']) && is_array($payload['speakers'])) {
    $speakers = $payload['speakers'];
    $n = count($speakers);

    if ($n == 6) { $cols = 3; $rows = 2; }
    elseif ($n == 7) { $cols = 4; $rows = 2; }
    elseif ($n == 9) { $cols = 3; $rows = 3; }
    else {
      $cols = ceil(sqrt($n));
      $rows = ceil($n / $cols);
    }

    $padding = intval($payload['autoLayout']['padding'] ?? 40);
    $gutter  = intval($payload['autoLayout']['gutter'] ?? 20);

    $gridW = $W - 2*$padding;
    $gridH = $H - 2*$padding;
    $cellW = intval(($gridW - ($cols-1)*$gutter) / $cols);
    $cellH = intval(($gridH - ($rows-1)*$gutter) / $rows);

    for ($i=0; $i<$n; $i++) {
      $c = $i % $cols;
      $r = intdiv($i, $cols);
      $x = $padding + $c*($cellW + $gutter);
      $y = $padding + $r*($cellH + $gutter);

      $url = $speakers[$i]['photo'] ?? null;
      if (!$url) continue;
      $lay = $download_image($url);
      if (!$lay) continue;

      $lay->thumbnailImage($cellW, $cellH, true, true);

      $frame = new Imagick();
      $frame->newImage($cellW, $cellH, new ImagickPixel('#ffffff'));
      $frame->setImageFormat('png');

      $offX = intval(($cellW - $lay->getImageWidth())/2);
      $offY = intval(($cellH - $lay->getImageHeight())/2);
      $frame->compositeImage($lay, Imagick::COMPOSITE_DEFAULT, $offX, $offY);

      // Recorte circular
      $mask = new Imagick();
      $mask->newImage($cellW, $cellH, new ImagickPixel('transparent'));
      $drawMask = new ImagickDraw();
      $drawMask->setFillColor('white');
      $drawMask->circle($cellW/2, $cellH/2, $cellW/2, 0);
      $mask->drawImage($drawMask);
      $frame->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
      $mask->destroy();

      $img->compositeImage($frame, Imagick::COMPOSITE_OVER, $x, $y);
      $lay->destroy();
      $frame->destroy();
    }

    // Título
    if (!empty($payload['event_title'])) {
      $draw = new ImagickDraw();
      $draw->setFillColor('#111111');
      $draw->setFontSize(36);
      $img->annotateImage($draw, $padding, $H - $padding/2, 0, $payload['event_title']);
    }
  }

  else {
    return new WP_REST_Response(['error' => 'Debes enviar "layers" o "speakers"'], 400);
  }

  // --- Salida final ---
  $format = strtolower($payload['output']['format'] ?? 'jpg');
  $quality = intval($payload['output']['quality'] ?? 90);
  $filename = sanitize_file_name(($payload['output']['filename'] ?? ('collage-'.time())) . '.' . $format);

  $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

  if ($format === 'jpg' || $format === 'jpeg') {
    $img->setImageFormat('jpeg');
    $img->setImageCompressionQuality($quality);
  } elseif ($format === 'webp') {
    $img->setImageFormat('webp');
    $img->setImageCompressionQuality($quality);
  } else {
    $img->setImageFormat('png');
  }

  $blob = $img->getImagesBlob();
  $upload = wp_upload_bits($filename, null, $blob);
  if (!empty($upload['error'])) {
    return new WP_REST_Response(['error' => 'Fallo subiendo a Medios'], 500);
  }

  $filetype = wp_check_filetype($upload['file']);
  $attachment = [
    'post_mime_type' => $filetype['type'],
    'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
    'post_content'   => '',
    'post_status'    => 'inherit'
  ];

  $attach_id = wp_insert_attachment($attachment, $upload['file']);
  require_once ABSPATH.'wp-admin/includes/image.php';
  wp_generate_attachment_metadata($attach_id, $upload['file']);
  $url = wp_get_attachment_url($attach_id);

  error_log("✅ Imagen final guardada en: $url");

  return new WP_REST_Response(['url' => $url, 'attachment_id' => $attach_id], 200);
}
