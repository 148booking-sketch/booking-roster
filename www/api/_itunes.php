<?php
/**
 * Verifica idoneità artista via catalogo Apple Music/iTunes (API pubblica, nessuna chiave).
 * Requisito minimo per il roster: almeno 2 brani pubblicati negli ultimi 12 mesi
 * E almeno 6 brani totali sul profilo (anche feat./collab.). Logica CONDIVISA da
 * artist-eligibility-check.php (wizard registrazione) e management-create-artist.php.
 */
require_once __DIR__ . '/_http.php';

/**
 * Ritorna ['eligible'=>bool, 'track_count'=>int (ultimi 12 mesi), 'total_count'=>int,
 * 'artist_name'=>string] oppure lancia fail().
 * $url = link al profilo artista Apple Music/iTunes.
 */
function itunes_eligibility(string $url): array {
  // Link Apple Music: .../artist/nome/123456  — o vecchio formato itunes.apple.com/.../id123456
  if (!preg_match('#(?:/artist/[^/]+/|[?&]id=|/id)(\d+)#', $url, $m)) fail('itunes_url_invalid');
  $artistId = (int) $m[1];

  $lu = 'https://itunes.apple.com/lookup?' . http_build_query([
    'id' => $artistId, 'entity' => 'song', 'limit' => 200, 'country' => 'IT',
  ]);
  $ch = curl_init($lu);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200 || $res === '') fail('itunes_lookup_failed', 502);

  $j = json_decode($res, true);
  $results = $j['results'] ?? [];
  $artistName = null;
  foreach ($results as $r) {
    if (($r['wrapperType'] ?? '') === 'artist') { $artistName = $r['artistName'] ?? null; break; }
  }
  if ($artistName === null) fail('itunes_artist_not_found', 404);

  $cutoff = date('Y-m-d', strtotime('-12 months'));
  $recentCount = 0; $totalCount = 0;
  foreach ($results as $r) {
    if (($r['wrapperType'] ?? '') !== 'track') continue;
    $totalCount++;
    $rd = substr((string) ($r['releaseDate'] ?? ''), 0, 10);
    if ($rd !== '' && $rd >= $cutoff) $recentCount++;
  }

  return [
    'eligible'    => $recentCount >= 2 && $totalCount >= 6,
    'track_count' => $recentCount,
    'total_count' => $totalCount,
    'artist_name' => $artistName,
  ];
}
