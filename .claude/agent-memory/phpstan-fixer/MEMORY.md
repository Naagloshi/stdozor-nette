# PHPStan Fixer - Project Memory

## Configuration
- **Config file:** `phpstan.neon` in project root
- **Level:** 6
- **Paths:** `app/`
- **Excluded:** `app/Bootstrap.php`
- **Tmp dir:** `temp/phpstan`
- **Run command:** `bin/php vendor/bin/phpstan analyse --no-progress` (runs inside Docker)
- **Extension:** `phpstan/phpstan-nette` (v2.0) — included via `extension.neon` + `rules.neon`

## Ignored errors (in phpstan.neon)
- `property.notFound` in `app/*` — Nette Database Explorer uses `__get()` magic for column access
- `missingType.generics` in `app/Model/Repository/*` — Selection generic type T not specified
- `arguments.count` in `app/Controls/*` — Nette template render() accepts parameters

## phpstan-nette extension benefits
- `getComponent()` knows return type from `createComponent*` methods
- Presenter early terminating methods (`error()`, `redirect()`) recognized
- Forms fluent methods return `static`
- `@inject` properties treated as initialized
- NOTE: `$form['fieldName']` ArrayAccess still returns `IComponent` — use `/** @var BaseControl */` inline

## Common patterns & fixes

### missingType.iterableValue on `array $data` parameters
- Repository `insert()`, `update()` methods: Add `@param array<string, mixed> $data`
- Role arrays (`$memberRoles`, `$newRoles`, `$roles`): Add `@param string[] $roles`
- Return types like `findByUser()` with raw query: Add `@return array<\Nette\Database\Row>`
- `buildTree()` return: Use `@return array<int, array{category: ActiveRow, children: array<mixed>}>`

### return.type — fetchAll() returns Row[] not ActiveRow[]
- Methods using `$this->database->query(...)->fetchAll()` return `Nette\Database\Row[]`
- Fix: Add `/** @var ActiveRow[] $result */` before fetchAll(), assign to `$result`, return `$result`
- Selection-based fetchAll() (`$this->getTable()->...->fetchAll()`) already returns `ActiveRow[]`

### property.nonObject on insert() result
- `Selection::insert()` returns `ActiveRow|array|bool|int`
- Fix: Add `assert($row instanceof ActiveRow);` after insert() call

### nullsafe.neverNull
- Using `?->` on left side of `??` is unnecessary — PHPStan knows it's never null there
- Fix: Replace `$profile?->first_name ?? ''` with `$profile->first_name ?? ''`

### notIdentical.alwaysTrue
- Pattern: `$data->amount !== '' && $data->amount !== null` — after `!== ''` check, `!== null` is always true for mixed type
- Fix: Remove the redundant `!== null` part

### method.notFound on $form['fieldName']
- `$form['name']` returns `IComponent` via ArrayAccess — methods like addError(), addCondition(), setDisabled() not found
- Fix: Extract to typed variable: `/** @var \Nette\Forms\Controls\BaseControl $control */ $control = $form['name'];`
