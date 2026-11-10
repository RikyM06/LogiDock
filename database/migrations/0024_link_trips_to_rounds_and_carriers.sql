-- Viaggi, seconda versione (Fase 13). Un viaggio non va più verso una sola destinazione: fa un giro
-- con più tappe (i clienti dei bancali caricati), quindi "destination" lascia il posto a round_id.
-- carrier_id e carrier_fee sono la fotografia, al momento della conferma, di chi fa il viaggio e del
-- suo compenso fisso: se domani cambia la tariffa del trasportatore, i viaggi già confermati restano uguali.
-- La tabella è vuota (finora nessuna parte del programma la usava), quindi round_id può nascere
-- NOT NULL senza un valore di partenza: se ci fossero righe, la migrazione si fermerebbe sulla chiave esterna.
-- Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE trips
    DROP COLUMN destination,
    ADD COLUMN round_id BIGINT UNSIGNED NOT NULL AFTER code,
    ADD COLUMN carrier_id BIGINT UNSIGNED NULL AFTER vehicle_id,
    ADD COLUMN carrier_fee DECIMAL(10,2) NULL AFTER carrier_id,
    ADD KEY idx_trips_round (round_id),
    ADD KEY idx_trips_carrier (carrier_id),
    ADD CONSTRAINT fk_trips_round FOREIGN KEY (round_id) REFERENCES rounds (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_trips_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_trips_carrier_fee CHECK (carrier_fee IS NULL OR carrier_fee >= 0);
