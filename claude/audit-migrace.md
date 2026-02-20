# Audit migračního zadání (2026-02-19)

Analýza migračních dokumentů z pohledu profesionálního Nette programátora. Identifikované nejasnosti, chybějící detaily a rozdíly Symfony vs Nette které je třeba řešit během implementace.

> **Zdroj:** Porovnání `migracni-plan.md`, `mapovani.md`, `db-schema.md` se skutečnou Symfony implementací v `/home/elvi/projects/stdozor/www/`

---

## KRITICKÉ (blokující — bez vyřešení nelze korektně implementovat)

### 1. Doctrine Entity Listeners — nikde nezmíněny

Symfony projekt používá **3 entity listenery** s klíčovou business logikou:

#### `ItemEntityListener`
- **Eventy:** `postPersist`, `preUpdate`, `postUpdate`, `postRemove`
- **Logika:** Po vytvoření/editaci/smazání položky automaticky přepočítá `actual_amount` kategorie a propaguje nahoru k projektu
- **Soubor:** `src/EventListener/ItemEntityListener.php`

#### `AttachmentEntityListener`
- **Event:** `preRemove`
- **Logika:** Před smazáním přílohy z DB fyzicky odstraní soubor z disku + prázdné adresáře (category_id, project_id)
- **Soubor:** `src/EventListener/AttachmentEntityListener.php`

#### `CategoryStatusChangeListener`
- **Eventy:** `prePersist`, `preUpdate`, `postUpdate`
- **Logika:** Při změně statusu root kategorie přeřadí `display_order` v rámci status skupiny
- **Soubor:** `src/EventListener/CategoryStatusChangeListener.php`

**Problém pro Nette:** Nette Database Explorer **nemá lifecycle events**. Žádný ekvivalent Doctrine entity listenerů.

**Řešení — rozhodnout se:**
1. **Ručně volat přepočty** v presenterech/services po každé DB operaci (jednoduché, ale křehké — lze zapomenout)
2. **Wrapper repository pattern** s hooky `afterInsert()`, `afterUpdate()`, `afterDelete()` (robustnější)
3. **Přejít na Nextras ORM** (má entity events, ale větší změna architektury)

**Doporučení:** Varianta 2 — vytvořit repository třídy které interně volají přepočty. Např.:
```php
class ItemRepository
{
    public function insert(array $data): ActiveRow
    {
        $row = $this->table()->insert($data);
        $this->amountCalculator->recalculate($row->category_id);
        return $row;
    }
}
```

---

### 2. Latte filtry — chybí MoneyExtension a TextExtension

Mapování zmiňuje `EnumTranslator`, ale Symfony má **3 Twig extensions** které je třeba přepsat do Latte:

| Twig Extension | Filtry/Funkce | V migračních docs |
|---|---|---|
| `EnumExtension` | `\|enum_trans`, `\|enum_color`, `\|enum_badge_class`, `enum_choices()` | Zmíněno obecně |
| **`MoneyExtension`** | **`\|money(cents, currency)`, `\|money_cents`** | **CHYBÍ!** |
| **`TextExtension`** | **`\|truncate(length, suffix)`** | **CHYBÍ!** |

#### MoneyExtension (KRITICKÝ)
Celý rozpočtový systém závisí na formátování částek:
- Vstup: centy/haléře (`int`, např. `600010200`)
- Výstup: `"6 000 102,00 CZK"` (česká lokalizace)
- Filtr `|money(cents, currency='CZK', decimals=2)`: dělí 100, `number_format()` s české lokalizací
- Filtr `|money_cents(cents, currency)`: bez desetinných míst

**Soubor:** `src/Twig/MoneyExtension.php`

#### TextExtension
- Filtr `|truncate(length=100, suffix='...', preserveWords=false)`: zkrácení textu
- Podpora multibyte (mb_* funkce)

**Soubor:** `src/Twig/TextExtension.php`

**Řešení:** Registrovat custom Latte filtry v `LatteExtension` nebo přes `TemplateFactory`:
```php
$latte->addFilter('money', function (int $cents, string $currency = 'CZK'): string {
    return number_format($cents / 100, 2, ',', ' ') . ' ' . $currency;
});
```

