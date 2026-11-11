# silverstripe-flow

Domain-neutral case files ("Fallakte") for Silverstripe 6, built on
[`atwx/silverstripe-frontdesk-kit`](https://github.com/atwx/silverstripe-frontdesk-kit).

An extension turns any domain class into a case file with:

- phases and statuses with allowed transitions per role and a status history
- field permissions per field group × role × status (hidden / read / write)
- signed, revocable edit links for external collaborators without an account
- custom fields stored as JSON, usable in forms, lists, filters and exports
- a timeline of notes, status changes and link accesses
- configuration as database records, importable/exportable as YAML

## Configuration

Workflows are maintained in the CMS under *Abläufe* (`FlowAdmin`), which needs the
permission `FLOW_CONFIGURE`. A workflow belongs to one domain class and holds
phases, statuses, transitions (with the roles allowed to perform them) and roles
(internal roles map to a security group, external roles act through edit links).
Every record has a stable `Code` for configuration files and hooks.

The classes offered as case file class are configured for now:

```yaml
Atwx\Flow\Model\Workflow:
  case_classes:
    - App\Models\Event
```

> Status: under construction (MVP). The demo project is
> [`atwx/silverstripe-flow-demo`](https://github.com/atwx/silverstripe-flow-demo).

## Requirements

- PHP 8.4
- Silverstripe framework 6
- `atwx/silverstripe-frontdesk-kit` (dev-main during the MVP)

## Development

The module is developed inside the demo project
[`atwx/silverstripe-flow-demo`](https://github.com/atwx/silverstripe-flow-demo):
there it is installed from source, so `vendor/atwx/silverstripe-flow` is a git clone
of this repository (see the demo README).

```sh
cd silverstripe-flow-demo
ddev flow-test                      # all module tests
ddev flow-test --filter Workflow    # PHPUnit arguments are passed through
```

`ddev flow-test` runs PHPUnit with the root DB user (SapphireTest creates temporary
databases) and a fresh manifest.

### Standalone (as in CI)

CI installs the module on its own, with `atwx/silverstripe-frontdesk-kit` checked out
next to it as a Composer `path` repository (`../silverstripe-frontdesk-kit`), and runs:

```sh
composer install
composer lint      # PHPCS (PSR-12)
composer phpstan   # PHPStan + Silverstan
composer test      # PHPUnit, needs SS_DATABASE_* with CREATE DATABASE privileges
```

## License

BSD-3-Clause
