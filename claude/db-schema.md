# Databázové schéma STDozor

Kompletní schéma MariaDB 11.2 databáze. Nette verze používá **stejnou databázi** jako Symfony verze.

> **Zdroj:** `mariadb-dump --no-data` z produkční DB (2026-02-19)
> **Engine:** InnoDB, charset utf8mb4

---

## Přehled tabulek

| Tabulka | Popis | Relace |
|---------|-------|--------|
| `user` | Uživatelé | - |
| `profile` | Profily uživatelů | OneToOne → user |
| `email_verification_token` | Verifikační tokeny | ManyToOne → user |
| `reset_password_request` | Tokeny pro reset hesla | ManyToOne → user |
| `user_webauthn_credentials` | WebAuthn/Passkey credentials | ManyToOne → user |
| `project` | Projekty | ManyToOne owner → user |
| `project_member` | Členové projektů | ManyToOne → user, project |
| `project_invitation` | Pozvánky do projektů | ManyToOne → project, user |
| `category` | Kategorie (stromová struktura) | ManyToOne → project, self-ref parent |
| `category_permission` | Oprávnění ke kategoriím | ManyToOne → category, project_member |
| `item` | Položky (záznamy) | ManyToOne → category, user |
| `attachment` | Přílohy k položkám | ManyToOne → item, user |
| `role` | Referenční tabulka rolí | - |
| `project_template` | Šablony projektů | - |
| `project_template_category` | Kategorie šablon | ManyToOne → project_template |

**Symfony-specifické tabulky (NEPŘENÁŠET):**
- `doctrine_migration_versions` — Doctrine migrations tracking
- `messenger_messages` — Symfony Messenger fronta

---

## Tabulky — detaily

### user

Hlavní tabulka uživatelů. Obsahuje i 2FA data (TOTP, backup codes, trusted device).

```sql
CREATE TABLE `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(180) NOT NULL,
  `password` varchar(255) NOT NULL,
  `roles` longtext NOT NULL CHECK (json_valid(`roles`)),       -- JSON array: ["ROLE_USER"]
  `is_verified` tinyint(4) NOT NULL,                           -- bool: email ověřen
  `created_at` datetime NOT NULL,
  `totp_secret` varchar(255) DEFAULT NULL,                     -- TOTP secret (null = neaktivní)
  `backup_codes` longtext NOT NULL DEFAULT '[]' CHECK (json_valid(`backup_codes`)),  -- JSON array hashů
  `trusted_version` int(11) NOT NULL,                          -- Verze pro invalidaci trusted devices
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_IDENTIFIER_EMAIL` (`email`)
);
```

**Poznámky pro Nette:**
- `roles` — v Nette Security se role řeší jinak (přes `getRoles()` v Identity)
- `password` — bcrypt hash, Nette používá `Nette\Security\Passwords`
- `totp_secret` — null = TOTP neaktivní, jinak base32 secret
- `backup_codes` — JSON array bcrypt hashů záložních kódů
- `trusted_version` — inkrementace zneplatní všechna důvěryhodná zařízení

---

### profile

