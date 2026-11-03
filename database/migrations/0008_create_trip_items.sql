-- Righe viaggio: i bancali (o parte della loro quantità) assegnati a un viaggio.
-- weight_kg è una fotografia del peso al momento dell'assegnazione: così il controllo di portata
-- resta coerente anche se in seguito cambia il peso unitario del prodotto.
-- Che un bancale stia in un solo viaggio attivo per volta lo controlla il service (con lock),
-- perché un vincolo UNIQUE su pallet_id impedirebbe di riassegnarlo dopo un viaggio annullato.

CREATE TABLE IF NOT EXISTS trip_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NOT NULL,
    pallet_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    weight_kg DECIMAL(12,3) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trip_items_trip_pallet (trip_id, pallet_id),
    KEY idx_trip_items_pallet (pallet_id),
    CONSTRAINT fk_trip_items_trip FOREIGN KEY (trip_id) REFERENCES trips (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_trip_items_pallet FOREIGN KEY (pallet_id) REFERENCES pallets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_trip_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_trip_items_weight CHECK (weight_kg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
