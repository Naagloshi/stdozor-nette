# Mapování Symfony → Nette

Kompletní mapování všech Symfony komponent na Nette ekvivalenty.

---

## 1. Entity → Modely/Repository

### User modul

| Symfony Entity | Nette Model | Tabulka | Poznámka |
|---------------|-------------|---------|----------|
| `Entity\User\User` | `Model\Entity\User` + `Model\UserRepository` | `user` | Implementuje `Nette\Security\IIdentity` |
| `Entity\User\Profile` | `Model\Entity\Profile` + `Model\ProfileRepository` | `profile` | OneToOne s User |
| `Entity\User\WebAuthnCredential` | `Model\Entity\WebAuthnCredential` + `Model\WebAuthnCredentialRepository` | `webauthn_credential` | ManyToOne s User |
| `Entity\User\EmailVerificationToken` | `Model\Entity\EmailVerificationToken` + `Model\EmailVerificationTokenRepository` | `email_verification_token` | Token-based verifikace |

### Project modul

| Symfony Entity | Nette Model | Tabulka | Poznámka |
|---------------|-------------|---------|----------|
| `Entity\Project\Project` | `Model\Entity\Project` + `Model\ProjectRepository` | `project` | ManyToOne owner → User |
| `Entity\Project\Category` | `Model\Entity\Category` + `Model\CategoryRepository` | `category` | Self-reference strom (parent_id) |
| `Entity\Project\Item` | `Model\Entity\Item` + `Model\ItemRepository` | `item` | ManyToOne → Category |
| `Entity\Project\Attachment` | `Model\Entity\Attachment` + `Model\AttachmentRepository` | `attachment` | ManyToOne → Item |
| `Entity\Project\ProjectMember` | `Model\Entity\ProjectMember` + `Model\ProjectMemberRepository` | `project_member` | User ↔ Project s rolemi (JSON) |
| `Entity\Project\ProjectInvitation` | `Model\Entity\ProjectInvitation` + `Model\ProjectInvitationRepository` | `project_invitation` | Token-based pozvánky |
| `Entity\Project\CategoryPermission` | `Model\Entity\CategoryPermission` + `Model\CategoryPermissionRepository` | `category_permission` | Granulární přístupy |
| `Entity\Project\Role` | `Model\Entity\Role` + `Model\RoleRepository` | `role` | Referenční tabulka |
| `Entity\Project\ProjectTemplate` | `Model\Entity\ProjectTemplate` | `project_template` | Šablony projektů |
| `Entity\Project\ProjectTemplateCategory` | `Model\Entity\ProjectTemplateCategory` | `project_template_category` | Šablony kategorií |

### Podpůrné entity

| Symfony Entity | Nette Model | Tabulka |
|---------------|-------------|---------|
| `ResetPasswordRequest` | `Model\Entity\ResetPasswordRequest` | `reset_password_request` |

---

## 2. Controllers → Presentery

| Symfony Controller | Nette Presenter | Akce |
|-------------------|-----------------|------|
| `HomepageController` | `HomepagePresenter` | `default` |
| `SecurityController` | `SignPresenter` | `in`, `out`, `passkeyOptions`, `passkeyLogin` |
| `RegistrationController` | `SignPresenter` | `up`, `verifyEmail`, `resendVerification` |
| `ResetPasswordController` | `SignPresenter` | `forgotPassword`, `checkEmail`, `resetPassword` |
| `ProjectController` | `ProjectPresenter` | `default`, `create`, `show`, `edit`, `exports` + `handleDelete` |
| `CategoryController` | `CategoryPresenter` | `create`, `edit` + signály: `handleDelete`, `handleReorder`, `handleChangeStatus`, `handleAddAccess`, `handleRemoveAccess` |
| `ItemController` | `ItemPresenter` | `create`, `edit` + `handleDelete` |
| `ProfileController` | `ProfilePresenter` | `default`, `edit` |
| `ProjectMemberController` | `MemberPresenter` | `default`, `invite`, `accept`, `changeRoles` + `handleRemove`, `handleCancelInvitation` |
| `AttachmentController` | (součást ItemPresenter) | `handleDeleteAttachment` |
| `Security\TwoFactorSetupController` | `SecurityPresenter` | `default`, `totpSetup`, `totpEnable`, `totpDisable`, `backupCodes`, `backupCodesGenerate`, `trustedRevoke` |
| `Security\WebAuthnSetupController` | `SecurityPresenter` | `webauthnRegister`, `webauthnOptions`, `webauthnComplete`, `webauthnDelete` |
| `Security\PasskeyLoginController` | `SignPresenter` | `passkeyOptions` (JSON) |

---

## 3. FormTypes → FormFactories