```sql
CREATE TABLE `profile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
);
```

---

### email_verification_token

DB-backed tokeny pro ověření emailu (NE signed URL).

```sql
CREATE TABLE `email_verification_token` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,                    -- random_bytes(32) → hex
  `expires_at` datetime NOT NULL,                  -- 1 hodina od vytvoření
  `used` tinyint(4) NOT NULL,                      -- bool: single-use
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`token`),
  KEY (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
);
```

---

### reset_password_request

Tokeny pro reset hesla (selector/verifier pattern).

```sql
CREATE TABLE `reset_password_request` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selector` varchar(20) NOT NULL,             -- Veřejná část tokenu (v URL)
  `hashed_token` varchar(100) NOT NULL,        -- Hash tajné části
  `requested_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
);
```

**Poznámka:** Symfony používá `symfonycasts/reset-password-bundle` se selector/verifier pattern. Pro Nette implementovat vlastní — nebo zjednodušit na token pattern jako u email verification.

---

### user_webauthn_credentials

WebAuthn credentials — 2FA klíče i passkeys.

```sql
CREATE TABLE `user_webauthn_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,                      -- Uživatelský název ("Můj YubiKey")
  `credential_id` longtext NOT NULL,                 -- Base64url credential ID
  `is_passkey` tinyint(4) NOT NULL,                  -- bool: true = passwordless, false = 2FA
  `transport` varchar(50) NOT NULL,                  -- 'usb', 'internal', 'ble', 'nfc'
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `counter` int(11) NOT NULL,                        -- Signature counter (replay protection)
  `user_handle` varchar(255) NOT NULL,               -- WebAuthn user handle
  `type` varchar(20) NOT NULL,                       -- 'public-key'
  `transports` longtext NOT NULL CHECK (json_valid(`transports`)),  -- JSON array transportů
  `attestation_type` varchar(50) NOT NULL,           -- 'none', 'packed', etc.
  `trust_path` longtext NOT NULL,                    -- Serialized trust path
  `aaguid` varchar(36) NOT NULL,                     -- Authenticator AAGUID
  `credential_public_key` longtext NOT NULL,         -- Base64 public key
  `backup_eligible` tinyint(4) DEFAULT NULL,         -- bool: multi-device credential
  `backup_status` tinyint(4) DEFAULT NULL,           -- bool: is backed up
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`),
  KEY (`credential_id`(768)),
  FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
);
```

**Poznámka:** Webauthn-lib serializuje data jinak v různých verzích. Pro Nette může být potřeba upravit formát uložení.

---

### project

```sql
CREATE TABLE `project` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(255) NOT NULL,                     -- Enum: PLANNING, ACTIVE, PAUSED, COMPLETED, CANCELLED
  `is_public` tinyint(4) NOT NULL,                    -- bool
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `currency` varchar(3) NOT NULL,                     -- ISO 4217: CZK, EUR, USD
  `owner_id` int(11) NOT NULL,
  `estimated_amount_cents` bigint(20) DEFAULT NULL,   -- Celkový odhad (v haléřích)
  `total_amount_cents` bigint(20) DEFAULT NULL,       -- Celkové náklady (v haléřích)
  PRIMARY KEY (`id`),
  FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`)
);
```

**Poznámky:**
- `status` — uložen jako string, v PHP `ProjectStatus` enum
- `estimated_amount_cents`, `total_amount_cents` — v haléřích (centesimal), nullable

---

### project_member

Vazba uživatel ↔ projekt s rolemi.

```sql
CREATE TABLE `project_member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `roles` longtext NOT NULL CHECK (json_valid(`roles`)),  -- JSON: ["OWNER"], ["SUPERVISOR", "CONTRACTOR"]
  `invited_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `invited_by_id` int(11) DEFAULT NULL,
  `has_global_category_access` tinyint(4) NOT NULL,       -- bool: přístup ke všem kategoriím
  PRIMARY KEY (`id`),
  UNIQUE KEY (`user_id`, `project_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user` (`id`),
  FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invited_by_id`) REFERENCES `user` (`id`)
);
```

**Poznámky:**
- `roles` — JSON array enum hodnot ProjectRole (OWNER, SUPERVISOR, CONTRACTOR, INVESTOR)
- `has_global_category_access` — true = vidí všechny kategorie, false = jen vybrané (viz category_permission)

---

### project_invitation

Pozvánky do projektu (token-based).

```sql
CREATE TABLE `project_invitation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(180) NOT NULL,
  `roles` longtext NOT NULL CHECK (json_valid(`roles`)),     -- JSON: ["CONTRACTOR"]
  `token` varchar(64) NOT NULL,                               -- random_bytes(32) → hex
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `used` tinyint(4) NOT NULL,                                 -- bool
  `project_id` int(11) NOT NULL,
  `invited_by_id` int(11) DEFAULT NULL,
  `project_member_id` int(11) DEFAULT NULL,                   -- Vyplněno po přijetí
  `category_ids` longtext NOT NULL CHECK (json_valid(`category_ids`)),  -- JSON: [1, 5, 12]
  PRIMARY KEY (`id`),
  UNIQUE KEY (`token`),
  UNIQUE KEY (`project_member_id`),
  KEY (`email`, `project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_member_id`) REFERENCES `project_member` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`invited_by_id`) REFERENCES `user` (`id`)
);
```

