-- Righe viaggio, terza versione (Fase 13, parte 2): le scelte fatte a mano.
-- Un bancale spostato a mano da un viaggio all'altro (o bloccato con il pulsante) diventa "bloccato":
-- il ricalcolo del giorno non lo sposta mai e ricalcola tutto il resto attorno a lui.
-- locked_by e locked_at dicono chi ha deciso e quando; si riempiono e si svuotano insieme a is_locked
-- (lo garantisce il CHECK). Le righe già presenti partono tutte "non bloccate".
-- Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE trip_items
    ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
    ADD COLUMN locked_by BIGINT UNSIGNED NULL AFTER is_locked,
    ADD COLUMN locked_at DATETIME NULL AFTER locked_by,
    ADD KEY idx_trip_items_locked_by (locked_by),
    ADD CONSTRAINT fk_trip_items_locked_by FOREIGN KEY (locked_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_trip_items_locked CHECK (
        (is_locked = 0 AND locked_by IS NULL AND locked_at IS NULL)
        OR (is_locked = 1 AND locked_by IS NOT NULL AND locked_at IS NOT NULL)
    );
