<?php
/**
 * GET /api/notifications.php — feed notifiche per la campana in header.
 * Compone gli eventi dalle tabelle esistenti (nessuno stato server: il "visto"
 * è gestito client-side in localStorage).
 *   artista            → nuove richieste ricevute + messaggi ricevuti
 *   promoter/agenzia   → risposte alle proprie richieste + messaggi ricevuti
 *   admin              → artisti da approvare + ultime richieste sulla piattaforma
 * Risposta: { notifications: [{icon,title,meta,ts,href}] } (max 20, più recenti prima).
 */
require_once __DIR__ . '/_http.php';

$u = require_user();
$uid = (int)$u['id'];
$out = [];

$hasMessages = true;
try { db()->query('SELECT 1 FROM booking_messages LIMIT 1'); } catch (Throwable $e) { $hasMessages = false; }

if ($u['role'] === 'artist') {
  // richieste ricevute (le più recenti, non ritirate)
  $st = db()->prepare(
    "SELECT br.id, br.created_at, br.event_date, br.status, COALESCE(pp.org_name, up.display_name) AS who
     FROM booking_requests br
     JOIN users up ON up.id = br.promoter_user_id
     LEFT JOIN promoter_profiles pp ON pp.user_id = br.promoter_user_id
     WHERE br.artist_user_id = ? AND br.status IN ('inviata','vista')
     ORDER BY br.created_at DESC LIMIT 10");
  $st->execute([$uid]);
  foreach ($st->fetchAll() as $r) {
    $out[] = ['icon' => 'inbox', 'title' => 'Nuova richiesta da ' . $r['who'],
      'meta' => $r['event_date'] ? 'Evento il ' . date('d/m/Y', strtotime($r['event_date'])) : 'Data da concordare',
      'ts' => $r['created_at'], 'href' => '/richieste.html'];
  }
} elseif (in_array($u['role'], ['promoter', 'management'], true)) {
  // risposte alle richieste inviate
  $st = db()->prepare(
    "SELECT br.responded_at, br.status, ap.stage_name
     FROM booking_requests br JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
     WHERE br.promoter_user_id = ? AND br.responded_at IS NOT NULL AND br.status IN ('accettata','rifiutata')
     ORDER BY br.responded_at DESC LIMIT 10");
  $st->execute([$uid]);
  foreach ($st->fetchAll() as $r) {
    $ok = $r['status'] === 'accettata';
    $out[] = ['icon' => $ok ? 'check' : 'x', 'title' => $r['stage_name'] . ($ok ? ' ha accettato' : ' ha rifiutato'),
      'meta' => 'La tua richiesta di booking', 'ts' => $r['responded_at'], 'href' => '/richieste.html'];
  }
} else { // admin
  $st = db()->query(
    "SELECT ap.stage_name, ap.updated_at FROM artist_profiles ap
     JOIN users us ON us.id = ap.user_id
     WHERE ap.published = 0 AND us.email_verified = 1
     ORDER BY ap.updated_at DESC LIMIT 5");
  foreach ($st->fetchAll() as $r) {
    $out[] = ['icon' => 'clock', 'title' => $r['stage_name'] . ' in attesa di approvazione',
      'meta' => 'Vai al pannello per approvarlo', 'ts' => $r['updated_at'], 'href' => '/admin'];
  }
  $st = db()->query(
    "SELECT br.created_at, ap.stage_name, COALESCE(pp.org_name, up.display_name) AS who
     FROM booking_requests br
     JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
     JOIN users up ON up.id = br.promoter_user_id
     LEFT JOIN promoter_profiles pp ON pp.user_id = br.promoter_user_id
     ORDER BY br.created_at DESC LIMIT 8");
  foreach ($st->fetchAll() as $r) {
    $out[] = ['icon' => 'inbox', 'title' => $r['who'] . ' → ' . $r['stage_name'],
      'meta' => 'Nuova richiesta di booking', 'ts' => $r['created_at'], 'href' => '/admin'];
  }
}

// messaggi ricevuti sui propri thread (artista e promoter/agenzia)
if ($hasMessages && in_array($u['role'], ['artist', 'promoter', 'management'], true)) {
  $col = $u['role'] === 'artist' ? 'artist_user_id' : 'promoter_user_id';
  $st = db()->prepare(
    "SELECT m.created_at, us.display_name AS who, ap.stage_name
     FROM booking_messages m
     JOIN booking_requests br ON br.id = m.request_id
     JOIN users us ON us.id = m.sender_user_id
     JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
     WHERE br.$col = ? AND m.sender_user_id <> ?
     ORDER BY m.created_at DESC LIMIT 10");
  $st->execute([$uid, $uid]);
  foreach ($st->fetchAll() as $r) {
    $who = $u['role'] === 'artist' ? $r['who'] : ($r['stage_name'] ?: $r['who']);
    $out[] = ['icon' => 'message', 'title' => 'Messaggio da ' . $who,
      'meta' => 'Sul thread della richiesta', 'ts' => $r['created_at'], 'href' => '/richieste.html'];
  }
}

usort($out, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
ok(['notifications' => array_slice($out, 0, 20)]);
