# Migrační plán: Symfony → Nette

**Cíl:** Funkčně a vizuálně identická aplikace v Nette frameworku.
**Referenční projekt:** `/home/elvi/projects/stdozor/www/`

---

## Fáze 0: Základ projektu

### 0.1 Nette Web Project skeleton
- [ ] `composer create-project nette/web-project stdozor-nette`
- [ ] Ověřit strukturu: `app/`, `config/`, `www/`, `temp/`, `log/`
- [ ] Nastavit Docker (PHP-FPM + Nginx + MariaDB) — lze sdílet MariaDB kontejner se Symfony verzí
- [ ] Nastavit `.env` / `config/local.neon` s DB připojením

### 0.2 Základní balíčky
```bash
composer require nette/application nette/database nette/security nette/forms
composer require latte/latte nette/utils nette/di nette/http
composer require contributte/translation    # Symfony Translation pro Nette
composer require nette/tester --dev         # Testování
composer require nette/coding-standard --dev
composer require phpstan/phpstan --dev
```

### 0.3 Sdílené assety (kopie ze Symfony)
- [ ] Zkopírovat `assets/controllers/*.js` (Stimulus controllery)
- [ ] Zkopírovat `assets/lib/webauthn-utils.js`
- [ ] Nastavit Tailwind CDN v layoutu
- [ ] Nastavit PhotoSwipe CDN
- [ ] **Ikony: Heroicons inline SVG** (NEPOUŽÍVAT Bootstrap Icons ani jiné CDN font ikony!)
- [ ] Nastavit Stimulus (importmap nebo Vite)

### 0.4 Databáze
- [ ] Použít **stejnou DB** jako Symfony verze (nebo kopii)
- [ ] Nakonfigurovat Nette Database Explorer v `common.neon`
- [ ] Ověřit připojení

---

## Fáze 1: Layout a navigace

### 1.1 Hlavní layout (`@layout.latte`)
- [ ] Přepsat `base.html.twig` → `@layout.latte`
- [ ] Bloky: `{block title}`, `{block content}`, `{block scripts}`
- [ ] Meta tagy, CDN odkazy (Tailwind, PhotoSwipe, Stimulus) — **BEZ** Bootstrap Icons CDN!
- [ ] Toast notifikace (flash messages)

### 1.2 Navigace
- [ ] `_navigation_tailwind.html.twig` → komponenta nebo include v Latte
- [ ] User menu (přihlášený/nepřihlášený stav)
- [ ] Mobile menu
- [ ] Footer

### 1.3 Error stránky
- [ ] 403, 404, 500 error šablony
- [ ] `ErrorPresenter` s renderováním chybových stránek

### 1.4 Stimulus integrace
- [ ] Nastavit Stimulus v Nette (Vite plugin nebo importmap)
- [ ] Ověřit že controllery se načítají (`toast_controller`, `user_menu_controller`, `mobile_menu_controller`)

---

## Fáze 2: Autentizace

### 2.1 Registrace
- [ ] `SignPresenter::actionUp()` — registrační formulář
- [ ] `RegistrationFormFactory` — email, password (passphrase 16+ znaků), agree terms
- [ ] Hashování hesla (bcrypt)
- [ ] Email verifikace (DB-backed tokeny — stejný princip jako Symfony verze)
- [ ] `EmailVerificationToken` tabulka/model
- [ ] Odeslání verifikačního emailu (Nette Mail nebo contributte/mail)

### 2.2 Přihlášení
- [ ] `SignPresenter::actionIn()` — přihlašovací formulář
- [ ] `LoginFormFactory` — email, password
- [ ] Nette Authenticator (implementace `Nette\Security\Authenticator`)
- [ ] `UserChecker` — kontrola verified statusu
- [ ] Redirect po přihlášení

### 2.3 Odhlášení
- [ ] `SignPresenter::actionOut()`

### 2.4 Reset hesla
- [ ] `SignPresenter::actionForgotPassword()` — žádost o reset
- [ ] `SignPresenter::actionResetPassword($token)` — nové heslo
- [ ] Reset token v DB (custom implementace — Symfony ResetPasswordBundle nemá Nette ekvivalent)
- [ ] Email s reset odkazem

