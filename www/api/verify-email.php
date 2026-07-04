<?php
/**
 * GET /api/verify-email.php?token=...
 * Attiva l'account (email_verified=1), effettua il login e reindirizza:
 *   artista  → /profilo.html (completa il profilo)
 *   promoter → / (cerca artisti)
 * Link cliccabile dall'email di verifica.
 *
 * NB: il token NON viene invalidato dopo l'uso (a differenza del reset password).
 * Molti client email (Gmail, Outlook, gateway aziendali) "pre-aprono" i link nelle email
 * per scansionarli prima ancora che l'utente li clicchi davvero: con un token usa-e-getta
 * quella scansione consumerebbe il link, e il vero click dell'utente fallirebbe sempre
 * (bug osservato in produzione). Qui il token resta valido: un secondo click sullo stesso
 * link effettua semplicemente il login di nuovo, invece di fallire.
 */
require_once __DIR__ . '/_http.php';

$app   = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
$token = trim($_GET['token'] ?? '');
$redir = function (string $path) use ($app) { header('Location: ' . $app . $path); exit; };

if ($token === '') $redir('/accedi.html?verify=fail');

$st = db()->prepare('SELECT id, role, email, display_name, email_verified FROM users WHERE verify_token = ?');
$st->execute([$token]);
$u = $st->fetch();
if (!$u) $redir('/accedi.html?verify=fail');

if ((int)$u['email_verified'] !== 1) {
  db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$u['id']]);
  // Prima verifica di un promoter/agenzia → email di benvenuto (best-effort).
  if (in_array($u['role'], ['promoter', 'management'], true)) {
    require_once __DIR__ . '/_mail.php';
    notify_promoter_welcome($u['email'], $u['display_name'] ?? '');
  }
}
login_user((int)$u['id']);

$redir($u['role'] === 'artist' ? '/profilo.html?welcome=1' : '/?welcome=1');
