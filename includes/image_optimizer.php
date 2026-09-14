<?php
/**
 * Customer catalog image optimization.
 * Retains originals; generates WebP derivatives on demand or after upload.
 */

function pf_image_optimizer_profiles(): array {
    return [
        'card' => [
            'widths' => [400, 800],
            'default' => 400,
            'sizes' => '(max-width: 768px) 50vw, 25vw',
            'quality' => 82,
        ],
        'detail' => [
            'widths' => [600, 800, 1200, 1600],
            'default' => 800,
            'sizes' => '(max-width: 768px) 100vw, 600px',
            'quality' => 84,
        ],
        'review' => [
            'widths' => [400],
            'default' => 400,
            'sizes' => '140px',
            'quality' => 80,
        ],
    ];
}

function pf_image_optimizer_is_raster_image(string $path): bool {
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function pf_image_optimizer_local_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return null;
    }

    $path = str_replace('\\', '/', strtok($url, '?#'));
    $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . ltrim((string)$path, '/');

    $appRoot = realpath(__DIR__ . '/..');
    if ($appRoot === false) {
        return null;
    }

    $candidates = [$appRoot . $path];
    if (strpos($path, '/uploads/') === 0) {
        $candidates[] = $appRoot . '/public' . $path;
    }
    if (strpos($path, '/public/') === 0) {
        $candidates[] = $appRoot . substr($path, strlen('/public'));
    }

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && strpos($resolved, $appRoot) === 0 && is_file($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function pf_image_optimizer_derivative_path(string $localOriginal, int $width): string {
    $dir = dirname($localOriginal);
    $base = pathinfo($localOriginal, PATHINFO_FILENAME);
    return $dir . DIRECTORY_SEPARATOR . $base . '-pf' . $width . '.webp';
}

function pf_image_optimizer_url_from_local(string $localDerivative, string $referenceUrl): ?string {
    $appRoot = realpath(__DIR__ . '/..');
    if ($appRoot === false || !is_file($localDerivative)) {
        return null;
    }

    $refLocal = pf_image_optimizer_local_from_url($referenceUrl);
    if ($refLocal === null) {
        return null;
    }

    $refDir = dirname($refLocal);
    $derDir = dirname($localDerivative);
    if ($refDir !== $derDir) {
        return null;
    }

    $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
    $refPath = str_replace('\\', '/', strtok($referenceUrl, '?#'));
    if ($base !== '' && strpos($refPath, $base . '/') === 0) {
        $refPath = substr($refPath, 0, strrpos($refPath, '/') + 1) . basename($localDerivative);
        return $refPath;
    }

    $relative = str_replace('\\', '/', substr($localDerivative, strlen($appRoot)));
    return ($base !== '' ? $base : '') . $relative;
}

function pf_image_optimizer_load_image(string $path) {
    if (!is_file($path) || !function_exists('imagecreatetruecolor')) {
        return null;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    try {
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return @imagecreatefromjpeg($path) ?: null;
        }
        if ($ext === 'png') {
            $img = @imagecreatefrompng($path);
            if ($img) {
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }
            return $img ?: null;
        }
        if ($ext === 'gif') {
            return @imagecreatefromgif($path) ?: null;
        }
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path) ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function pf_image_optimizer_generate(string $sourcePath, int $targetWidth, int $quality = 82): bool {
    if ($targetWidth < 1 || !function_exists('imagewebp')) {
        return false;
    }

    $source = pf_image_optimizer_load_image($sourcePath);
    if (!$source) {
        return false;
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($source);
        return false;
    }

    $targetWidth = min($targetWidth, $srcW);
    $targetHeight = (int)max(1, round($srcH * ($targetWidth / $srcW)));
    $dest = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$dest) {
        imagedestroy($source);
        return false;
    }

    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

    $derivative = pf_image_optimizer_derivative_path($sourcePath, $targetWidth);
    $ok = @imagewebp($dest, $derivative, max(60, min(95, $quality)));

    imagedestroy($source);
    imagedestroy($dest);

    return $ok && is_file($derivative);
}

function pf_image_optimizer_ensure_width(string $url, int $width, int $quality = 82, bool $generate = true): ?string {
    if (!pf_image_optimizer_is_raster_image($url)) {
        return null;
    }

    $local = pf_image_optimizer_local_from_url($url);
    if ($local === null) {
        return null;
    }

    $derivative = pf_image_optimizer_derivative_path($local, $width);
    if (!is_file($derivative) && $generate) {
        pf_image_optimizer_generate($local, $width, $quality);
    }
    if (!is_file($derivative)) {
        return null;
    }

    return pf_image_optimizer_url_from_local($derivative, $url);
}

function pf_image_optimizer_generate_all(string $localPath, ?array $widths = null): void {
    if (!is_file($localPath) || !pf_image_optimizer_is_raster_image($localPath)) {
        return;
    }
    $widths = $widths ?? [400, 600, 800, 1200, 1600];
    foreach ($widths as $width) {
        $derivative = pf_image_optimizer_derivative_path($localPath, (int)$width);
        if (!is_file($derivative)) {
            pf_image_optimizer_generate($localPath, (int)$width, 82);
        }
    }
}

function pf_image_optimizer_after_upload_path(string $localPath): void {
    if (is_file($localPath)) {
        pf_image_optimizer_generate_all($localPath);
    }
}

function pf_catalog_image_bundle(string $url, string $profile = 'card', bool $generateDefault = true): array {
    $url = trim($url);
    $profiles = pf_image_optimizer_profiles();
    $config = $profiles[$profile] ?? $profiles['card'];
    $fallback = $url;
    $src = $fallback;
    $srcsetParts = [];

    if ($url !== '' && pf_image_optimizer_is_raster_image($url)) {
        foreach ($config['widths'] as $width) {
            $shouldGenerate = $generateDefault && (int)$width === (int)$config['default'];
            $optimized = pf_image_optimizer_ensure_width($url, (int)$width, (int)$config['quality'], $shouldGenerate);
            if ($optimized !== null) {
                $srcsetParts[(int)$width] = htmlspecialchars($optimized, ENT_QUOTES, 'UTF-8') . ' ' . (int)$width . 'w';
                if ((int)$width === (int)$config['default']) {
                    $src = $optimized;
                }
            }
        }
        if ($src === $fallback && $srcsetParts !== []) {
            $firstWidth = min(array_keys($srcsetParts));
            $src = strtok($srcsetParts[$firstWidth], ' ');
        }
    }

    return [
        'src' => $src,
        'srcset' => implode(', ', $srcsetParts),
        'sizes' => (string)$config['sizes'],
        'fallback' => $fallback,
        'profile' => $profile,
    ];
}

function pf_catalog_image_tag(string $url, string $profile = 'card', array $attrs = []): string {
    $bundle = pf_catalog_image_bundle($url, $profile);
    $alt = htmlspecialchars((string)($attrs['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
    $class = htmlspecialchars((string)($attrs['class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $loading = (string)($attrs['loading'] ?? 'lazy');
    $decoding = (string)($attrs['decoding'] ?? 'async');
    $fetchpriority = trim((string)($attrs['fetchpriority'] ?? ''));
    $width = isset($attrs['width']) ? (int)$attrs['width'] : 0;
    $height = isset($attrs['height']) ? (int)$attrs['height'] : 0;
    $onerror = (string)($attrs['onerror'] ?? "this.onerror=null;this.src='" . addslashes($bundle['fallback']) . "';");
    $style = trim((string)($attrs['style'] ?? ''));
    $onclick = trim((string)($attrs['onclick'] ?? ''));
    $extraAttrs = is_array($attrs['data'] ?? null) ? $attrs['data'] : [];

    $parts = [
        '<img src="' . htmlspecialchars($bundle['src'], ENT_QUOTES, 'UTF-8') . '"',
        'alt="' . $alt . '"',
    ];
    if ($class !== '') {
        $parts[] = 'class="' . $class . '"';
    }
    if ($bundle['srcset'] !== '') {
        $parts[] = 'srcset="' . $bundle['srcset'] . '"';
        $parts[] = 'sizes="' . htmlspecialchars($bundle['sizes'], ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($width > 0) {
        $parts[] = 'width="' . $width . '"';
    }
    if ($height > 0) {
        $parts[] = 'height="' . $height . '"';
    }
    $parts[] = 'loading="' . htmlspecialchars($loading, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'decoding="' . htmlspecialchars($decoding, ENT_QUOTES, 'UTF-8') . '"';
    if ($fetchpriority !== '') {
        $parts[] = 'fetchpriority="' . htmlspecialchars($fetchpriority, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($style !== '') {
        $parts[] = 'style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($onclick !== '') {
        $parts[] = 'onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '"';
    }
    foreach ($extraAttrs as $attrName => $attrValue) {
        $parts[] = htmlspecialchars((string)$attrName, ENT_QUOTES, 'UTF-8')
            . '="' . htmlspecialchars((string)$attrValue, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($onerror !== '') {
        $parts[] = 'onerror="' . htmlspecialchars($onerror, ENT_QUOTES, 'UTF-8') . '"';
    }
    $parts[] = '/>';

    return implode(' ', $parts);
}