### 2.5 Passkey login
- [ ] `SignPresenter::actionPasskeyOptions()` — WebAuthn assertion options (JSON)
- [ ] `SignPresenter::actionPasskeyLogin()` — ověření passkey (JSON)
- [ ] `PasskeyAuthenticator` — custom authenticator pro Nette Security
- [ ] Stimulus controller `webauthn_login_controller.js` (beze změny)

---

## Fáze 3: Profil uživatele

### 3.1 Zobrazení profilu
- [ ] `ProfilePresenter::actionDefault()` — přehled profilu
- [ ] Zobrazení: email, jméno, příjmení, telefon, bio
- [ ] Stav ověření emailu

### 3.2 Editace profilu
- [ ] `ProfilePresenter::actionEdit()` — editační formulář
- [ ] `ProfileFormFactory` — User + Profile data
- [ ] Změna hesla (volitelná, vyžaduje aktuální heslo)

---

## Fáze 4: 2FA (dvoufaktorové ověření)

### 4.1 Bezpečnostní přehled
- [ ] `SecurityPresenter::actionDefault()` — přehled 2FA metod
- [ ] Stav: TOTP, WebAuthn klíče, passkeys, záložní kódy, důvěryhodná zařízení

### 4.2 TOTP (Google Authenticator)
- [ ] `SecurityPresenter::actionTotpSetup()` — QR kód + manual entry
- [ ] `SecurityPresenter::actionTotpEnable()` — POST, verifikace kódu
- [ ] `SecurityPresenter::actionTotpDisable()` — POST, vyžaduje heslo
- [ ] `TotpSetupService` — port ze Symfony (OTPHP knihovna, endroid/qr-code)
- [ ] 2FA interceptor po přihlášení (Nette nemá scheb/2fa-bundle!)
  - **Řešení:** Custom middleware/event handler v `onLoggedIn` eventu
  - Redirect na 2FA formulář pokud má uživatel aktivní 2FA
  - Session flag `2fa_verified`

### 4.3 WebAuthn bezpečnostní klíče (2FA mód)
- [ ] `SecurityPresenter::actionWebauthnRegister($type)` — registrace klíče
- [ ] `SecurityPresenter::actionWebauthnOptions()` — JSON registration options
- [ ] `SecurityPresenter::actionWebauthnComplete()` — JSON zpracování registrace
- [ ] `SecurityPresenter::actionWebauthnDelete($id)` — smazání klíče
- [ ] `WebAuthnService` — port ze Symfony (web-auth/webauthn-lib)
- [ ] WebAuthn 2FA challenge v 2FA formuláři

### 4.4 Záložní kódy
- [ ] `SecurityPresenter::actionBackupCodes()` — zobrazení kódů
- [ ] `SecurityPresenter::actionBackupCodesGenerate()` — generování (vyžaduje heslo)
- [ ] `BackupCodeService` — port ze Symfony (bcrypt hashování)

### 4.5 Důvěryhodná zařízení
- [ ] `SecurityPresenter::actionTrustedRevoke()` — revokace všech
- [ ] Cookie-based trusted device (30 dní)

### 4.6 2FA interceptor (KRITICKÉ — vlastní implementace!)

Symfony má `scheb/2fa-bundle` který automaticky přesměrovává na `/2fa` po form_login.
Nette toto NEMÁ. Je třeba implementovat:

```
Přihlášení → onLoggedIn event → kontrola has2FAEnabled()
  → ANO: redirect na 2FA formulář, session['2fa_pending'] = true
  → NE: pokračovat normálně

Každý presenter s @Secured:
  → Kontrola: pokud session['2fa_pending'] === true → redirect na 2FA
  → Po úspěšném 2FA: session['2fa_verified'] = true, remove 2fa_pending

2FA formulář:
  → TOTP kód / WebAuthn / Backup kód
  → Trusted device checkbox
  → Provider switching (TOTP ↔ WebAuthn)
```

---

## Fáze 5: Projekty

### 5.1 Seznam projektů
- [ ] `ProjectPresenter::actionDefault()` — grid s kartami
- [ ] Filtrování podle role uživatele (owner, member)

