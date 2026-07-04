<?php
/**
 * POST /api/account-save.php   (utente loggato)
 * Body: { email, display_name }
 * Aggiorna i dati dell'account (NON la password: usa password-change.php).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
only('POST');

$u   = require_user();
$in  = body();
$name  = trim($in['display_name'] ?? '');
$email = strtolower(trim($in['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if ($name === '') fail('name_required');

$emailChanged = ($email !== strtolower($u['email']));

if ($emailChanged) {
  // Email presa da un altro utente?
  $st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
  $st->execute([$email, $u['id']]);
  if ($st->fetch()) fail('email_taken', 409);

  // Cambiando indirizzo, l'email torna NON verificata e parte una nuova verifica: così non si
  // può spostare l'account su un indirizzo non posseduto mantenendo l'accesso ai login futuri.
  // La sessione corrente resta valida (non sloggiamo l'utente subito), ma per riaccedere dovrà
  // verificare il nuovo indirizzo (login richiede email_verified=1).
  $token = bin2hex(random_bytes(32));
  db()->prepare('UPDATE users SET email = ?, display_name = ?, email_verified = 0, verify_token = ? WHERE id = ?')
      ->execute([$email, $name, $token, $u['id']]);
  @send_verification_email($email, $name, $token);
} else {
  db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $u['id']]);
}

ok([
  'user' => ['id' => (int)$u['id'], 'email' => $email, 'display_name' => $name, 'role' => $u['role']],
  'email_changed' => $emailChanged,
]);
