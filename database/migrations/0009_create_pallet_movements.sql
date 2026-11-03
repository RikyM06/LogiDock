-- Movimenti dei bancali: la tracciabilità. La tabella è APPEND-ONLY, si fa solo INSERT.
--
-- Protezione su due livelli:
--   1. due trigger che bloccano UPDATE e DELETE anche se qualcuno sbaglia un permesso
--   2. in produzione l'utente MySQL dell'applicazione ha solo SELECT e INSERT su questa tabella
-- (TRUNCATE non attiva i trigger: lo impedisce il fatto che le chiavi esterne puntano a pallets.)
--
-- quantity_delta: positivo in ingresso, negativo in uscita, zero per i soli spostamenti.
-- Per ricostruire "da dove arriva e dove è andato" si leggono i movimenti di un bancale in ordine
-- di occurred_at. L'ingresso porta la posizione di arrivo (to_location_id), l'uscita il viaggio (trip_id).
-- Non c'è updated_at: le righe non cambiano mai.

CREATE TABLE IF NOT EXISTS pallet_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pallet_id BIGINT UNSIGNED NOT NULL,
    type ENUM('inbound', 'move', 'outbound', 'adjustment') NOT NULL,
    quantity_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    from_location_id BIGINT UNSIGNED NULL,
    to_location_id BIGINT UNSIGNED NULL,
    trip_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notes VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pallet_movements_pallet_time (pallet_id, occurred_at),
    KEY idx_pallet_movements_trip (trip_id),
    KEY idx_pallet_movements_type_time (type, occurred_at),
    KEY idx_pallet_movements_user (user_id),
    KEY idx_pallet_movements_from_location (from_location_id),
    KEY idx_pallet_movements_to_location (to_location_id),
    CONSTRAINT fk_pallet_movements_pallet FOREIGN KEY (pallet_id) REFERENCES pallets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallet_movements_from_location FOREIGN KEY (from_location_id) REFERENCES warehouse_locations (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallet_movements_to_location FOREIGN KEY (to_location_id) REFERENCES warehouse_locations (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallet_movements_trip FOREIGN KEY (trip_id) REFERENCES trips (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallet_movements_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS trg_pallet_movements_no_update;

CREATE TRIGGER trg_pallet_movements_no_update BEFORE UPDATE ON pallet_movements
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pallet_movements e append-only: UPDATE non consentito';

DROP TRIGGER IF EXISTS trg_pallet_movements_no_delete;

CREATE TRIGGER trg_pallet_movements_no_delete BEFORE DELETE ON pallet_movements
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pallet_movements e append-only: DELETE non consentito';