### 5.2 Vytvoření projektu
- [ ] `ProjectPresenter::actionCreate()` — formulář
- [ ] `ProjectFormFactory` — name, description, address, dates, status, currency, template
- [ ] Vytvoření ProjectMember (owner) + kategorií ze šablony

### 5.3 Detail projektu
- [ ] `ProjectPresenter::actionShow($id)` — detail s taby
- [ ] Taby: detail, lidé, exporty
- [ ] Kategorie strom
- [ ] Permission checking (ProjectVoter ekvivalent)

### 5.4 Editace projektu
- [ ] `ProjectPresenter::actionEdit($id)` — editační formulář

### 5.5 Smazání projektu
- [ ] `ProjectPresenter::handleDelete($id)` — signal handler s CSRF

---

## Fáze 6: Kategorie

### 6.1 CRUD kategorií
- [ ] `CategoryPresenter::actionCreate($projectId)` — nová kategorie
- [ ] `CategoryPresenter::actionEdit($id)` — editace
- [ ] `CategoryPresenter::handleDelete($id)` — smazání (recursive)
- [ ] `CategoryFormFactory` — name, description, status, amounts
- [ ] `CategoryAmountCalculator` — port ze Symfony
- [ ] `CategoryDeleter` — port ze Symfony (recursive delete)

### 6.2 Řazení a stav
- [ ] `CategoryPresenter::handleReorder($id, $direction)` — řazení
- [ ] `CategoryPresenter::handleChangeStatus($id, $status)` — quick status change

### 6.3 Přístupová práva
- [ ] `CategoryPresenter::actionAddAccess($id)` — AJAX modal
- [ ] `CategoryPresenter::actionRemoveAccess($id)` — AJAX modal
- [ ] `CategoryPermission` model/tabulka

---

## Fáze 7: Položky (Items)

### 7.1 CRUD položek
- [ ] `ItemPresenter::actionCreate($categoryId)` — nová položka (AJAX)
- [ ] `ItemPresenter::actionEdit($id)` — editace (AJAX)
- [ ] `ItemPresenter::handleDelete($id)` — smazání
- [ ] `ItemFormFactory` — description, date, amount, weather, flags, attachments
- [ ] Permission-based field visibility (amount jen pro owner)

### 7.2 Přílohy
- [ ] Upload souborů (max 10, max 10MB)
- [ ] `AttachmentService` — port ze Symfony
- [ ] Galerie obrázků (PhotoSwipe + `gallery_controller.js`)

---

## Fáze 8: Členové projektu

### 8.1 Seznam členů
- [ ] `MemberPresenter::actionDefault($projectId)` — členové + pozvánky

### 8.2 Pozvánky
- [ ] `MemberPresenter::actionInvite($projectId)` — formulář pozvánky
- [ ] `InvitationService` — port ze Symfony
- [ ] Email s odkazem na přijetí
- [ ] `MemberPresenter::actionAccept($token)` — přijetí pozvánky

### 8.3 Správa rolí
- [ ] `MemberPresenter::actionChangeRoles($memberId)` — změna rolí
- [ ] `MemberPresenter::handleRemove($memberId)` — odebrání člena

---

## Fáze 9: Překlady

### 9.1 Nastavení překladů
- [ ] `contributte/translation` nebo vlastní translator
- [ ] Konverze `messages.cs.yaml` do Nette formátu
- [ ] Pluralizace (ICU format)

### 9.2 Enum překlady
- [ ] `EnumTranslator` service — port ze Symfony
- [ ] Latte filtry pro enum překlady a badge classes

---

## Fáze 10: Autorizace (Voters → Nette ACL)

### 10.1 Permission systém
- [ ] Custom `Authorizator` implementace
- [ ] Ekvivalent Symfony Voters:
  - `ProjectVoter` → kontrola owner/member
  - `CategoryVoter` → kontrola CategoryPermission
  - `ItemVoter` → kontrola práv na položky
  - `ProjectMemberVoter` → kontrola práv na členy
- [ ] Helper: `ProjectPermissionChecker` — port ze Symfony

---

