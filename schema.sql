-- Schemat bazy Biblioteka (MySQL 8 / InnoDB)
--
-- Do wczytania z linii poleceń:  mysql -u UZYTKOWNIK -p NAZWA_BAZY < schema.sql
-- Aplikacja tworzy te same tabele sama przy pierwszym połączeniu (config.php
-- → db_tabele()), więc plik jest alternatywą dla tego mechanizmu, a nie
-- warunkiem działania. Kolumny dopisywane migracjami (db_migracje()) są tu
-- już włączone do definicji tabel, żeby schemat był kompletny od razu.

CREATE TABLE IF NOT EXISTS bib_sesje (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id    INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token_hash),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bib_login_attempts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    klucz      VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_klucz_czas (klucz, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Katalog papierowy — jeden wiersz = jeden fizyczny egzemplarz
CREATE TABLE IF NOT EXISTS bib_ksiazki (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL DEFAULT 1,
    isbn             VARCHAR(20) NULL,
    tytul            VARCHAR(500) NOT NULL,
    podtytul         VARCHAR(500) NULL,
    autor            VARCHAR(300) NOT NULL DEFAULT '',
    rok              VARCHAR(10) NULL,
    wydawca          VARCHAR(200) NULL,
    liczba_stron     SMALLINT UNSIGNED NULL,
    format           VARCHAR(20) NULL,
    szerokosc        VARCHAR(20) NULL,
    ocena            TINYINT UNSIGNED NULL,
    jezyk            VARCHAR(20) NULL,
    oryginalny_tytul VARCHAR(500) NULL,
    opis             TEXT NULL,
    okladka          VARCHAR(255) NULL,
    zdjecie          VARCHAR(255) NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'chce_przeczytac',
    notatki          TEXT NULL,
    zrodlo           VARCHAR(20) NOT NULL DEFAULT 'recznie',
    data_dodania     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_edycji      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_isbn (isbn),
    KEY idx_tytul (tytul),
    KEY idx_autor (autor),
    KEY idx_status (status),
    KEY idx_dodania (data_dodania)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache lookupów ISBN (dane = JSON metryczki albo NULL = brak w bazach)
CREATE TABLE IF NOT EXISTS bib_isbn_cache (
    isbn    VARCHAR(20) NOT NULL,
    zrodlo  VARCHAR(20) NOT NULL DEFAULT '',
    dane    TEXT NULL,
    pobrano DATETIME NOT NULL,
    PRIMARY KEY (isbn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagi — nazwa w małych literach, „SciFi" i „scifi" to jeden tag
CREATE TABLE IF NOT EXISTS bib_tagi (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL DEFAULT 1,
    nazwa      VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_nazwa (user_id, nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Półki — nazwane grupy książek
CREATE TABLE IF NOT EXISTS bib_polk (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL DEFAULT 1,
    nazwa      VARCHAR(80) NOT NULL,
    opis       VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_nazwa (user_id, nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bib_ksiazka_tagi (
    ksiazka_id INT UNSIGNED NOT NULL,
    tag_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (ksiazka_id, tag_id),
    KEY idx_tag (tag_id),
    CONSTRAINT fk_kt_ksiazka FOREIGN KEY (ksiazka_id)
        REFERENCES bib_ksiazki (id) ON DELETE CASCADE,
    CONSTRAINT fk_kt_tag FOREIGN KEY (tag_id)
        REFERENCES bib_tagi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bib_ksiazka_polk (
    ksiazka_id INT UNSIGNED NOT NULL,
    polka_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (ksiazka_id, polka_id),
    KEY idx_polka (polka_id),
    CONSTRAINT fk_kp_ksiazka FOREIGN KEY (ksiazka_id)
        REFERENCES bib_ksiazki (id) ON DELETE CASCADE,
    CONSTRAINT fk_kp_polka FOREIGN KEY (polka_id)
        REFERENCES bib_polk (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wypożyczenia: data_zwrotu NULL = wciąż wypożyczona; po zwrocie wiersz zostaje
CREATE TABLE IF NOT EXISTS bib_wypozyczenia (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL DEFAULT 1,
    ksiazka_id        INT UNSIGNED NOT NULL,
    osoba             VARCHAR(120) NOT NULL,
    data_wypozyczenia DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_zwrotu       DATETIME NULL,
    uwagi             VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_ksiazka (ksiazka_id),
    KEY idx_aktywne (data_zwrotu),
    KEY idx_osoba (osoba),
    CONSTRAINT fk_wyp_ksiazka FOREIGN KEY (ksiazka_id)
        REFERENCES bib_ksiazki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ebooki — drugi, równoległy katalog; pliki zostają u użytkownika, tu tylko metadane.
-- hash_pliku (SHA-256) deduplikuje import folderu; powiazana_ksiazka_id wiąże ebooka
-- z papierowym egzemplarzem (SET NULL — katalog papierowy jest nadrzędny).
CREATE TABLE IF NOT EXISTS bib_ebooki (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id              INT UNSIGNED NOT NULL DEFAULT 1,
    isbn                 VARCHAR(20) NULL,
    tytul                VARCHAR(500) NOT NULL,
    podtytul             VARCHAR(500) NULL,
    autor                VARCHAR(300) NOT NULL DEFAULT '',
    rok                  VARCHAR(10) NULL,
    wydawca              VARCHAR(200) NULL,
    liczba_stron         SMALLINT UNSIGNED NULL,
    jezyk                VARCHAR(20) NULL,
    oryginalny_tytul     VARCHAR(500) NULL,
    opis                 TEXT NULL,
    okladka              VARCHAR(255) NULL,
    format               VARCHAR(10) NOT NULL DEFAULT 'epub',
    status               VARCHAR(20) NOT NULL DEFAULT 'chce_przeczytac',
    ocena                TINYINT UNSIGNED NULL,
    notatki              TEXT NULL,
    hash_pliku           CHAR(64) NULL,
    nazwa_pliku          VARCHAR(255) NULL,
    folder               VARCHAR(120) NULL,
    powiazana_ksiazka_id INT UNSIGNED NULL,
    zrodlo               VARCHAR(20) NOT NULL DEFAULT 'recznie',
    data_dodania         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_edycji          DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_isbn (isbn),
    KEY idx_hash (hash_pliku),
    KEY idx_tytul (tytul),
    KEY idx_autor (autor),
    KEY idx_status (status),
    KEY idx_format (format),
    KEY idx_powiazana (powiazana_ksiazka_id),
    CONSTRAINT fk_eb_ksiazka FOREIGN KEY (powiazana_ksiazka_id)
        REFERENCES bib_ksiazki (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Powiązania ebook↔tag / ebook↔półka — osobne tabele, katalogi są niezależne
CREATE TABLE IF NOT EXISTS bib_ebook_tagi (
    ebook_id INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (ebook_id, tag_id),
    KEY idx_tag (tag_id),
    CONSTRAINT fk_et_ebook FOREIGN KEY (ebook_id)
        REFERENCES bib_ebooki (id) ON DELETE CASCADE,
    CONSTRAINT fk_et_tag FOREIGN KEY (tag_id)
        REFERENCES bib_tagi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bib_ebook_polk (
    ebook_id INT UNSIGNED NOT NULL,
    polka_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ebook_id, polka_id),
    KEY idx_polka (polka_id),
    CONSTRAINT fk_ep_ebook FOREIGN KEY (ebook_id)
        REFERENCES bib_ebooki (id) ON DELETE CASCADE,
    CONSTRAINT fk_ep_polka FOREIGN KEY (polka_id)
        REFERENCES bib_polk (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
