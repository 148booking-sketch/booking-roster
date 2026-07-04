-- migration-19: preferiti dei promoter/management (artisti salvati per il monitoraggio).
-- Incolla in phpMyAdmin > SQL sul DB di produzione.
CREATE TABLE IF NOT EXISTS favorites (
  user_id         INT UNSIGNED NOT NULL,
  artist_user_id  INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, artist_user_id),
  KEY idx_fav_artist (artist_user_id),
  CONSTRAINT fk_fav_user   FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_artist FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