## Fáze 11: Testy

### 11.1 Unit testy
- [ ] `TotpSetupServiceTest` — port ze Symfony (9 testů)
- [ ] `BackupCodeServiceTest` — port ze Symfony (10 testů)
- [ ] `ProjectPermissionCheckerTest` — port
- [ ] `CategoryAmountCalculatorTest` — port
- [ ] `InvitationServiceTest` — port

### 11.2 Funkční testy
- [ ] Presenter testy (ekvivalent WebTestCase)
- [ ] Auth flow testy
- [ ] CRUD testy pro projekty, kategorie, položky

---

## Fáze 12: Docker a deployment

### 12.1 Docker setup
- [ ] PHP-FPM kontejner (PHP 8.3 + Nette extensions)
- [ ] Nginx s Nette-specific rewrite rules
- [ ] MariaDB (sdílený nebo vlastní)
- [ ] Mailpit (development)

### 12.2 Finální kontrola
- [ ] Všechny stránky vizuálně shodné se Symfony verzí
- [ ] Všechny funkce fungují identicky
- [ ] PHPStan level 6 — 0 chyb
- [ ] Coding standard — 0 chyb
- [ ] Testy prochází

---

## Poznámky k rozdílům

### Klíčové rozdíly Symfony vs Nette

| Symfony | Nette | Poznámka |
|---------|-------|----------|
| Controller + Route annotation | Presenter + RouterFactory | Jiný routing model |
| Twig `{{ }}` | Latte `{...}` | Jiná syntax šablon |
| Symfony Forms (FormType) | Nette Forms (FormFactory) | Jiná filozofie (callback vs controller) |
| Doctrine ORM | Nette Database / Nextras ORM | Jiný přístup k DB |
| Symfony Security (Voters) | Nette Security (Authorizator) | Jiný auth model |
| YAML config | NEON config | Podobné, jiná syntax |
| scheb/2fa-bundle | Vlastní implementace | Nette nemá 2FA bundle! |
| Symfony Messenger | Vlastní async (nebo contributte) | Jiný messaging |
| `bin/console` | Custom CLI nebo contributte/console | Jiný CLI |
| Monolitický controller | Presenter + Controls (komponenty) | Nette je komponentové! |
| AJAX: JS fetch + full reload | AJAX snippety + Controls | Nette má snippet invalidaci |
| Twig include/partial | Latte `{control}` + `{snippet}` | Znovupoužitelné komponenty |

### Co zůstává stejné
- **Stimulus JS controllery** — framework-agnostické
- **Tailwind CSS** — framework-agnostický
- **Databázové schéma** — stejné tabulky
- **Business logika** — stejná pravidla (portuji services)
- **Překlady** — stejné klíče, jiný formát souboru
- **CDN knihovny** — PhotoSwipe (ikony jsou Heroicons inline SVG, ne CDN)

---

## DŮLEŽITÉ: Nette architektonické vzory

### Controls (komponenty) — NEŘEŠIT vše v presenterech!

V Symfony se vše řeší v controlleru. **V Nette je správný přístup použít Controls (komponenty)** pro znovupoužitelné a komplexní části UI.

#### Co je Nette Control?
- Samostatná třída (`extends Nette\Application\UI\Control`) s vlastní šablonou
- Má vlastní logiku, formuláře, signály (handleXxx)
- Připojuje se k presenteru přes `createComponentXxx()`
- V šabloně se vykresluje přes `{control componentName}`

#### Kdy použít Control místo kódu v presenteru:
- **Formuláře** — FormFactory vrací formulář, ale samotná logika zpracování může být v Controlu
- **Seznamy s AJAXem** — strom kategorií, seznam členů, ...
- **Opakující se UI bloky** — navigace, sidebar, karta projektu, ...
- **Komplexní interaktivní prvky** — galerie, upload, ...

#### Konkrétní kandidáti na Controls v tomto projektu:

