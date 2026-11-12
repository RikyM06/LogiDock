-- Documenti di trasporto, seconda versione (Fase 14): i PDF emessi alla partenza di un viaggio.
--
-- Tre tipi di documento (la "bolla di consegna" e il DDT sono la stessa cosa in italiano, quindi
-- delivery_note sparisce):
--   load_list     documento di carico del camion (nessun prezzo), uno per viaggio
--   ddt           documento di trasporto di UN cliente, con i prezzi di vendita, uno per cliente del viaggio
--   compensation  prospetto del compenso del trasportatore, uno per viaggio (solo per l'admin)
--
-- Il PDF si salva DENTRO il database (pdf_content) invece che in un file: la partenza del viaggio e i
-- suoi documenti nascono nella stessa transazione (o c'è tutto o non c'è niente), il backup del database
-- porta con sé anche i documenti e in produzione non c'è nessuna cartella in più da creare e proteggere.
-- Un documento emesso non si rigenera mai: si rilegge sempre lo stesso PDF, con lo stesso numero.
--
-- La tabella non è mai stata usata prima di questa fase (è vuota): per questo si può aggiungere una
-- colonna NOT NULL senza valore di partenza e togliere la vecchia pdf_path.
-- customer_id serve solo per i DDT; per gli altri tipi resta vuoto.
-- Una sola istruzione ALTER, così è tutto o niente.
ALTER TABLE transport_documents
    MODIFY COLUMN type ENUM('load_list', 'ddt', 'compensation') NOT NULL,
    DROP COLUMN pdf_path,
    ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER trip_id,
    ADD COLUMN pdf_content MEDIUMBLOB NOT NULL AFTER issued_at,
    ADD KEY idx_transport_documents_customer (customer_id),
    ADD CONSTRAINT fk_transport_documents_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_transport_documents_customer CHECK (
        (type = 'ddt' AND customer_id IS NOT NULL) OR (type <> 'ddt' AND customer_id IS NULL)
    );
