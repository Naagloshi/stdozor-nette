# CLAUDE.md - STDozor Nette

## Read First

1. **`/AGENTS.md`** - Obecné instrukce pro AI (setup, konvence, testing)
2. **`claude/migracni-plan.md`** - Krok po kroku plán migrace ze Symfony
3. **`claude/mapovani.md`** - Mapování Symfony komponent na Nette ekvivalenty
4. **`claude/db-schema.md`** - Databázové schéma (sdílené se Symfony verzí)
5. **`claude/audit-migrace.md`** - **Audit migrace** — známé problémy, chybějící detaily, rozhodnutí k řešení během implementace

## Projekt

**STDozor Nette** je port aplikace Stavební dozor ze Symfony do Nette frameworku.

- **Zdrojový Symfony projekt:** `/home/elvi/projects/stdozor/www/`
- **Tento Nette projekt:** `/home/elvi/projects/stdozor-nette/`
- **Jazyk komunikace:** Česky
- **Cíl:** Funkčně a vizuálně identická aplikace v Nette

## Framework: Nette

**DŮLEŽITÉ:** Tento projekt používá **Nette Framework**, NE Symfony!

- **Presentery** (ne controllery)
- **Latte šablony** (ne Twig)
- **Nette Forms** (ne Symfony Forms)
- **Nette Database Explorer** nebo **Nextras ORM** (ne Doctrine)
- **NEON konfigurace** (ne YAML)
- **Nette DI Container** (ne Symfony DI)
- **Nette Security** (ne Symfony Security)

## Skills

Pro práci s Nette používej nainstalované skills:
- `nette-architecture` — presentery, moduly, struktura aplikace
- `nette-configuration` — DI, NEON, autowiring
- `nette-database` — Nette Database Explorer, Selection API
- `nette-forms` — formuláře, kontroly, validace, rendering
- `nette-testing` — Nette Tester, .phpt soubory
- `latte-templates` — syntaxe, tagy, filtry, layouty
- `neon-format` — syntaxe NEON konfigurace
- `nette-utils` — utility třídy (Strings, Arrays, Finder...)
- `nette-schema` — validace dat
- `frontend-development` — Vite, assets, SCSS

**Vždy invokuj příslušný skill PŘED psaním kódu v dané oblasti!**

## Referenční Symfony projekt

Symfony verze je v `/home/elvi/projects/stdozor/www/`. Můžeš se do ní podívat pro:
- Logiku business pravidel (jak fungují oprávnění, výpočty, flow)
- Databázové schéma (entity = definice tabulek)
- Překlady (`translations/messages.cs.yaml`)
- JS controllery (Stimulus — stejné pro obě verze)
- CSS (Tailwind — stejné pro obě verze)

**NEKOPÍRUJ Symfony kód přímo!** Vždy přepiš do Nette idiomů.

## Workflow

1. Před implementací modulu si načti příslušné soubory z `claude/`
2. **Zkontroluj `claude/audit-migrace.md`** — jsou tam známé problémy a rozhodnutí pro daný modul?
3. Invokuj relevantní Nette skill
4. Podívej se na Symfony implementaci pro business logiku
5. Implementuj v Nette idiomech
6. Spusť testy
7. Aktualizuj `claude/stav-migrace.md`
8. **Odškrtni vyřešené položky** v `claude/audit-migrace.md` checklist

## Pravidla

- **NIKDY nepsat hardcoded texty** — vždy překlady
- **NIKDY nepoužívat Symfony komponenty** — vždy Nette ekvivalenty
- **Latte šablony** místo Twig
- **NEON konfigurace** místo YAML
- **Nette Forms** místo Symfony Forms
- **NEPORTOVAT 1:1** — Symfony controller ≠ Nette presenter. Použít Nette Controls pro komplexní UI!
- **Controls (`{control}`)** — pro znovupoužitelné/komplexní části (strom kategorií, seznam členů, formuláře v modalu)
- **Snippety (`{snippet}`)** — pro AJAX překreslení (ne vlastní JS fetch). Použít s Naja.js
- **Stimulus** — ponechat POUZE pro čistě klientské interakce (tooltip, toggle, galerie, clipboard)
- **Ikony: Heroicons inline SVG** — NEPOUŽÍVAT Bootstrap Icons ani jiné font-based ikony
- Tailwind CSS zůstává **beze změny**

## MCP Server: Context7

Pro aktuální dokumentaci Nette:
```
mcp__context7__resolve-library-id(query="...", libraryName="nette")
mcp__context7__query-docs(libraryId="/nette/...", query="...")
```
