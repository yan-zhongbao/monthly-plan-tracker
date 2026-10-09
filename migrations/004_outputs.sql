CREATE TABLE outputs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 date TEXT NOT NULL,
 type TEXT NOT NULL,
 title TEXT NOT NULL,
 note TEXT NOT NULL DEFAULT '',
 links TEXT NOT NULL DEFAULT '[]',
 external_id TEXT NULL,
 source TEXT NOT NULL,
 manual_lock INTEGER NOT NULL DEFAULT 0,
 revision INTEGER NOT NULL DEFAULT 1,
 archived INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(user_id,external_id)
);
CREATE INDEX outputs_dates ON outputs(user_id,date,archived);