---

### category

Stromová struktura kategorií (self-referencing parent_id).

```sql
CREATE TABLE `category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `display_order` int(11) NOT NULL,                           -- Pořadí v rámci rodiče
  `status` varchar(255) NOT NULL,                             -- Enum: PLANNED, IN_PROGRESS, COMPLETED
  `estimated_amount` decimal(10,2) DEFAULT NULL,
  `actual_amount` decimal(10,2) DEFAULT NULL,
  `manual_amount_override` tinyint(4) NOT NULL,               -- bool: ruční přepsání částky
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `estimated_completion_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,                           -- null = root kategorie
  PRIMARY KEY (`id`),
  KEY (`project_id`, `parent_id`),
  FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
);
```

**Poznámky:**
- Stromová struktura: `parent_id = NULL` → root, jinak child
- `actual_amount` — buď manuální (manual_amount_override=1) nebo sum z items
- `status` — `CategoryStatus` enum

---

### category_permission

Granulární oprávnění ke kategoriím pro členy projektu.

```sql
CREATE TABLE `category_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `can_view` tinyint(4) NOT NULL,
  `can_edit` tinyint(4) NOT NULL,
  `can_delete` tinyint(4) NOT NULL,
  `granted_at` datetime NOT NULL,
  `project_member_id` int(11) NOT NULL,
  `granted_by_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`project_member_id`, `category_id`),
  FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_member_id`) REFERENCES `project_member` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granted_by_id`) REFERENCES `user` (`id`)
);
```

**Poznámka:** Použito jen pokud `project_member.has_global_category_access = false`.

---

### item

Položky (záznamy) v kategoriích — stavební deník.

```sql
CREATE TABLE `item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` longtext NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,                        -- Částka (jen owner vidí)
  `item_date` date NOT NULL,                                  -- Datum záznamu
  `is_control_day` tinyint(4) NOT NULL,                       -- bool: kontrolní den
  `include_in_construction_log` tinyint(4) NOT NULL,          -- bool: zahrnout do stavebního deníku
  `weather` varchar(500) DEFAULT NULL,                        -- Počasí (text)
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `created_by_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`category_id`, `item_date`),
  FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
);
```

---

### attachment

Přílohy k položkám (soubory, obrázky).

```sql
CREATE TABLE `attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,                    -- Originální název souboru
  `file_path` varchar(500) NOT NULL,                   -- Relativní cesta v uploads/
  `file_size` int(11) NOT NULL,                        -- Velikost v bytech
  `mime_type` varchar(100) NOT NULL,                   -- image/jpeg, application/pdf, ...
  `uploaded_at` datetime NOT NULL,
  `item_id` int(11) NOT NULL,
  `uploaded_by_id` int(11) NOT NULL,
  `image_width` int(11) DEFAULT NULL,                  -- Rozměry (jen pro obrázky)
  `image_height` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`item_id`) REFERENCES `item` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by_id`) REFERENCES `user` (`id`)
);
```

**Poznámka:** Soubory jsou na filesystému v `www/uploads/` (nebo `public/uploads/`). Pro Nette: `www/uploads/`.

---

### role

Referenční tabulka rolí.

```sql
CREATE TABLE `role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,          -- Zobrazený název
  `code` varchar(30) NOT NULL,          -- OWNER, SUPERVISOR, CONTRACTOR, INVESTOR
  `description` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`code`)
);
```

**Poznámka:** Tato tabulka je referenční (read-only). Role jsou primárně jako PHP enum `ProjectRole`. Tabulka slouží pro UI (popis, překlad).

---

### project_template

Šablony pro vytváření projektů s předdefinovanými kategoriemi.

```sql
CREATE TABLE `project_template` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`code`)
);
```

---

### project_template_category

Kategorie v šablonách (stromová struktura přes parent_name).

```sql
CREATE TABLE `project_template_category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `display_order` int(11) NOT NULL,
  `parent_name` varchar(200) DEFAULT NULL,    -- Název rodiče (ne ID — šablony nemají ID předem)
  `template_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`template_id`) REFERENCES `project_template` (`id`)
);
```

