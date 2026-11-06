-- Un veicolo ha un tipo di rimorchio (misure) e un proprietario. Entrambi opzionali: i veicoli
-- esistenti restano validi. Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE vehicles
    ADD COLUMN trailer_type_id BIGINT UNSIGNED NULL AFTER max_pallets,
    ADD COLUMN carrier_id BIGINT UNSIGNED NULL AFTER trailer_type_id,
    ADD KEY idx_vehicles_trailer_type (trailer_type_id),
    ADD KEY idx_vehicles_carrier (carrier_id),
    ADD CONSTRAINT fk_vehicles_trailer_type FOREIGN KEY (trailer_type_id) REFERENCES trailer_types (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_vehicles_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;
