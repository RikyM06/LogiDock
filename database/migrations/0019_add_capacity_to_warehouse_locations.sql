-- Capacità di una posizione in bancali. Vuota = vale la capacità predefinita
-- (impostazione location_default_capacity).
ALTER TABLE warehouse_locations
    ADD COLUMN capacity SMALLINT UNSIGNED NULL AFTER description,
    ADD CONSTRAINT chk_warehouse_locations_capacity CHECK (capacity IS NULL OR capacity > 0);
