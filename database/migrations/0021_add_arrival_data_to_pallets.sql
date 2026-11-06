-- Dati di arrivo del bancale: tipo (misure), documento del fornitore, prezzo d'acquisto per unità
-- e peso lordo segnato dal fornitore. Tutte colonne facoltative nel database perché i bancali di
-- esempio già presenti non le hanno: è l'applicazione (InboundService) a pretenderle per i nuovi arrivi.
-- Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE pallets
    ADD COLUMN pallet_type_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN supplier_document_id BIGINT UNSIGNED NULL AFTER supplier_id,
    ADD COLUMN unit_price DECIMAL(12,4) NULL AFTER initial_quantity,
    ADD COLUMN recorded_weight_kg DECIMAL(12,3) NULL AFTER unit_price,
    ADD KEY idx_pallets_pallet_type (pallet_type_id),
    ADD KEY idx_pallets_supplier_document (supplier_document_id),
    ADD CONSTRAINT fk_pallets_pallet_type FOREIGN KEY (pallet_type_id) REFERENCES pallet_types (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_pallets_supplier_document FOREIGN KEY (supplier_document_id) REFERENCES supplier_documents (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_pallets_unit_price CHECK (unit_price IS NULL OR unit_price >= 0),
    ADD CONSTRAINT chk_pallets_recorded_weight CHECK (recorded_weight_kg IS NULL OR recorded_weight_kg >= 0);
