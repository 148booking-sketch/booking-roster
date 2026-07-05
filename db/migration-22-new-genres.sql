-- Migration 22 · nuove macro-categorie di genere (2026-07-05)
-- Auto-applicata da www/api/genres.php al primo uso (INSERT IGNORE idempotente).
-- File di riferimento: non contiene dati sensibili.
INSERT IGNORE INTO genres (slug, name) VALUES
  ('latin','Latin / Reggaeton'),
  ('rnb','R&B'),
  ('country','Country'),
  ('gospel','Gospel / Spiritual'),
  ('ambient','Ambient / Chill'),
  ('ska','Ska');