---

### 3. Form rendering s Tailwind — nejasný přístup

Symfony používá **custom form theme** (`form/tailwind_theme.html.twig`) který automaticky přidává Tailwind třídy na všechny form elementy.

**Problém:** Nette Forms **nemají koncept form themes**. Migrační plán říká `{form}...{/form}` ale nedefinuje strategii stylingem.

**Otázky k rozhodnutí:**
- Ruční renderování `{input}` + `{label}` v každé šabloně? (flexibilní, ale opakující se)
- Custom `DefaultFormRenderer` s Tailwind třídami? (automatické, ale méně flexibilní)
- Sdílená Latte šablona/component pro form field rendering?

**Doporučení:** Kombinace:
1. Custom `TailwindFormRenderer extends DefaultFormRenderer` pro automatické třídy
2. V šablonách kde potřebujeme speciální layout použít ruční `{input}` / `{label}`
3. Validační chyby: Nette renderuje `<ul class="error">` — přepsat na Tailwind (`text-red-600 text-sm`)

**Referenční soubor:** `src/templates/form/tailwind_theme.html.twig` (Symfony verze)

---

### 4. HaveIBeenPwned (NotCompromisedPassword) — chybí řešení

Symfony má vestavěný `NotCompromisedPassword` constraint. Nette **nic takového nemá**.

**Implementace pro Nette:**
1. K-anonymity hash prefix: `SHA1(heslo)` → prvních 5 znaků → `GET https://api.pwnedpasswords.com/range/{prefix}`
2. Zkontrolovat zbytek hashe v odpovědi
3. Implementovat jako custom Nette Forms validator nebo service

**Soubor pro referenci:** Symfony `NotCompromisedPasswordValidator` — volá HIBP API s k-anonymity

**Řešení:** Vytvořit `App\Validator\NotCompromisedPassword` service:
```php
class PwnedPasswordChecker
{
    public function isCompromised(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);
        // GET https://api.pwnedpasswords.com/range/$prefix
        // Parse response, check if $suffix exists
    }
}
```

---

## DŮLEŽITÉ (ovlivňující správnost implementace)

### 5. LoginSuccessHandler — invitation redirect flow

Migrační plán zmiňuje `onLoggedIn[]` event, ale nepopisuje konkrétní chování:

**Symfony flow:**
1. Neautentizovaný uživatel klikne na odkaz s pozvánkou
2. Session uloží `pending_invitation_token`
3. Redirect na login
4. Po úspěšném loginu → `LoginSuccessHandler` zkontroluje session
5. Pokud `pending_invitation_token` existuje → redirect na přijetí pozvánky
6. Jinak → redirect na seznam projektů

**Soubor:** `src/Security/LoginSuccessHandler.php`

**Řešení pro Nette:** V `SignPresenter::formSucceeded()` po úspěšném loginu:
```php
$token = $this->getSession('invitation')->token;
if ($token) {
    unset($this->getSession('invitation')->token);
    $this->redirect('Member:accept', $token);
} else {
    $this->redirect('Project:default');
}
```

---

### 6. Voter pravidla — detaily chybí

Mapování uvádí 4 Authorizátory, ale neobsahuje **konkrétní business pravidla**:

#### CategoryVoter (nejsložitější)
- Owner/supervisor → přístup ke **všem** kategoriím (zkratka, bez kontroly permission)
- Contractor/investor → potřebují `CategoryPermission` záznamy
- **Rekurzivní kontrola:** Oprávnění na rodičovskou kategorii = oprávnění na všechny potomky
- `CATEGORY_EDIT` / `CATEGORY_DELETE` / `CATEGORY_MANAGE_ACCESS` → **jen owner/supervisor**
- `has_global_category_access` v `project_member` je **jen pro auto-creating** permissions, NE pro access check

**Soubor:** `src/Security/Voter/CategoryVoter.php`

