-- migration-20: thread messaggi sulle richieste + dedup promemoria evento.
-- NB: entrambe le tabelle vengono AUTO-CREATE dal codice al primo uso
-- (api/booking-messages.php e send_event_reminders in api/_mail.php);
-- questo file esiste solo come riferimento/allineamento manuale.
CREATE TABLE IF NOT EXISTS booking_messages (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id     INT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  body           TEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_req (request_id),
  CONSTRAINT fk_bm_req    FOREIGN KEY (request_id)     REFERENCES booking_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_bm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_reminders (
  request_id INT UNSIGNED NOT NULL,
  sent_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id),
  CONSTRAINT fk_rem_req FOREIGN KEY (request_id) REFERENCES booking_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
