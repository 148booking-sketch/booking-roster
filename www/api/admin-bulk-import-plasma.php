<?php
/**
 * GET/POST /api/admin-bulk-import-plasma.php   (solo admin — SCRIPT USA E GETTA)
 * Importa in blocco gli artisti di Plasma Concerti (roster preso da
 * plasmaconcerti.com il 2026-07-07). Ogni artista: verified=1, trattativa_riservata=1,
 * manager_user_id = Plasma Concerti, published=0 (in attesa di completamento/approvazione:
 * mancano calendario/telefono/cachet/scheda tecnica, non presenti sul sito sorgente).
 * Idempotente: salta gli stage_name già presenti, si può rilanciare senza duplicare.
 * DA RIMUOVERE dal sito subito dopo l'uso.
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_stats.php';
require_admin();
ensure_trattativa_col();

const MANAGER_ORG = 'Plasma Concerti';

$ARTISTS = [
  ['stage'=>'Nerone', 'bio'=>"Uno dei rapper freestyle più affermati d'Italia, noto per le numerose vittorie in battle e competizioni.",
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['facebook'=>'https://www.facebook.com/Nerooneymcf','instagram'=>'https://www.instagram.com/neroneofficial/','spotify'=>'https://open.spotify.com/intl-it/artist/7kG6A2lZMXeaD5YkubF5Kn']],
  ['stage'=>'Jack The Smoker', 'bio'=>"Una delle voci più credibili dell'hip-hop italiano, conosciuto per la scrittura ricercata e l'abilità tecnica.",
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/jack_the_smoker_/','spotify'=>'https://open.spotify.com/intl-it/artist/0vZAzVAFQL1gKLBPfnXMaS']],
  ['stage'=>'Dani Faiv', 'bio'=>'Una delle voci più riconoscibili del rap italiano, con hit multiplatino all\'attivo.',
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['youtube'=>'https://www.youtube.com/channel/UClM2-KqsHMkE0MKVhU-5fgQ','instagram'=>'https://www.instagram.com/danifaiv/','spotify'=>'https://open.spotify.com/intl-it/artist/0ffKEtMDnROKWyJtXUnLbJ']],
  ['stage'=>'Beba', 'bio'=>'Rapper che ha collaborato con artisti come Lazza e Salmo.',
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['youtube'=>'https://www.youtube.com/channel/UCail6XpIIJvAyrGHSLGX7Bw','instagram'=>'https://www.instagram.com/beb.ounce/','spotify'=>'https://open.spotify.com/intl-it/artist/6ZpOQK1OKdCybuOgMkdbUh']],
  ['stage'=>'Babaman', 'bio'=>"Artista che unisce hip hop e reggae, tra i principali rappresentanti del reggae in Italia.",
    'genres'=>['rap-hiphop','reggae'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/babamanofficial/','facebook'=>'https://www.facebook.com/babamanofficial','spotify'=>'https://open.spotify.com/intl-it/artist/3iYwsCmyIpFw5GLKwL6V77']],
  ['stage'=>'Tära', 'bio'=>"Artista italo-palestinese che unisce l'eredità araba a sonorità R&B contemporanee.",
    'genres'=>['rnb'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/tarawave/','spotify'=>'https://open.spotify.com/intl-it/artist/0ez4Y0vHwlRCrqGIkChC3Q']],
  ['stage'=>'Vaeva', 'bio'=>'Band di cinque elementi formata nel 2022 vicino a Monza, dal sound indie-rock.',
    'genres'=>['indie','rock'], 'formazione'=>'live_band', 'componenti'=>5, 'comune'=>'Monza', 'provincia'=>'MB',
    'socials'=>['instagram'=>'https://www.instagram.com/vaeva.band/','spotify'=>'https://open.spotify.com/intl-it/artist/2xP0ZgiHfJOP8dzz3ij10b']],
  ['stage'=>'Nora Lang', 'bio'=>'Progetto musicale di Eleonora Di Matteo, produttrice e cantautrice orientata alla sperimentazione sonora.',
    'genres'=>['cantautore'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/xo.noralang/','spotify'=>'https://open.spotify.com/intl-it/artist/1dl2ZT5IwLGsyKrOf2SSkh']],
  ['stage'=>'Real Talk', 'bio'=>'Format cult nato nel 2016 in cui rapper si esibiscono su beat inediti.',
    'genres'=>['rap-hiphop','format'], 'formazione'=>'live_dj', 'componenti'=>null,
    'socials'=>['youtube'=>'https://www.youtube.com/@RealTalk','facebook'=>'https://www.facebook.com/realtalkitaly','instagram'=>'https://www.instagram.com/realtalkitaly/','spotify'=>'https://open.spotify.com/intl-it/artist/7MeFbKQT6w9iBMLuHmzVVW']],
  ['stage'=>'Klaus Noir', 'bio'=>"Rapper e producer noto per le metriche complesse e l'estetica horror-noir.",
    'genres'=>['rap-hiphop','hard'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/klausnoir/','facebook'=>'https://www.facebook.com/klausnoir','spotify'=>'https://open.spotify.com/intl-it/artist/49IamDbZMOj9GPux8Z4i9e']],
  ['stage'=>'Macello', 'bio'=>'Rapper bolognese classe 2001, noto per la qualità metrica e i contenuti lirici audaci.',
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>1, 'comune'=>'Bologna', 'provincia'=>'BO',
    'socials'=>['instagram'=>'https://www.instagram.com/socbemben/','spotify'=>'https://open.spotify.com/intl-it/artist/3DzlFEEKyk4jj7FuYnJSZZ']],
  ['stage'=>'Marte', 'bio'=>"Giovane rapper classe 2004 che unisce l'attitudine freestyle a un'estetica trap contemporanea.",
    'genres'=>['rap-hiphop','trap'], 'formazione'=>'live_dj', 'componenti'=>1,
    'socials'=>['instagram'=>'https://www.instagram.com/stayon.mars/','spotify'=>'https://open.spotify.com/intl-it/artist/3IWdXZamAg5bhIa0KyRBNb']],
  ['stage'=>'Cor Veleno', 'bio'=>"Gruppo attivo dagli anni '90, tra i rappresentanti dell'hip-hop italiano nei festival internazionali.",
    'genres'=>['rap-hiphop'], 'formazione'=>'live_dj', 'componenti'=>null,
    'socials'=>['instagram'=>'https://www.instagram.com/corveleno_official/','spotify'=>'https://open.spotify.com/intl-it/artist/2OFhu1uXhK8gutkt7QcF2R']],
  ['stage'=>'Allerta!', 'bio'=>'Band punk toscana emergente, formata nel 2022, con testi di critica sociale.',
    'genres'=>['punk'], 'formazione'=>'live_band', 'componenti'=>null,
    'socials'=>['instagram'=>'https://www.instagram.com/allertagram/','spotify'=>'https://open.spotify.com/intl-it/artist/0hKvP3aJhzfrFwABgGJHUI']],
];

$pdo = db();

$mgr = $pdo->prepare("SELECT id FROM users WHERE role='management' AND (display_name=? OR id IN (SELECT user_id FROM promoter_profiles WHERE org_name=?))");
$mgr->execute([MANAGER_ORG, MANAGER_ORG]);
$managerId = (int) ($mgr->fetchColumn() ?: 0);
if ($managerId <= 0) fail('manager_not_found');

$managerPhone = manager_phone($managerId) ?? '';

$genreIdCache = [];
$genreId = function(string $slug) use ($pdo, &$genreIdCache) {
  if (isset($genreIdCache[$slug])) return $genreIdCache[$slug];
  $st = $pdo->prepare('SELECT id FROM genres WHERE slug = ?');
  $st->execute([$slug]);
  return $genreIdCache[$slug] = (int) ($st->fetchColumn() ?: 0);
};

$results = [];

foreach ($ARTISTS as $a) {
  $stage = $a['stage'];

  $exists = $pdo->prepare('SELECT 1 FROM artist_profiles WHERE stage_name = ?');
  $exists->execute([$stage]);
  if ($exists->fetch()) { $results[] = ['stage'=>$stage, 'status'=>'skipped_exists']; continue; }

  $email = managed_email($stage);
  $pass  = managed_password();
  $comune = $a['comune'] ?? '';
  $prov   = $a['provincia'] ?? null;

  $lat = $lng = null;
  if ($comune !== '') {
    $geo = geocode_comune($comune, $prov);
    if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
  }

  $socialsArr = array_filter($a['socials'], fn($v) => trim((string)$v) !== '');
  $socialsJson = $socialsArr ? json_encode($socialsArr, JSON_UNESCAPED_UNICODE) : null;

  $pdo->beginTransaction();
  try {
    $pdo->prepare(
      'INSERT INTO users (email, password_hash, role, display_name, status, email_verified) VALUES (?, ?, "artist", ?, "active", 1)'
    )->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $stage]);
    $uid = (int) $pdo->lastInsertId();
    $slug = make_slug($stage, $uid);

    $pdo->prepare(
      'INSERT INTO artist_profiles
         (user_id, manager_user_id, stage_name, slug, formazione, componenti, bio, phone, comune, provincia,
          lat, lng, cachet_trattabile, trattativa_riservata, rimborso_tipo, socials, verified, top8, published)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
      $uid, $managerId, $stage, $slug, $a['formazione'], $a['componenti'], $a['bio'], $managerPhone, ($comune ?: null), $prov,
      $lat, $lng, 1, 1, 'da_concordare', $socialsJson, 1, 0, 0,
    ]);

    $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
    foreach ($a['genres'] as $slugG) {
      $gid = $genreId($slugG);
      if ($gid > 0) $ins->execute([$uid, $gid]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    $results[] = ['stage'=>$stage, 'status'=>'error', 'msg'=>$e->getMessage()];
    continue;
  }

  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

  $firstGenreSlug = $a['genres'][0] ?? null;
  [$photo, $photoSource] = resolve_photo_url(null, $socialsArr, $firstGenreSlug, null, $uid);
  if ($photo) {
    try { $pdo->prepare('UPDATE artist_profiles SET photo_url=? WHERE user_id=?')->execute([$photo, $uid]); }
    catch (Throwable $e) {}
  }

  $stats = [];
  if ($socialsArr) {
    try { $stats = refresh_artist_stats($uid, $socialsArr, false); } catch (Throwable $e) {}
  }

  $results[] = ['stage'=>$stage, 'status'=>'created', 'id'=>$uid, 'slug'=>$slug, 'photo_from'=>$photo?$photoSource:null, 'stats_keys'=>array_keys($stats)];
}

ok(['manager_id'=>$managerId, 'results'=>$results]);