#### ItemVoter (8 různých oprávnění)
| Oprávnění | Kdo má přístup |
|---|---|
| `ITEM_VIEW` | Kaskáduje na CategoryVoter |
| `ITEM_CREATE` | V aktivní kategorii: kdo má CategoryVoter.ADD_ITEM. V completed kategorii: jen owner |
| `ITEM_EDIT` | Jen owner |
| `ITEM_DELETE` | Jen owner |
| `ITEM_EDIT_AMOUNT` | Jen owner |
| `ITEM_EDIT_CONTROL_DAY` | Owner NEBO supervisor |
| `ITEM_EDIT_CONSTRUCTION_LOG` | Owner NEBO supervisor |
| `ITEM_VIEW_ATTACHMENT` (non-image) | Owner NEBO investor |
| `ITEM_DELETE_ATTACHMENT` | Owner NEBO uploader přílohy |

**Soubor:** `src/Security/Voter/ItemVoter.php`

#### ProjectMemberVoter (prevence lock-out)
- Nelze odebrat posledního ownera (musí zůstat ≥1 owner)
- Nelze změnit vlastní roli
- Nelze odebrat permanentního ownera (project.owner_id)
- Role check přes `JSON_CONTAINS()` v DB dotazu

**Soubor:** `src/Security/Voter/ProjectMemberVoter.php`

---

### 7. Password Reset — nerozhodnutý pattern

DB schéma obsahuje `reset_password_request` se `selector` + `hashed_token` (selector/verifier pattern z SymfonyCasts/ResetPasswordBundle).

**Rozhodnutí potřeba:**
- **Varianta A:** Zachovat selector/verifier (bezpečnější — selector je v URL, verifier je hashovaný v DB)
- **Varianta B:** Zjednodušit na plain token jako email verification (jednodušší, ale méně bezpečné)

**Doporučení:** Varianta B — zjednodušit. Token pattern (64-char hex, hashovaný v DB) je dostatečně bezpečný a konzistentní s email verification. DB schéma by se upravilo:
- Sloučit `selector` + `hashed_token` → jeden `token` sloupec
- Nebo zachovat stávající schéma ale používat jen `hashed_token` jako lookup

**Soubor:** `src/Controller/ResetPasswordController.php`

---

### 8. File upload — chybí validační pravidla

Konfigurace z `AttachmentService` která není v migračních docs:

```php
const MAX_FILE_SIZE = 10 * 1024 * 1024;      // 10 MB
const MAX_ATTACHMENTS_PER_ITEM = 10;
const MAX_IMAGE_WIDTH = 1920;
const MAX_IMAGE_HEIGHT = 1080;

// Povolené MIME typy:
'image/jpeg', 'image/png', 'image/gif', 'image/webp',
'application/pdf',
'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
'application/vnd.oasis.opendocument.presentation',
'application/zip'
```

**Adresářová struktura uploadů:** `uploads/{projectId}/{categoryId}/{random_hex}.{ext}`
**Filename:** `bin2hex(random_bytes(16))` — bezpečný, bez kolizí
**Image resizing:** `intervention/image` s GD backendem

**Řešení pro Nette:** Použít `Nette\Utils\Image` (nativní, bez závislosti) místo `intervention/image`.

**Soubor:** `src/Service/Project/AttachmentService.php`

---

### 9. CSRF na delete/AJAX akce

Symfony CSRF konvence:
- Formulářové delete: `csrf_token('delete-category-{id}')`
- WebAuthn AJAX: header `X-CSRF-Token`

**Nette řešení:**
- Nette Forms mají **automatický CSRF** (`addProtection()`)
- Signály (`handleDelete()`) — přidat `$this->checkSignalPermission()` nebo token v URL
- AJAX JSON endpointy — buď Nette session token, nebo custom header validace

**POZOR:** Nette signály (`handleXxx`) jsou volány přes URL s parametrem `do=xxx`. Nette samo o sobě signály **nechrání CSRF tokenem** — je třeba přidat manuálně (např. přes parameter v URL nebo session check).

---

### 10. Email systém — nespecifikovaný

Symfony posílá minimálně **3 typy emailů:**

