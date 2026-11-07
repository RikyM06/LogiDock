-- Richieste dei clienti: una richiesta per cliente, con più righe di prodotto (order_lines).
-- Il codice è interno e progressivo per anno: ORD-AAAA-NNNNNN (tabella number_sequences).
-- status: open = da pianificare; planned / shipped li useranno la pianificazione dei viaggi e le
-- consegne; cancelled = annullata. Una richiesta non si cancella mai: fa parte dello storico.
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    requested_date DATE NOT NULL,
    delivery_date DATE NOT NULL,
    status ENUM('open', 'planned', 'shipped', 'cancelled') NOT NULL DEFAULT 'open',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_code (code),
    KEY idx_orders_customer (customer_id),
    KEY idx_orders_status_delivery (status, delivery_date),
    KEY idx_orders_created_by (created_by),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_orders_dates CHECK (delivery_date >= requested_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