| Symfony FormType | Nette FormFactory | Pole |
|-----------------|-------------------|------|
| `LoginFormType` | `SignFormFactory::createLoginForm()` | email, password |
| `RegistrationFormType` | `SignFormFactory::createRegistrationForm()` | email, password (16+ znaků), agreeTerms |
| `ResetPasswordRequestFormType` | `SignFormFactory::createForgotPasswordForm()` | email |
| `ChangePasswordFormType` | `SignFormFactory::createResetPasswordForm()` | newPassword, confirmPassword |
| `ProjectType` | `ProjectFormFactory::create()` | name, description, address, dates, status, currency, isPublic, template |
| `CategoryType` | `CategoryFormFactory::create()` | name, description, status, estimatedAmount, manualAmountOverride, actualAmount |
| `ItemType` | `ItemFormFactory::create()` | description, itemDate, amount?, weather, isControlDay?, includeInConstructionLog?, attachmentFiles |
| `UserProfileFormType` | `ProfileFormFactory::create()` | email (disabled), firstName, lastName, phone, bio, currentPassword?, newPassword? |
| `InviteType` | `MemberFormFactory::createInviteForm()` | email, roles |
| `ChangeRolesType` | `MemberFormFactory::createChangeRolesForm()` | roles, hasGlobalCategoryAccess, categories |

---

## 4. Services → Services

| Symfony Service | Nette Service | Změny |
|----------------|---------------|-------|
| `EnumTranslator` | `Model\EnumTranslator` | Použije Nette Translator |
| `EnumPresenter` | `Model\EnumPresenter` | Beze změny (pure PHP) |
| `ProjectPermissionChecker` | `Model\Security\ProjectPermissionChecker` | Použije Nette Security |
| `CategoryAmountCalculator` | `Model\CategoryAmountCalculator` | Použije Nette Database |
| `CategoryDeleter` | `Model\CategoryDeleter` | Použije Nette Database |
| `AttachmentService` | `Model\AttachmentService` | Beze změny (filesystem) |
| `InvitationService` | `Model\InvitationService` | Použije Nette Mail |
| `EmailVerifier` | `Model\Security\EmailVerifier` | Použije Nette Mail |
| `TotpSetupService` | `Model\TwoFactor\TotpSetupService` | Beze změny (OTPHP) |
| `BackupCodeService` | `Model\TwoFactor\BackupCodeService` | Beze změny (pure PHP) |
| `WebAuthnService` | `Model\TwoFactor\WebAuthnService` | Přepsat session handling |

---

## 5. Security → Nette Security

### Authenticator
| Symfony | Nette |
|---------|-------|
| `form_login` authenticator | `Nette\Security\Authenticator` implementace |
| `PasskeyAuthenticator` | Custom authenticator pro WebAuthn |
| `UserChecker` | Kontrola v authenticatoru (isVerified) |
| `LoginSuccessHandler` | `onLoggedIn[]` event v authenticatoru |

### Authorization (Voters → Authorizator)
| Symfony Voter | Nette ekvivalent |
|--------------|------------------|
| `ProjectVoter` | `Model\Security\ProjectAuthorizator::isAllowed($user, $project, $privilege)` |
| `CategoryVoter` | `Model\Security\CategoryAuthorizator::isAllowed(...)` |
| `ItemVoter` | `Model\Security\ItemAuthorizator::isAllowed(...)` |
| `ProjectMemberVoter` | `Model\Security\MemberAuthorizator::isAllowed(...)` |

**Implementace:** Custom `Nette\Security\Authorizator` nebo service-based kontroly v presenterech.

---

## 6. Šablony (Twig → Latte)

### Syntaxe převod

| Twig | Latte |
|------|-------|
| `{% extends 'base.html.twig' %}` | `{layout '@layout.latte'}` |
| `{% block body %}...{% endblock %}` | `{block content}...{/block}` |
| `{% include '_partial.html.twig' %}` | `{include '_partial.latte'}` |
| `{{ variable }}` | `{$variable}` |
| `{{ variable\|trans }}` | `{_$variable}` nebo `{_'key'}` |
| `{{ 'key'\|trans }}` | `{_'key'}` |
| `{{ 'key'\|trans({'%name%': name}) }}` | `{_'key', name: $name}` |
| `{% if condition %}` | `{if $condition}` |
| `{% for item in items %}` | `{foreach $items as $item}` |
| `{{ form_start(form) }}` | `{form $form}` |
| `{{ form_row(form.field) }}` | `{input field}` + `{label field}` |
| `{{ form_end(form) }}` | `{/form}` |
| `{{ path('route_name') }}` | `{link Presenter:action}` nebo `{plink ...}` |
| `{{ path('route', {id: 1}) }}` | `{link Presenter:action, id: 1}` |
| `{{ csrf_token('id') }}` | Nette Forms mají CSRF automaticky |
| `{{ app.user }}` | `{$user}` (předáno z presenteru) |
| `{% set var = value %}` | `{var $var = $value}` |
| `{{ variable\|date('d.m.Y') }}` | `{$variable\|date:'d.m.Y'}` |
| `{{ variable\|number_format(2, ',', ' ') }}` | `{$variable\|number:2, ',', ' '}` |