| Email | Kdy | Šablona (Twig) |
|---|---|---|
| Verifikace emailu | Po registraci | `registration/confirmation_email.html.twig` |
| Reset hesla | Po žádosti o reset | `reset_password/email.html.twig` |
| Pozvánka do projektu | Po odeslání pozvánky | `project_member/invitation_email.html.twig` |

**Řešení pro Nette:**
- `Nette\Mail\SmtpMailer` pro odesílání
- Latte šablony pro emaily (Latte umí renderovat do stringu)
- Dev: Mailpit (SMTP `localhost:1025`)
- Prod: reálný SMTP server

**Konfigurace v NEON:**
```neon
mail:
    smtp: true
    host: mailpit  # dev
    port: 1025
```

---

## DROBNÉ NEJASNOSTI

### 11. Nesoulad namespace

Mapování: `Model\UserRepository`
AGENTS.md struktura: `app/Model/Repository/UserRepository.php`

→ Plný namespace by byl `App\Model\Repository\UserRepository`, ne `App\Model\UserRepository`.

**Doporučení:** Sjednotit na:
```
App\Model\Entity\User          → app/Model/Entity/User.php
App\Model\Repository\UserRepository → app/Model/Repository/UserRepository.php
App\Model\Service\TotpSetupService  → app/Model/Service/TotpSetupService.php
App\Model\Security\ProjectAuthorizator → app/Model/Security/ProjectAuthorizator.php
```

---

### 12. Flash message typy

Symfony používá: `success`, `error`, `info`, `warning` + speciální `reset_password_error`.

Nette `flashMessage($message, $type)` podporuje libovolný typ. Layout (`@layout.latte`) musí umět zobrazit všechny:

```latte
{foreach $flashes as $flash}
    <div n:class="'alert',
        $flash->type === 'success' ? 'bg-green-50 text-green-800 border-green-200',
        $flash->type === 'error' ? 'bg-red-50 text-red-800 border-red-200',
        $flash->type === 'warning' ? 'bg-yellow-50 text-yellow-800 border-yellow-200',
        $flash->type === 'info' ? 'bg-blue-50 text-blue-800 border-blue-200'
    ">
        {$flash->message}
    </div>
{/foreach}
```

---

### 13. Nette Database — explicitní pravidla

Doplnit do AGENTS.md jako explicitní pravidla:

- **JSON sloupce** (`roles`, `backup_codes`, `transports`, `category_ids`): Vždy `json_decode($row->column, true)`
- **Boolean sloupce** (`is_verified`, `is_public`, `is_passkey`, `used`, `manual_amount_override`): Vždy `(bool) $row->column`
- **DateTime:** Nette vrací `Nette\Utils\DateTime` (extends `\DateTime`), pro immutabilitu: `\DateTimeImmutable::createFromMutable($row->created_at)`
- **Decimal:** Nette vrací `string` pro `decimal(10,2)` — přetypovat na `float` nebo ponechat string pro přesnost

---

### 14. CLI příkazy

Symfony console commands k portování:
- `app:cleanup:email-tokens` — mazání expired/used tokenů starších 30 dní
- `InvitationService::cleanupExpiredInvitations()` — mazání expired pozvánek

**Řešení:** `contributte/console` (Symfony Console pro Nette) nebo jednoduché PHP scripty volané z cronu:
```bash
# cron
0 3 * * * php /var/www/bin/cleanup-tokens.php
```

---

### 15. Asset management

Symfony používá AssetMapper (importmap pro JS). Pro Nette:

**Doporučení:** Vite s `nette-vite` pluginem:
- Hot module replacement pro dev
- Bundling pro prod
- Stimulus lazy loading

Alternativa: Přímé CDN/importmap odkazy v layoutu (jednodušší, ale bez bundlingu).

---

### 16. WebAuthn knihovna

Symfony verze používá `web-auth/webauthn-symfony-bundle` (Symfony-specifický wrapper).

**Pro Nette:** Použít pouze core knihovnu `web-auth/webauthn-lib` (framework-agnostická).
**NEINSTALOVAT** `web-auth/webauthn-symfony-bundle` — nebude fungovat bez Symfony DI.

