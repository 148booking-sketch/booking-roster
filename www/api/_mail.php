<?php
/**
 * Invio email semplice via mail() nativa (hosting DirectAdmin).
 * HTML minimale con mittente da config. Ritorna true/false.
 */
require_once __DIR__ . '/_db.php';

function send_mail(string $to, string $subject, string $htmlBody): bool {
  $c = config();
  $from     = $c['mail_from']      ?? 'noreply@bookingroster.it';
  $fromName = $c['mail_from_name'] ?? 'Booking Roster';

  $headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=utf-8',
    'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . '>',
    'Reply-To: ' . $from,
    'X-Mailer: BookingRoster',
  ]);
  $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

  return @mail($to, $subjectEnc, $htmlBody, $headers);
}

/** Invia l'email di verifica con il link che attiva l'account. */
function send_verification_email(string $email, string $name, string $token): bool {
  $c = config();
  $link = rtrim($c['app_url'] ?? 'https://bookingroster.it', '/') . '/api/verify-email.php?token=' . $token;
  $name = trim($name) ?: 'ciao';
  $body = mail_layout('Verifica la tua email',
      '<p>' . htmlspecialchars($name) . ', benvenuto in Booking Roster! Conferma la tua email per attivare l\'account.</p>'
    . '<p style="margin:20px 0"><a href="' . htmlspecialchars($link) . '" '
    . 'style="background:#1a1c22;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;display:inline-block">Verifica email</a></p>'
    . '<p style="font-size:13px;color:#777">Se non hai creato tu questo account, ignora questa email.</p>'
    . '<p style="font-size:12px;color:#999;word-break:break-all">' . htmlspecialchars($link) . '</p>');
  return @send_mail($email, 'Verifica la tua email · Booking Roster', $body);
}

/** Layout email di base per Booking Roster. $footerHtml opzionale (es. link disiscrizione). */
function mail_layout(string $title, string $bodyHtml, string $footerHtml = ''): string {
  $c = config();
  $app = $c['app_name'] ?? 'Booking Roster';
  return '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;color:#1a1c22">'
    . '<div style="font-size:20px;font-weight:700;margin-bottom:16px">' . htmlspecialchars($app) . '</div>'
    . '<h2 style="font-size:18px;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>'
    . '<div style="font-size:15px;line-height:1.55;color:#333">' . $bodyHtml . '</div>'
    . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0">'
    . '<div style="font-size:12px;color:#999">' . htmlspecialchars($app) . ' · bookingroster.it</div>'
    . ($footerHtml !== '' ? '<div style="font-size:12px;color:#999;margin-top:6px">' . $footerHtml . '</div>' : '')
    . '</div>';
}

/* ============================================================
   EMAIL TRANSAZIONALI RICHIESTE DI BOOKING (design "Email di sistema")
   Tutte best-effort: mai bloccare la risposta HTTP se l'invio fallisce.
   ============================================================ */

/** Bottone CTA standard per le email. */
function mail_cta(string $href, string $label): string {
  return '<p style="margin:20px 0"><a href="' . htmlspecialchars($href) . '" '
    . 'style="background:#1a1c22;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;display:inline-block">'
    . htmlspecialchars($label) . '</a></p>';
}

