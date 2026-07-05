<?php
/**
 * POST /api/artist-eligibility-check.php   (pubblico, usato dal wizard di registrazione artista
 * e dalla dashboard booking per aggiungere un artista)
 * Body: { itunes }
 * Requisito minimo per candidarsi come artista su Booking Roster: almeno 2 brani pubblicati
 * negli ultimi 12 mesi E almeno 6 brani totali (anche feat./collab.), da catalogo Apple Music/iTunes.
 * Usa la iTunes Search API pubblica (nessuna chiave/credenziale richiesta).
 */
require_once __DIR__ . '/_itunes.php';
only('POST');

$in  = body();
$url = trim($in['itunes'] ?? '');
ok(itunes_eligibility($url));
