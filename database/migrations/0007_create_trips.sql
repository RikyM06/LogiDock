-- Viaggi: un mezzo, una destinazione, una data. Lo stato segue il ciclo di vita del viaggio.
-- L'indice (vehicle_id, planned_date) serve a controllare se un mezzo è già impegnato in quel giorno.

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    destination VARCHAR(255) NOT NULL,
    planned_date DATE NOT NULL,
    driver_name VARCHAR(120) NULL,
    status ENUM('draft', 'planned', 'in_transit', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trips_code (code),
    KEY idx_trips_planned_date_status (planned_date, status),
    KEY idx_trips_vehicle_date (vehicle_id, planned_date),
    KEY idx_trips_created_by (created_by),
    CONSTRAINT fk_trips_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_trips_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
