<?php
/**
 * POST /api/calendar-check.php   (pubblico, usato dal wizard di registrazione artista)
 * Body: { calendar_url }
 * Verifica che l'indirizzo iCal del Google Calendar sia valido e raggiungibile
 * (un calendario vero, anche senza eventi). Non salva nulla.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_calendar.php';
only('POST');

$in  = body();
$url = trim($in['calendar_url'] ?? '');
if ($url === '') fail('calendar_required');
if (!calendar_is_valid($url)) fail('calendar_invalid', 422);

ok(['valid' => true]);