/** Nuova richiesta di booking → email all'artista. */
function notify_new_booking_request(int $artistUserId, array $req): void {
  try {
    $st = db()->prepare('SELECT u.email, ap.stage_name FROM users u JOIN artist_profiles ap ON ap.user_id = u.id WHERE u.id = ?');
    $st->execute([$artistUserId]);
    $a = $st->fetch(); if (!$a) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $when = !empty($req['event_date']) ? date('d/m/Y', strtotime($req['event_date'])) : 'da concordare';
    $fee  = isset($req['proposed_fee']) && $req['proposed_fee'] !== null ? '€' . number_format((int)$req['proposed_fee'], 0, ',', '.') : 'da concordare';
    $body = mail_layout('Nuova richiesta di booking',
        '<p>' . htmlspecialchars($a['stage_name'] ?: 'Ciao') . ', <b>' . htmlspecialchars($req['promoter_name'] ?? 'un promoter') . '</b> vuole scritturarti!</p>'
      . '<p style="font-size:14px;color:#444">Data: <b>' . htmlspecialchars($when) . '</b><br>Offerta: <b>' . htmlspecialchars($fee) . '</b></p>'
      . (!empty($req['message']) ? '<p style="font-size:14px;color:#555;background:#f7f7f7;border-radius:10px;padding:12px 14px">&ldquo;' . nl2br(htmlspecialchars(mb_substr($req['message'], 0, 500))) . '&rdquo;</p>' : '')
      . mail_cta($appUrl . '/richieste.html', 'Rispondi alla richiesta'));
    @send_mail($a['email'], 'Nuova richiesta di booking · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** L'artista ha risposto (accettata/rifiutata) → email al promoter. */
function notify_booking_response(int $requestId, string $status): void {
  if (!in_array($status, ['accettata', 'rifiutata'], true)) return;
  try {
    $st = db()->prepare(
      'SELECT br.event_date, up.email AS promoter_email, ap.stage_name
       FROM booking_requests br
       JOIN users up ON up.id = br.promoter_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       WHERE br.id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(); if (!$r) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $when = $r['event_date'] ? ' per il ' . date('d/m/Y', strtotime($r['event_date'])) : '';
    if ($status === 'accettata') {
      $subject = $r['stage_name'] . ' ha accettato la tua richiesta';
      $intro = '<p><b>' . htmlspecialchars($r['stage_name']) . '</b> ha <b style="color:#0a7d38">accettato</b> la tua richiesta' . $when . '! Ora potete accordarvi sui dettagli.</p>';
    } else {
      $subject = 'La tua richiesta non è andata a buon fine';
      $intro = '<p><b>' . htmlspecialchars($r['stage_name']) . '</b> non ha potuto accettare la tua richiesta' . $when . '. Nel roster ci sono tanti altri artisti disponibili!</p>';
    }
    $body = mail_layout($status === 'accettata' ? 'Richiesta accettata' : 'Richiesta non accolta',
      $intro . mail_cta($appUrl . ($status === 'accettata' ? '/richieste.html' : '/'), $status === 'accettata' ? 'Vedi la richiesta' : 'Cerca altri artisti'));
    @send_mail($r['promoter_email'], $subject . ' · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Profilo approvato/pubblicato per la prima volta → email all'artista. */
function notify_artist_published(int $artistUserId): void {
  try {
    $st = db()->prepare('SELECT u.email, ap.stage_name, ap.slug FROM users u JOIN artist_profiles ap ON ap.user_id = u.id WHERE u.id = ?');
    $st->execute([$artistUserId]);
    $a = $st->fetch(); if (!$a) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $link = $appUrl . '/' . rawurlencode($a['slug'] ?: '');
    $body = mail_layout('Il tuo profilo è online!',
        '<p>' . htmlspecialchars($a['stage_name'] ?: 'Ciao') . ', il tuo profilo è stato approvato ed è ora <b>visibile ai promoter</b> nella ricerca di Booking Roster.</p>'
      . '<p style="font-size:14px;color:#555">Tieni aggiornati calendario e cachet: i profili completi ricevono più richieste.</p>'
      . mail_cta($link, 'Vedi il tuo profilo pubblico'));
    @send_mail($a['email'], 'Il tuo profilo è online · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Benvenuto post-verifica per promoter/agenzie. */
function notify_promoter_welcome(string $email, string $name): void {
  try {
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $body = mail_layout('Benvenuto su Booking Roster',
        '<p>' . htmlspecialchars($name ?: 'Ciao') . ', il tuo account è attivo! Ecco come funziona:</p>'
      . '<p style="font-size:14px;color:#444">1. <b>Cerca</b> l\'artista giusto per il tuo evento (filtri per genere, zona, budget).<br>'
      . '2. <b>Salva i preferiti</b> e tieni d\'occhio disponibilità e promo.<br>'
      . '3. <b>Invia la richiesta</b> con data e offerta: l\'artista ti risponde qui.</p>'
      . '<p style="font-size:13px;color:#777">I cachet diventano visibili dopo l\'approvazione del tuo account da parte dello staff.</p>'
      . mail_cta($appUrl . '/', 'Inizia a cercare artisti'));
    @send_mail($email, 'Benvenuto su Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Nuovo messaggio nel thread di una richiesta → email alla controparte. */
function notify_new_message(int $requestId, int $senderUserId): void {
  try {
    $st = db()->prepare(
      'SELECT br.promoter_user_id, br.artist_user_id, ap.stage_name,
              up.email AS promoter_email, up.display_name AS promoter_name, ua.email AS artist_email
       FROM booking_requests br
       JOIN users up ON up.id = br.promoter_user_id
       JOIN users ua ON ua.id = br.artist_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       WHERE br.id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(); if (!$r) return;
    $senderIsArtist = $senderUserId === (int)$r['artist_user_id'];
    $to   = $senderIsArtist ? $r['promoter_email'] : $r['artist_email'];
    $from = $senderIsArtist ? $r['stage_name'] : ($r['promoter_name'] ?: 'Il promoter');
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $body = mail_layout('Nuovo messaggio',
        '<p><b>' . htmlspecialchars($from) . '</b> ti ha scritto un messaggio sulla richiesta di booking.</p>'
      . mail_cta($appUrl . '/richieste.html', 'Leggi e rispondi'));
    @send_mail($to, 'Nuovo messaggio da ' . $from . ' · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/**
 * Promemoria evento: 3 giorni prima della data, email a artista e promoter per ogni
 * richiesta ACCETTATA. Deduplica con la tabella booking_reminders (auto-creata al primo
 * uso, stessa strategia di ensure_favorites_table). Sicuro da richiamare più volte al giorno.
 * Ritorna il numero di promemoria inviati.
 */
function send_event_reminders(): int {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS booking_reminders (
      request_id INT UNSIGNED NOT NULL,
      sent_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (request_id),
      CONSTRAINT fk_rem_req FOREIGN KEY (request_id) REFERENCES booking_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) { return 0; }

  $sent = 0;
  try {
    $st = db()->query(
      "SELECT br.id, br.event_date, br.proposed_fee,
              ua.email AS artist_email, ap.stage_name,
              up.email AS promoter_email, up.display_name AS promoter_name, pp.org_name
       FROM booking_requests br
       JOIN users ua ON ua.id = br.artist_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       JOIN users up ON up.id = br.promoter_user_id
       LEFT JOIN promoter_profiles pp ON pp.user_id = br.promoter_user_id
       LEFT JOIN booking_reminders rem ON rem.request_id = br.id
       WHERE br.status = 'accettata' AND rem.request_id IS NULL
         AND br.event_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    foreach ($st->fetchAll() as $r) {
      $when = date('d/m/Y', strtotime($r['event_date']));
      $org  = $r['org_name'] ?: $r['promoter_name'] ?: 'il promoter';
      $bodyA = mail_layout('Promemoria evento',
          '<p>' . htmlspecialchars($r['stage_name']) . ', tra 3 giorni (<b>' . $when . '</b>) hai l\'evento concordato con <b>' . htmlspecialchars($org) . '</b>.</p>'
        . mail_cta($appUrl . '/richieste.html', 'Rivedi i dettagli'));
      $bodyP = mail_layout('Promemoria evento',
          '<p>Tra 3 giorni (<b>' . $when . '</b>) c\'è l\'evento con <b>' . htmlspecialchars($r['stage_name']) . '</b> che hai concordato su Booking Roster.</p>'
        . mail_cta($appUrl . '/richieste.html', 'Rivedi i dettagli'));
      @send_mail($r['artist_email'],   'Promemoria: evento il ' . $when . ' · Booking Roster', $bodyA);
      @send_mail($r['promoter_email'], 'Promemoria: evento il ' . $when . ' · Booking Roster', $bodyP);
      db()->prepare('INSERT IGNORE INTO booking_reminders (request_id) VALUES (?)')->execute([$r['id']]);
      $sent++;
    }
  } catch (Throwable $e) { /* best-effort */ }
  return $sent;
}

/** Genera (se manca) e ritorna il token di disiscrizione one-click del promoter. */
function ensure_promoter_unsub_token(int $userId): string {
  $st = db()->prepare('SELECT email_unsub_token FROM promoter_profiles WHERE user_id = ?');
  $st->execute([$userId]);
  $tok = $st->fetchColumn();
  if ($tok) return $tok;
  $tok = bin2hex(random_bytes(32));
  db()->prepare('UPDATE promoter_profiles SET email_unsub_token = ? WHERE user_id = ?')->execute([$tok, $userId]);
  return $tok;
}

/**
 * Digest email per i promoter: nuovi artisti pubblicati, artisti in promo attivi,
 * richieste di booking a cui l'artista ha risposto. $data = [
 *   'new_artists' => [ {stage_name, slug, comune, genre}, ... ],
 *   'promo_artists' => [ {stage_name, slug, comune, cachet_promo, promo_until}, ... ],
 *   'responded_requests' => [ {stage_name, status, event_date}, ... ],
 * ]
 * Ritorna null se non c'è nulla da mandare (il chiamante decide di non inviare).
 */
function build_promoter_digest_html(array $data, string $freqLabel, string $name = '', bool $force = false): ?string {
  $hasContent = !empty($data['new_artists']) || !empty($data['promo_artists']) || !empty($data['responded_requests']);
  if (!$hasContent && !$force) return null;

  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $artistLink = fn($slug) => $appUrl . '/' . rawurlencode($slug);

  $sections = '';

  if (!empty($data['new_artists'])) {
    $items = '';
    foreach ($data['new_artists'] as $a) {
      $loc = trim(($a['comune'] ?? '') . ($a['provincia'] ? ' (' . $a['provincia'] . ')' : ''));
      $items .= '<li style="margin-bottom:6px"><a href="' . htmlspecialchars($artistLink($a['slug'])) . '" style="color:#d52454;text-decoration:none;font-weight:600">'
        . htmlspecialchars($a['stage_name']) . '</a>'
        . ($a['genre'] ? ' · ' . htmlspecialchars($a['genre']) : '')
        . ($loc !== '' ? ' · ' . htmlspecialchars($loc) : '') . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">🆕 Nuovi artisti in roster</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if (!empty($data['promo_artists'])) {
    $items = '';
    foreach ($data['promo_artists'] as $a) {
      $until = $a['promo_until'] ? ' (fino al ' . date('d/m/Y', strtotime($a['promo_until'])) . ')' : '';
      $items .= '<li style="margin-bottom:6px"><a href="' . htmlspecialchars($artistLink($a['slug'])) . '" style="color:#d52454;text-decoration:none;font-weight:600">'
        . htmlspecialchars($a['stage_name']) . '</a> · cachet promo ' . (int)$a['cachet_promo'] . '€' . $until . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">🔥 Artisti in promo</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if (!empty($data['responded_requests'])) {
    $items = '';
    foreach ($data['responded_requests'] as $r) {
      $label = $r['status'] === 'accettata' ? '✅ ha accettato' : '❌ ha rifiutato';
      $items .= '<li style="margin-bottom:6px"><b>' . htmlspecialchars($r['stage_name']) . '</b> ' . $label . ' la tua richiesta'
        . ($r['event_date'] ? ' per il ' . date('d/m/Y', strtotime($r['event_date'])) : '') . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">📩 Risposte alle tue richieste</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if ($sections === '' && $force) {
    $sections = '<p style="color:#777;font-size:13px">(Nessuna novità reale in questo momento — email di test.)</p>';
  }

  $greet = $name !== '' ? htmlspecialchars($name) : 'Ciao';
  return '<p>' . $greet . ', ecco il riepilogo ' . htmlspecialchars($freqLabel) . ' pensato per te:</p>' . $sections;
}

/**
 * Invia il digest e ritorna true/false. $unsubToken → link di disiscrizione one-click
 * (se vuoto, il link viene omesso: caso email di test non legata a un account promoter).
 * $force = true → invia comunque anche se non c'è contenuto reale (per test manuali).
 */
function send_promoter_digest_email(string $email, string $name, array $data, string $freqLabel, string $unsubToken, bool $force = false): bool {
  $body = build_promoter_digest_html($data, $freqLabel, $name, $force);
  if ($body === null) return false;

  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $prefsLink = $appUrl . '/account.html';

  $footer = 'Ricevi questa email perché hai attivato gli alert ' . htmlspecialchars($freqLabel) . ' su Booking Roster. '
    . '<a href="' . htmlspecialchars($prefsLink) . '" style="color:#999">Gestisci preferenze</a>';
  if ($unsubToken !== '') {
    $unsubLink = $appUrl . '/api/promoter-unsubscribe.php?token=' . urlencode($unsubToken);
    $footer .= ' · <a href="' . htmlspecialchars($unsubLink) . '" style="color:#999">Disiscriviti</a>';
  }

  $html = mail_layout('Novità per te su Booking Roster', $body, $footer);
  return send_mail($email, 'Novità su Booking Roster · nuovi artisti e promo', $html);
}
