-- Fase 15 (storico): pagamento delle richieste, chiusura dei viaggi e compenso pagato.
-- Richieste: pagata / non pagata con data, importo e utente (niente pagamenti parziali, scelta del titolare).
-- I tre campi si riempiono insieme e solo per una richiesta spedita (lo garantiscono i CHECK).
-- Viaggi: "concluso" = consegnato; concluded_at c'e' se e solo se lo stato e' completed.
-- Il compenso del trasportatore si puo' segnare pagato solo se il viaggio ha un compenso.
-- Una sola istruzione ALTER per tabella, cosi' e' tutto o niente. Le righe gia' presenti partono "non pagate".
ALTER TABLE orders
    ADD COLUMN paid_at DATETIME NULL,
    ADD COLUMN paid_amount DECIMAL(12,2) NULL,
    ADD COLUMN paid_by BIGINT UNSIGNED NULL,
    ADD KEY idx_orders_paid_by (paid_by),
    ADD CONSTRAINT fk_orders_paid_by FOREIGN KEY (paid_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_orders_payment_complete CHECK (
        (paid_at IS NULL) = (paid_amount IS NULL) AND (paid_at IS NULL) = (paid_by IS NULL)
    ),
    ADD CONSTRAINT chk_orders_paid_only_shipped CHECK (paid_at IS NULL OR status = 'shipped');

ALTER TABLE trips
    ADD COLUMN concluded_at DATETIME NULL,
    ADD COLUMN concluded_by BIGINT UNSIGNED NULL,
    ADD COLUMN compensation_paid_at DATETIME NULL,
    ADD COLUMN compensation_paid_by BIGINT UNSIGNED NULL,
    ADD KEY idx_trips_concluded_by (concluded_by),
    ADD KEY idx_trips_compensation_paid_by (compensation_paid_by),
    ADD CONSTRAINT fk_trips_concluded_by FOREIGN KEY (concluded_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_trips_compensation_paid_by FOREIGN KEY (compensation_paid_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_trips_completed_has_date CHECK ((status = 'completed') = (concluded_at IS NOT NULL)),
    ADD CONSTRAINT chk_trips_concluded_has_user CHECK ((concluded_at IS NULL) = (concluded_by IS NULL)),
    ADD CONSTRAINT chk_trips_compensation_needs_fee CHECK (compensation_paid_at IS NULL OR carrier_fee IS NOT NULL),
    ADD CONSTRAINT chk_trips_compensation_has_user CHECK ((compensation_paid_at IS NULL) = (compensation_paid_by IS NULL));