### Mapování šablon

| Twig šablona | Latte šablona |
|-------------|---------------|
| `base.html.twig` | `@layout.latte` |
| `base_security.html.twig` | `@layout-security.latte` |
| `security/login.html.twig` | `Sign/in.latte` |
| `registration/register.html.twig` | `Sign/up.latte` |
| `reset_password/request.html.twig` | `Sign/forgotPassword.latte` |
| `reset_password/reset.html.twig` | `Sign/resetPassword.latte` |
| `reset_password/check_email.html.twig` | `Sign/checkEmail.latte` |
| `registration/resend_verification.html.twig` | `Sign/resendVerification.latte` |
| `homepage/index.html.twig` | `Homepage/default.latte` |
| `profile/index.html.twig` | `Profile/default.latte` |
| `profile/edit.html.twig` | `Profile/edit.latte` |
| `profile/security/index.html.twig` | `Security/default.latte` |
| `profile/security/totp_setup.html.twig` | `Security/totpSetup.latte` |
| `profile/security/backup_codes.html.twig` | `Security/backupCodes.latte` |
| `profile/security/webauthn_register.html.twig` | `Security/webauthnRegister.latte` |
| `security/2fa_form.html.twig` | `Sign/twoFactor.latte` |
| `security/2fa_webauthn.html.twig` | `Sign/twoFactorWebauthn.latte` |
| `project/index.html.twig` | `Project/default.latte` |
| `project/new.html.twig` | `Project/create.latte` |
| `project/show_new.html.twig` | `Project/show.latte` |
| `project/edit.html.twig` | `Project/edit.latte` |
| `project/_category_tree.html.twig` | `Project/_categoryTree.latte` |
| `category/new.html.twig` | `Category/create.latte` |
| `category/edit.html.twig` | `Category/edit.latte` |
| `item/_form.html.twig` | `Item/_form.latte` |
| `project_member/index.html.twig` | `Member/default.latte` |
| `project_member/accept_invitation.html.twig` | `Member/accept.latte` |

---

## 7. Konfigurace (YAML → NEON)

### security.yaml → common.neon
```neon
security:
    users:
        - App\Model\Security\UserAuthenticator
    authorizator: App\Model\Security\Authorizator
```

### doctrine.yaml → common.neon
```neon
database:
    dsn: 'mysql:host=mariadb;dbname=stdozor'
    user: stdozor
    password: '...'
```

### services.yaml → services.neon
```neon
services:
    - App\Model\EnumTranslator
    - App\Model\EnumPresenter
    - App\Model\CategoryAmountCalculator
    - App\Model\CategoryDeleter
    - App\Model\AttachmentService
    - App\Model\InvitationService
    # ... autowiring
```

---

## 8. Stimulus controllery (beze změny)

Tyto soubory se **kopírují 1:1** ze Symfony verze:

| Controller | Účel |
|-----------|------|
| `gallery_controller.js` | PhotoSwipe galerie |
| `toast_controller.js` | Toast notifikace |
| `modal_controller.js` | Generic modal |
| `disclosure_controller.js` | Expand/collapse sekce |
| `confirm_controller.js` | Potvrzovací dialog |
| `toggle_controller.js` | Show/hide toggle |
| `collapse_controller.js` | Collapse s ikonou |
| `tabs_controller.js` | Tab navigace |
| `upload_controller.js` | Drag & drop upload |
| `clipboard_controller.js` | Copy to clipboard |
| `category_form_controller.js` | Manual amount toggle |
| `category_access_controller.js` | Category access toggle |
| `category_member_access_controller.js` | Member access management |
| `ajax_modal_controller.js` | AJAX forms v modalu |
| `user_menu_controller.js` | User dropdown |
| `mobile_menu_controller.js` | Mobile hamburger |
| `tooltip_controller.js` | Tooltips |
| `webauthn_login_controller.js` | Passkey login |
| `webauthn_2fa_controller.js` | WebAuthn 2FA |
| `webauthn_register_controller.js` | WebAuthn registrace |

**+ Shared utility:**
| `lib/webauthn-utils.js` | Base64url encode/decode |

---

## 9. Enums (beze změny)

PHP 8.1+ enums se přenesou **identicky**:

| Enum | Hodnoty |
|------|---------|
| `ProjectStatus` | PLANNING, ACTIVE, PAUSED, COMPLETED, CANCELLED |
| `CategoryStatus` | PLANNED, IN_PROGRESS, COMPLETED |
| `ProjectRole` | OWNER, SUPERVISOR, CONTRACTOR, INVESTOR |
