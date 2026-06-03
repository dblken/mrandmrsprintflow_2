<?php
/**
 * Team member photo helpers (About page + admin settings).
 */

function printflow_team_upload_dir(): string
{
    return dirname(__DIR__) . '/public/assets/uploads/team/';
}

function printflow_ensure_team_upload_dir(): string
{
    $dir = printflow_team_upload_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Normalize stored photo value to a safe basename (or empty string). */
function printflow_team_photo_normalize(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $photo)) {
        $path = (string)(parse_url($photo, PHP_URL_PATH) ?? '');
        $photo = $path !== '' ? $path : $photo;
    }

    $photo = str_replace('\\', '/', $photo);
    if (preg_match('#/uploads/team/([^/]+)$#i', $photo, $m)) {
        return basename($m[1]);
    }
    if (preg_match('#/uploads/([^/]+)$#i', $photo, $m)) {
        return basename($m[1]);
    }

    return basename($photo);
}

function printflow_team_photo_disk_path(?string $photo): ?string
{
    $basename = printflow_team_photo_normalize($photo);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return null;
    }

    $teamPath = printflow_ensure_team_upload_dir() . $basename;
    if (is_file($teamPath)) {
        return $teamPath;
    }

    $uploadsPath = dirname(__DIR__) . '/public/assets/uploads/' . $basename;
    if (is_file($uploadsPath)) {
        return $uploadsPath;
    }

    return null;
}

/** Public URL for a team photo, or null when the file is not on disk. */
function printflow_team_photo_public_url(?string $photo): ?string
{
    $diskPath = printflow_team_photo_disk_path($photo);
    if ($diskPath === null) {
        return null;
    }

    $basename = basename($diskPath);
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    $subdir = str_contains(str_replace('\\', '/', $diskPath), '/uploads/team/') ? 'team/' : '';
    $url = rtrim($base, '/') . '/public/assets/uploads/' . $subdir . rawurlencode($basename);
    $mtime = @filemtime($diskPath);
    if ($mtime) {
        $url .= '?v=' . $mtime;
    }
    return $url;
}

/**
 * Save one team photo upload row; returns basename or null on failure / no file.
 *
 * @param array<string,mixed>|null $filesRow $_FILES['about_team_photo_upload']
 */
function printflow_save_team_photo_upload(?array $filesRow, int $index): ?string
{
    if ($filesRow === null || empty($filesRow['name'][$index])) {
        return null;
    }

    $error = (int)($filesRow['error'][$index] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo((string)$filesRow['name'][$index], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return null;
    }

    $fname = 'team_' . time() . '_' . $index . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = printflow_ensure_team_upload_dir() . $fname;
    if (!move_uploaded_file($filesRow['tmp_name'][$index], $dest)) {
        return null;
    }

    return $fname;
}

function printflow_team_photo_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}