Také `jbtronics/2fa-webauthn` je Symfony-specifický (bridge pro scheb/2fa) — **NEPOUŽÍVAT** v Nette.

---

### 17. contributte/translation — formát souborů

`contributte/translation` interně používá Symfony Translation komponentu a **umí číst YAML přímo**.

→ Soubor `translations/messages.cs.yaml` lze **zkopírovat beze změny**. Konverze do jiného formátu NENÍ nutná.

Konfigurace:
```neon
extensions:
    translation: Contributte\Translation\DI\TranslationExtension

translation:
    locales:
        default: cs
        fallback: [cs, en]
    dirs:
        - %appDir%/../translations
```

---

### 18. Router — chybí skeleton

Příklad `RouterFactory` pro tento projekt:

```php
class RouterFactory
{
    public static function createRouter(): RouteList
    {
        $router = new RouteList;

        // Auth
        $router->addRoute('prihlaseni', 'Sign:in');
        $router->addRoute('registrace', 'Sign:up');
        $router->addRoute('odhlaseni', 'Sign:out');
        $router->addRoute('zapomenute-heslo', 'Sign:forgotPassword');
        $router->addRoute('reset-hesla/<token>', 'Sign:resetPassword');
        $router->addRoute('overeni-emailu/<token>', 'Sign:verifyEmail');

        // 2FA
        $router->addRoute('2fa', 'Sign:twoFactor');

        // Profile
        $router->addRoute('profil', 'Profile:default');
        $router->addRoute('profil/upravit', 'Profile:edit');
        $router->addRoute('profil/zabezpeceni[/<action>]', 'Security:default');

        // Projects
        $router->addRoute('projekty', 'Project:default');
        $router->addRoute('projekt/novy', 'Project:create');
        $router->addRoute('projekt/<id \d+>[/<action>]', 'Project:show');

        // Categories
        $router->addRoute('kategorie/<id \d+>[/<action>]', 'Category:show');
        $router->addRoute('projekt/<projectId \d+>/kategorie/nova', 'Category:create');

        // Items
        $router->addRoute('polozka/<id \d+>[/<action>]', 'Item:show');

        // Members
        $router->addRoute('projekt/<projectId \d+>/clenove', 'Member:default');
        $router->addRoute('pozvanka/<token>', 'Member:accept');

        // Homepage fallback
        $router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');

        return $router;
    }
}
```

---

## Checklist — řešit během implementace

- [ ] **Rozhodnout:** Entity listeners → repository hooky vs manuální volání vs Nextras ORM
- [ ] **Implementovat:** MoneyExtension Latte filtr (`|money`, `|money_cents`)
- [ ] **Implementovat:** TextExtension Latte filtr (`|truncate`)
- [ ] **Rozhodnout:** Form rendering strategie (custom renderer vs ruční Latte)
- [ ] **Implementovat:** HaveIBeenPwned checker (HIBP API s k-anonymity)
- [ ] **Dokumentovat:** Voter pravidla (CategoryVoter rekurze, ItemVoter 8 oprávnění, lock-out prevence)
- [ ] **Rozhodnout:** Password reset — selector/verifier vs simple token
- [ ] **Dokumentovat:** Upload validační pravidla (MIME, size, limits)
- [ ] **Implementovat:** Email šablony v Latte (3 typy: verifikace, reset, pozvánka)
- [ ] **Ošetřit:** CSRF na signálech a AJAX endpointech
- [ ] **Sjednotit:** Namespace konvence (`App\Model\Repository\*` vs `App\Model\*`)
- [ ] **Implementovat:** Flash message rendering v layoutu (4 typy)
- [ ] **Pravidlo:** JSON/boolean/DateTime přetypování z Nette Database
- [ ] **Rozhodnout:** CLI příkazy — contributte/console vs PHP scripty
- [ ] **Rozhodnout:** Asset management — Vite vs CDN importmap
- [ ] **Poznámka:** WebAuthn → pouze `web-auth/webauthn-lib`, NE symfony-bundle
- [ ] **Poznámka:** contributte/translation čte YAML — konverze není nutná
