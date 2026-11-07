-- Righe di una richiesta: prodotto, quantità (nell'unità di misura del prodotto) e prezzo di vendita
-- per unità. Il prezzo è una "fotografia" del momento della richiesta: se domani cambia il listino,
-- le richieste già fatte non cambiano. Un prodotto compare una sola volta per richiesta.
CREATE TABLE IF NOT EXISTS order_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_price DECIMAL(12,4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_lines_order_product (order_id, product_id),
    KEY idx_order_lines_product (product_id),
    CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_order_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_order_lines_quantity CHECK (quantity > 0),
    CONSTRAINT chk_order_lines_unit_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
