<?php
/**
 * Chi può vedere i cachet e chi può inviare richieste di booking — logica CONDIVISA da
 * artist-get.php, artists-search.php, artists-map.php e artists-featured.php (stessa regola ovunque).
 *   - Cachet/prezzi: admin, l'artista stesso, o un promoter con account in stato "active"
 *     (approvato dall'admin — chi si registra da solo parte "pending").
 *   - Richieste di booking: qualunque promoter loggato (pending o active) o admin — lo stato
 *     va promosso ad "active" solo per vedere i prezzi, non per candidarsi/scrivere.
 */

/** True se il promoter (user_id) è stato approvato dall'admin (status = active). */
function promoter_is_verified(int $userId): bool {
  static $cache = [];
  if (!array_key_exists($userId, $cache)) {
    $st = db()->prepare('SELECT status FROM users WHERE id = ?');
    $st->execute([$userId]);
    $cache[$userId] = $st->fetchColumn() === 'active';
  }
  return $cache[$userId];
}

/** Il $viewer corrente può vedere il cachet di un artista (user_id $artistUserId)? */
function viewer_can_see_prices(?array $viewer, int $artistUserId = 0): bool {
  if (!$viewer) return false;
  if ($viewer['role'] === 'admin') return true;
  if ($artistUserId > 0 && (int) $viewer['id'] === $artistUserId) return true;
  if (in_array($viewer['role'], ['promoter', 'management'], true)) return promoter_is_verified((int) $viewer['id']);
  return false;
}

/** Il $viewer corrente può inviare una richiesta di booking? (promoter/booking anche non verificato) */
function viewer_can_contact(?array $viewer): bool {
  return (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management', 'admin'], true));
}

/** Il $viewer corrente può salvare artisti nei preferiti? (promoter/management/admin) */
function viewer_can_favorite(?array $viewer): bool {
  return (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management', 'admin'], true));
}

/**
 * Crea la tabella `favorites` se non esiste (migration-19 auto-applicata al primo uso, così
 * la feature funziona senza intervento manuale su phpMyAdmin). Idempotente: CREATE ... IF NOT
 * EXISTS non tocca nessun dato. La eseguono gli endpoint che SCRIVONO/LEGGONO i preferiti.
 * Ritorna true se la tabella esiste/è stata creata, false se non è stato possibile crearla
 * (es. l'utente DB non ha il privilegio CREATE): in quel caso serve il passaggio manuale.
 */
function ensure_favorites_table(): bool {
  static $ok = null;
  if ($ok !== null) return $ok;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS favorites (
         user_id         INT UNSIGNED NOT NULL,
         artist_user_id  INT UNSIGNED NOT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (user_id, artist_user_id),
         KEY idx_fav_artist (artist_user_id),
         CONSTRAINT fk_fav_user   FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
         CONSTRAINT fk_fav_artist FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ok = true;
  } catch (Throwable $e) {
    error_log('ensure_favorites_table: ' . $e->getMessage());
    $ok = false;
  }
  return $ok;
}

/** Insieme degli artist_user_id salvati come preferiti dal $viewer (vuoto se non può averne). */
function favorite_artist_ids(?array $viewer): array {
  if (!viewer_can_favorite($viewer)) return [];
  static $cache = [];
  $uid = (int) $viewer['id'];
  if (!array_key_exists($uid, $cache)) {
    // Tollerante alla tabella mancante: se la migration-19 non è ancora stata applicata
    // NON deve rompere ricerca/scheda artista (degradiamo a "nessun preferito").
    try {
      $st = db()->prepare('SELECT artist_user_id FROM favorites WHERE user_id = ?');
      $st->execute([$uid]);
      $cache[$uid] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
      $cache[$uid] = [];
    }
  }
  return $cache[$uid];
}