| Symfony přístup | Nette Control | Důvod |
|----------------|---------------|-------|
| `_category_tree.html.twig` + AJAX v CategoryController | `CategoryTreeControl` | Stromová struktura, drag&drop řazení, inline status change, přidání/odebrání přístupu — příliš komplexní pro presenter |
| `_project_card.html.twig` + partial render | `ProjectCardControl` | Karta projektu (opakuje se v seznamu, AJAX editace) |
| Navigace (`_navigation.html.twig`) | `NavigationControl` | Stav přihlášení, user menu, breadcrumbs |
| Seznam členů + pozvánky | `MemberListControl` | CRUD členů, pozvánky, role changes — komplexní interakce |
| Item formulář (AJAX modal) | `ItemFormControl` | AJAX create/edit v modalu, upload příloh |
| Security overview | `SecurityOverviewControl` | Přehled 2FA metod, víc interaktivních sekcí |

#### Příklad: CategoryTreeControl

```php
// app/Controls/CategoryTree/CategoryTreeControl.php
class CategoryTreeControl extends Nette\Application\UI\Control
{
    public function __construct(
        private CategoryRepository $categories,
        private int $projectId,
    ) {}

    public function render(): void
    {
        $this->template->categories = $this->categories->findByProject($this->projectId);
        $this->template->setFile(__DIR__ . '/categoryTree.latte');
        $this->template->render();
    }

    // Signály pro AJAX akce
    public function handleReorder(int $id, string $direction): void { /* ... */ }
    public function handleChangeStatus(int $id, string $status): void { /* ... */ }
    public function handleDelete(int $id): void { /* ... */ }

    // Formulář pro přidání kategorie (sub-control)
    protected function createComponentAddForm(): Form { /* ... */ }
}
```

```latte
{* V presenteru: *}
{control categoryTree}

{* S AJAX snippetem: *}
{snippet categoryTree}
    {control categoryTree}
{/snippet}
```

### AJAX Snippety — alternativa k Stimulus JS

Nette má **vestavěný mechanismus pro AJAX** přes snippety. Na rozdíl od Symfony, kde se AJAX řeší přes JS (fetch API + Stimulus), Nette umí:

1. **Invalidovat snippet** — presenter/control oznámí, že se snippet změnil
2. **Nette AJAX** automaticky překreslí jen změněné části stránky
3. **Naja.js** — malá knihovna pro AJAX (Nette ekvivalent Turbo)

```php
// V presenteru/controlu:
public function handleChangeStatus(int $id, string $status): void
{
    // ... business logika ...

    if ($this->isAjax()) {
        $this->redrawControl('categoryTree');  // Překreslí jen snippet
    } else {
        $this->redirect('this');  // Fallback bez JS
    }
}
```

```latte
{snippet categoryTree}
    {* Tento blok se překreslí AJAXem *}
    {foreach $categories as $category}
        ...
    {/foreach}
{/snippet}
```

#### Kdy použít Nette snippety vs Stimulus:
| Situace | Řešení |
|---------|--------|
| Překreslení části stránky po akci (smazání, změna stavu) | **Nette snippet** + `$this->redrawControl()` |
| Klientská interaktivita (toggle, collapse, tooltip) | **Stimulus controller** (zůstává) |
| Formulář v modalu | **Nette snippet** v modalu NEBO Stimulus (podle složitosti) |
| Real-time validace formuláře | **Stimulus** (čistě klientská) |
| Galerie obrázků, clipboard, drag&drop | **Stimulus** (čistě klientská) |
| Toast notifikace z flash messages | **Stimulus** (čistě klientská) |

**Pravidlo:** Pokud akce vyžaduje serverový round-trip → Nette snippety. Pokud je to čistě klientské → Stimulus.

### Doporučený postup při implementaci

1. **NEPORTOVAT 1:1 ze Symfony** — Symfony controller → Nette presenter není vždy správný překlad
2. **Identifikovat komponenty** — co se opakuje? co je komplexní? co má vlastní stav?
3. **Vytvořit Controls** pro komplexní/opakující se části
4. **Použít snippety** pro serverový AJAX místo vlastního JS fetch
5. **Ponechat Stimulus** pro čistě klientské interakce (toggle, tooltip, galerie)
6. **Zvážit Naja.js** jako AJAX driver (automatické odesílání AJAX requestů pro odkazy/formuláře s třídou `ajax`)
