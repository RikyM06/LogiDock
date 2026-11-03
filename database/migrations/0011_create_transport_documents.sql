-- Documenti di trasporto (bolle e DDT) generati a partire da un viaggio.
-- Il numero è progressivo per tipo e anno (vedi number_sequences) e non si riusa mai.
-- pdf_path resta NULL finché il PDF non viene generato (funzione futura).

CREATE TABLE IF NOT EXISTS transport_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NOT NULL,
    type ENUM('delivery_note', 'ddt') NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    issued_at DATETIME NOT NULL,
    pdf_path VARCHAR(255) NULL,
    issued_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transport_documents_number (type, year, number),
    KEY idx_transport_documents_trip (trip_id, type),
    KEY idx_transport_documents_issued_by (issued_by),
    CONSTRAINT fk_transport_documents_trip FOREIGN KEY (trip_id) REFERENCES trips (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_transport_documents_issued_by FOREIGN KEY (issued_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
