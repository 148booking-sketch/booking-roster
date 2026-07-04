<?php
/**
 * POST /api/favorites-toggle.php  — aggiunge/rimuove un artista dai preferiti.
 * Solo promoter/management (o admin). Body: { artist_user_id, on? }
 *   - on assente  → toggle (se c'è lo toglie, altrimenti lo aggiunge)
 *   - on = true   → aggiunge (idempotente)
 *   - on = false  → rimuove  (idempotente)
 * Risposta: { favorite: bool }
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_access.php';
only('POST');

$viewer = current_user();
if (!$viewer) fail('not_authenticated', 401);
if (!viewer_can_favorite($viewer)) fail('forbidden_role', 403);
ensure_favorites_table();

$in       = body();
$artistId = (int)($in['artist_user_id'] ?? 0);
if ($artistId <= 0) fail('artist_required');

// dev'essere un artista reale con profilo pubblicato
$chk = db()->prepare("SELECT 1 FROM users u JOIN artist_profiles ap ON ap.user_id = u.id
                      WHERE u.id = ? AND u.role = 'artist' AND ap.published = 1");
$chk->execute([$artistId]);
if (!$chk->fetchColumn()) fail('not_found', 404);

$uid = (int)$viewer['id'];
$ex  = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND artist_user_id = ?');
$ex->execute([$uid, $artistId]);
$exists = (bool)$ex->fetchColumn();

$want = array_key_exists('on', $in) ? (bool)$in['on'] : !$exists;

if ($want && !$exists) {
  db()->prepare('INSERT IGNORE INTO favorites (user_id, artist_user_id) VALUES (?, ?)')->execute([$uid, $artistId]);
} elseif (!$want && $exists) {
  db()->prepare('DELETE FROM favorites WHERE user_id = ? AND artist_user_id = ?')->execute([$uid, $artistId]);
}

ok(['favorite' => $want]);