**Poznámka:** `parent_name` místo `parent_id` protože při vytváření projektu ze šablony se teprve vytvářejí nové kategorie (ještě nemají ID).

---

## ER Diagram (textový)

```
user ──────────┬─────── profile (1:1)
               ├─────── email_verification_token (1:N)
               ├─────── reset_password_request (1:N)
               ├─────── user_webauthn_credentials (1:N)
               ├─────── project (1:N owner)
               └─────── project_member (N:M → project)
                              │
                              ├── category_permission (1:N)
                              └── project_invitation (1:1)

project ───────┬─────── category (1:N)
               │            │
               │            ├── category (self-ref parent, N-level tree)
               │            ├── category_permission (1:N)
               │            └── item (1:N)
               │                  └── attachment (1:N)
               │
               ├─────── project_member (1:N)
               └─────── project_invitation (1:N)

project_template ──── project_template_category (1:N)
role (standalone reference table)
```

---

## Indexy (důležité)

| Tabulka | Index | Typ | Účel |
|---------|-------|-----|------|
| `user` | `UNIQ_IDENTIFIER_EMAIL` | UNIQUE | Login lookup |
| `category` | `idx_category_project_parent` | INDEX | Stromový dotaz |
| `item` | `idx_item_category_date` | INDEX | Chronologické řazení |
| `email_verification_token` | `idx_expires_at` | INDEX | Cleanup starých tokenů |
| `project_invitation` | `idx_invitation_email_project` | INDEX | Kontrola duplicit |
| `category_permission` | `uniq_member_category` | UNIQUE | 1 oprávnění per člen+kategorie |
| `project_member` | `uniq_project_member` | UNIQUE | 1 členství per uživatel+projekt |
| `user_webauthn_credentials` | `idx_credential_id` | INDEX(768) | WebAuthn assertion lookup |

---

## SQL pro Nette (inicializace)

Pro Nette verzi vytvořit **stejné tabulky** (bez Symfony-specifických).
Doporučený postup:

```bash
# 1. Vytvořit DB
docker compose exec mariadb mysql -u root -p -e "CREATE DATABASE stdozor_nette CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 2. Importovat schéma (bez doctrine_migration_versions a messenger_messages)
# Nebo použít Adminer migrations / Nette Database migrations
```

Alternativně sdílet stejnou DB jako Symfony verze (pro porovnání výsledků).

---

## Datové typy — mapování na PHP

| SQL typ | PHP typ | Nette Database | Poznámka |
|---------|---------|---------------|----------|
| `int NOT NULL` | `int` | `$row->id` | |
| `int DEFAULT NULL` | `?int` | `$row->parent_id` | |
| `varchar NOT NULL` | `string` | `$row->name` | |
| `varchar DEFAULT NULL` | `?string` | `$row->description` | |
| `longtext NOT NULL` | `string` | `$row->description` | |
| `longtext DEFAULT NULL` | `?string` | `$row->bio` | |
| `tinyint NOT NULL` | `bool` | `(bool) $row->is_verified` | Nette Database vrací int! |
| `tinyint DEFAULT NULL` | `?bool` | | WebAuthn backup_eligible |
| `datetime NOT NULL` | `\DateTimeImmutable` | `$row->created_at` | Nette Database vrací DateTime |
| `datetime DEFAULT NULL` | `?\DateTimeImmutable` | | |
| `date NOT NULL` | `\DateTimeImmutable` | `$row->item_date` | |
| `decimal(10,2)` | `string` (nebo `float`) | `$row->amount` | Nette vrací string pro decimal |
| `bigint` | `int` | `$row->estimated_amount_cents` | |
| `longtext JSON` | `array` | `json_decode($row->roles)` | Nette Database NEparsuje JSON auto! |

**DŮLEŽITÉ pro Nette Database:**
- Nette Database Explorer vrací `ActiveRow` objekty
- JSON sloupce (`roles`, `backup_codes`, `transports`) — MUSÍ se ručně `json_decode()`
- Boolean sloupce — Nette vrací `int` (0/1), přetypovat na `bool`
- DateTime — Nette vrací `Nette\Utils\DateTime` (extends `\DateTime`)
