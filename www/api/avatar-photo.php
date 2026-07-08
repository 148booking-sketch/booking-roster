<?php
/**
 * GET /api/avatar-photo.php?u=<user_id>
 * Serve la foto profilo artista scaricata e salvata in cache locale permanente da
 * cache_avatar_image() (vedi _social.php): niente più richieste esterne ad ogni visita,
 * e la foto non si rompe più quando l'URL firmato Instagram scade (~4-5gg).
 */
require_once __DIR__ . '/_http.php';

$uid = (int) ($_GET['u'] ?? 0);
if ($uid <= 0) { http_response_code(400); exit; }

$dir = __DIR__ . '/cache/avatars';
$bin = "$dir/$uid.bin";
$ctf = "$dir/$uid.ct";
if (!is_file($bin)) { http_response_code(404); exit; }

header('Content-Type: ' . (is_file($ctf) ? trim((string) @file_get_contents($ctf)) : 'image/jpeg'));
header('Cache-Control: public, max-age=21600'); // 6h, stesso criterio di ig-avatar.php
readfile($bin);
