-- Documento di provenienza del fornitore (bolla / DDT del fornitore) con cui arrivano uno o più bancali.
-- Lo stesso numero può tornare in un altro anno o da un altro fornitore, quindi l'unicità è su
-- fornitore + numero + data del documento.
CREATE TABLE IF NOT EXISTS supplier_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(50) NOT NULL,
    document_date DATE NOT NULL,
    received_date DATE NOT NULL,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_documents_number (supplier_id, number, document_date),
    KEY idx_supplier_documents_received (received_date),
    KEY idx_supplier_documents_created_by (created_by),
    CONSTRAINT fk_supplier_documents_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_supplier_documents_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
