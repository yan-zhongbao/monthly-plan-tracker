CREATE TABLE IF NOT EXISTS outputs (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 date VARCHAR(10) NOT NULL,
 type VARCHAR(20) NOT NULL,
 title VARCHAR(480) NOT NULL,
 note TEXT NOT NULL,
 links TEXT NOT NULL,
 external_id VARCHAR(128) NULL,
 source VARCHAR(32) NOT NULL,
 manual_lock TINYINT NOT NULL DEFAULT 0,
 revision INT NOT NULL DEFAULT 1,
 archived TINYINT NOT NULL DEFAULT 0,
 created_at VARCHAR(40) NOT NULL,
 updated_at VARCHAR(40) NOT NULL,
 UNIQUE KEY outputs_external(user_id,external_id),
 INDEX outputs_dates(user_id,date,archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
