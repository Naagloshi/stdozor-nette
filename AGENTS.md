# AGENTS.md - STDozor Nette

Instrukce pro AI nástroje (Claude Code, GitHub Copilot, Cursor).

## Project Overview

- **Název:** Stavební dozor (STDozor) — Nette verze
- **Stack:** PHP 8.3, Nette Framework 3.2+, MariaDB 11.2, Nginx, Docker
- **Jazyk:** Česky (překlady, dokumentace, komunikace)
- **Port z:** Symfony 7.4 (referenční projekt: `/home/elvi/projects/stdozor/`)

## Setup & Development

### Docker

```bash
# Spuštění
docker compose up -d

# PHP příkazy
docker compose exec php php ...

# Composer
docker compose exec php composer install
docker compose exec php composer require nette/...
```

### Nette Console

```bash
# Migrace (pokud se použije)
docker compose exec php php bin/console migrations:migrate

# Cache
docker compose exec php php temp/clear-cache.sh
```

## Coding Standards

### PHP

- **PHP 8.3** — strict types, typed properties, enums, match expressions
- **Nette Coding Standard** — `nette/coding-standard`
- **PHPStan** — level 6+
- **Názvy:** PascalCase (třídy), camelCase (metody, properties), UPPER_CASE (konstanty)

```bash
# Code style check
docker compose exec php vendor/bin/phpcs --standard=vendor/nette/coding-standard/coding-standard-php83.neon src

# PHPStan
docker compose exec php vendor/bin/phpstan analyse src
```

### Nette specifika

- **Presentery** — `App\Presenters\XxxPresenter` (ne Controller)
- **Controls (komponenty)** — `App\Controls\Xxx\XxxControl` — pro komplexní/opakující se UI části
- **Modely/Services** — `App\Model\XxxService` nebo `App\Model\XxxRepository`
- **Formuláře** — `App\Forms\XxxFormFactory` (tovární vzor)
- **Entity** — `App\Model\Entity\Xxx` (pokud Nextras ORM)
- **Šablony** — `app/Presenters/templates/Xxx/action.latte`
- **Konfigurace** — `config/*.neon`

### Architektura: Controls a snippety

**DŮLEŽITÉ:** V Nette NEŘEŠIT vše v presenterech! Použít komponentový přístup:

- **Controls (`Nette\Application\UI\Control`)** — pro znovupoužitelné části UI s vlastní logikou
  - Komplexní formuláře, seznamy, stromy, interaktivní bloky
  - Připojení přes `createComponentXxx()` v presenteru
  - Vykreslení přes `{control xxx}` v Latte
- **Snippety (`{snippet}`)** — pro AJAX překreslení části stránky
  - `$this->redrawControl('snippetName')` v presenteru/controlu
  - Naja.js jako AJAX driver
- **Stimulus controllery** — POUZE pro čistě klientské interakce (tooltip, toggle, galerie, clipboard)
- **Pravidlo:** Server round-trip → Nette snippety. Čistě klientské → Stimulus.

### Latte šablony

- **NIKDY hardcoded texty** — vždy `{_'translation.key'}`
- **Nette Forms rendering** — `{form formName}...{/form}` nebo custom rendering
- **Layout** — `{layout 'layout.latte'}` + `{block content}...{/block}`

### Databáze

- **Nette Database Explorer** nebo **Nextras ORM**
- **Entity typy** musí odpovídat DB schématu (NOT NULL → non-nullable PHP typ)
- **Všechny NOT NULL fieldy** musí mít výchozí hodnotu nebo být v konstruktoru

## Password Validation

**Passphrase přístup** (stejný jako Symfony verze):
- Minimálně **16 znaků**
- **Žádné speciální znaky** nutné
- **NotCompromised** kontrola (HaveIBeenPwned API)

## Project Structure (Nette Web Project)

```
stdozor-nette/
├── app/
│   ├── Bootstrap.php
│   ├── Presenters/
│   │   ├── HomepagePresenter.php
│   │   ├── SignPresenter.php          # Login/Register
│   │   ├── ProjectPresenter.php
│   │   ├── CategoryPresenter.php
│   │   ├── ItemPresenter.php
│   │   ├── ProfilePresenter.php
│   │   ├── SecurityPresenter.php      # 2FA setup
│   │   └── templates/
│   │       ├── @layout.latte          # Hlavní layout
│   │       ├── Homepage/
│   │       ├── Sign/
│   │       ├── Project/
│   │       └── ...
│   ├── Model/
│   │   ├── Entity/                    # ORM entity
│   │   ├── Repository/                # DB přístup
│   │   ├── Service/                   # Business logika
│   │   └── Security/                  # Auth, voters
│   ├── Controls/                      # UI komponenty (Controls)
│   │   ├── CategoryTree/             # Strom kategorií
│   │   │   ├── CategoryTreeControl.php
│   │   │   └── categoryTree.latte
│   │   ├── ProjectCard/              # Karta projektu
│   │   ├── MemberList/               # Seznam členů
│   │   └── Navigation/               # Navigace
│   ├── Forms/                         # Form factories
│   └── Router/
│       └── RouterFactory.php
├── config/
│   ├── common.neon                    # Společná konfigurace
│   ├── services.neon                  # DI services
│   └── local.neon                     # Lokální overrides (ne v gitu)
├── www/                               # Document root
│   ├── index.php
│   ├── assets/                        # JS, CSS
│   │   ├── controllers/               # Stimulus (kopie ze Symfony)
│   │   └── lib/
│   └── uploads/                       # User uploads
├── translations/                      # Překlady
├── tests/                             # Nette Tester
├── docker/                            # Docker konfigurace
├── temp/                              # Cache, sessions
├── log/                               # Logy
└── vendor/
```

## Sdílené komponenty (ze Symfony verze)

Tyto soubory se **kopírují beze změny**:
- `assets/controllers/*.js` — Stimulus controllery
- `assets/lib/webauthn-utils.js` — WebAuthn utility
- `translations/messages.cs.yaml` → konverze do Nette translator formátu
- Tailwind CSS konfigurace (CDN)
- PhotoSwipe (CDN)
- **Ikony: Heroicons** (inline SVG, https://heroicons.com/) — NEPOUŽÍVAT Bootstrap Icons ani jiné font-based ikony!
