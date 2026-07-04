<?php
/**
 * Thread di messaggi su una richiesta di booking (promoter ↔ artista).
 *   GET  /api/booking-messages.php?request_id=N   → lista messaggi (solo i partecipanti o admin)
 *   POST /api/booking-messages.php  Body: { request_id, body }  → aggiunge un messaggio
 * La tabella booking_messages viene creata al primo uso (come favorites/booking_reminders).
 * Al nuovo messaggio parte una email best-effort alla controparte.
 */
require_once __DIR__ . '/_http.php';

function ensure_messages_table(): bool {
  static $ok = null;
  if ($ok !== null) return $ok;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS booking_messages (
      id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
      request_id     INT UNSIGNED NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      body           TEXT NOT NULL,
      created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_req (request_id),
      CONSTRAINT fk_bm_req    FOREIGN KEY (request_id)     REFERENCES booking_requests(id) ON DELETE CASCADE,
      CONSTRAINT fk_bm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ok = true;
  } catch (Throwable $e) { error_log('ensure_messages_table: ' . $e->getMessage()); $ok = false; }
  return $ok;
}

$u = require_user();
ensure_messages_table();

/** La richiesta esiste e l'utente è un partecipante (o admin)? Ritorna la riga richiesta. */
function require_request_participant(int $requestId, array $u): array {
  if ($requestId <= 0) fail('request_required');
  $st = db()->prepare('SELECT id, promoter_user_id, artist_user_id, status FROM booking_requests WHERE id = ?');
  $st->execute([$requestId]);
  $r = $st->fetch();
  if (!$r) fail('not_found', 404);
  $uid = (int)$u['id'];
  if ($u['role'] !== 'admin' && $uid !== (int)$r['promoter_user_id'] && $uid !== (int)$r['artist_user_id']) {
    fail('forbidden_role', 403);
  }
  return $r;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  $reqId = (int)($_GET['request_id'] ?? 0);
  $r = require_request_participant($reqId, $u);
  $st = db()->prepare(
    'SELECT m.id, m.sender_user_id, m.body, m.created_at, us.display_name AS sender_name, us.role AS sender_role
     FROM booking_messages m JOIN users us ON us.id = m.sender_user_id
     WHERE m.request_id = ? ORDER BY m.created_at ASC, m.id ASC LIMIT 200'
  );
  $st->execute([$reqId]);
  $msgs = $st->fetchAll();
  foreach ($msgs as &$m) { $m['mine'] = ((int)$m['sender_user_id'] === (int)$u['id']); }
  unset($m);
  ok(['messages' => $msgs, 'request_status' => $r['status']]);
}

only('POST');
$in    = body();
$reqId = (int)($in['request_id'] ?? 0);
$text  = trim($in['body'] ?? '');
if ($text === '') fail('message_required');
if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);

$r = require_request_participant($reqId, $u);

db()->prepare('INSERT INTO booking_messages (request_id, sender_user_id, body) VALUES (?, ?, ?)')
    ->execute([$reqId, $u['id'], $text]);
$msgId = (int)db()->lastInsertId();

// Email alla controparte (best-effort, dopo il rilascio del lock di sessione).
require_once __DIR__ . '/_mail.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
notify_new_message($reqId, (int)$u['id']);

ok(['id' => $msgId]);
