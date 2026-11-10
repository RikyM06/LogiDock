-- Righe viaggio, seconda versione (Fase 13): ogni bancale caricato va a un cliente preciso.
-- order_id dice a quale richiesta (quindi a quale cliente, cioè a quale tappa) è destinato il bancale.
-- Le richieste non si cancellano mai, quindi è obbligatorio.
-- order_line_id dice quale riga della richiesta copre: serve a calcolare quanto resta da pianificare.
-- È facoltativo e torna NULL se la riga sparisce: quando si modifica una richiesta aperta le sue righe
-- vengono riscritte, e un viaggio annullato che le citava deve restare leggibile nello storico.
-- La tabella è vuota, come trips. Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE trip_items
    ADD COLUMN order_id BIGINT UNSIGNED NOT NULL AFTER pallet_id,
    ADD COLUMN order_line_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD KEY idx_trip_items_order (order_id),
    ADD KEY idx_trip_items_order_line (order_line_id),
    ADD CONSTRAINT fk_trip_items_order FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_trip_items_order_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id)
        ON DELETE SET NULL ON UPDATE RESTRICT;
