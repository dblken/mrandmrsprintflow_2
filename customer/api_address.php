<?php
/**
 * Philippine Address API Proxy
 * Proxies the free PSGC.cloud public API
 * No authentication required (public geo-data)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$action = $_GET['action'] ?? '';

/**
 * Fetch from PSGC.cloud with caching
 */
function fetch_psgc(string $path): ?array {
    $cache_dir = sys_get_temp_dir() . '/psgc_cache';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);

    $cache_file = $cache_dir . '/' . md5($path) . '.json';
    // Cache for 24 hours
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 86400) {
        $cached = file_get_contents($cache_file);
        if ($cached) return json_decode($cached, true);
    }

    $url = 'https://psgc.cloud/api' . $path;
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'header'  => "Accept: application/json\r\nUser-Agent: PrintFlow/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    @file_put_contents($cache_file, $raw);
    return json_decode($raw, true);
}

switch ($action) {

    case 'regions':
        $data = fetch_psgc('/regions');
        if ($data === null) { echo json_encode(['success'=>false,'error'=>'Failed to fetch regions']); exit; }
        $out = array_map(fn($r) => ['code'=>$r['code'],'name'=>$r['name']], $data);
        usort($out, fn($a,$b) => strcmp($a['name'],$b['name']));
        echo json_encode(['success'=>true,'data'=>$out]);
        break;

    case 'provinces':
        $region = $_GET['region'] ?? '';
        $path = $region ? '/regions/' . urlencode($region) . '/provinces' : '/provinces';
        $data = fetch_psgc($path);
        if ($data === null) { echo json_encode(['success'=>false,'error'=>'Failed to fetch provinces']); exit; }
        $out = array_map(fn($p) => ['code'=>$p['code'],'name'=>$p['name']], $data);
        usort($out, fn($a,$b) => strcmp($a['name'],$b['name']));
        echo json_encode(['success'=>true,'data'=>$out]);
        break;

    case 'cities':
        $province = $_GET['province'] ?? '';
        if (!$province) { echo json_encode(['success'=>false,'error'=>'Province required']); exit; }
        $data = fetch_psgc('/provinces/' . urlencode($province) . '/cities-municipalities');
        if ($data === null) { echo json_encode(['success'=>false,'error'=>'Failed to fetch cities']); exit; }
        $out = array_map(fn($c) => ['code'=>$c['code'],'name'=>$c['name']], $data);
        usort($out, fn($a,$b) => strcmp($a['name'],$b['name']));
        echo json_encode(['success'=>true,'data'=>$out]);
        break;

    case 'barangays':
        $city = $_GET['city'] ?? '';
        if (!$city) { echo json_encode(['success'=>false,'error'=>'City required']); exit; }
        $data = fetch_psgc('/cities-municipalities/' . urlencode($city) . '/barangays');
        if ($data === null) { echo json_encode(['success'=>false,'error'=>'Failed to fetch barangays']); exit; }
        $out = array_map(fn($b) => ['code'=>$b['code'],'name'=>$b['name']], $data);
        usort($out, fn($a,$b) => strcmp($a['name'],$b['name']));
        echo json_encode(['success'=>true,'data'=>$out]);
        break;

    default:
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
        break;
}
