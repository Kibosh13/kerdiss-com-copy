CREATE TABLE content_import_state(id INTEGER PRIMARY KEY CHECK(id=1),fingerprint TEXT NOT NULL,imported_at TEXT NOT NULL);
