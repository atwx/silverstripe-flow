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

During the MVP the module, the kit and the demo are developed side by side as
Composer `path` repositories:

```
Projects/
  silverstripe-flow/              # this module
  silverstripe-frontdesk-kit/     # the kit (dev-main)
  silverstripe-flow-demo/         # demo project (ddev), mounts both siblings
```

### Running the tools inside the demo (recommended)

```sh
cd ../silverstripe-flow-demo
ddev flow-test                      # all module tests
ddev flow-test --filter Workflow    # PHPUnit arguments are passed through
```

`ddev flow-test` runs PHPUnit with the root DB user (SapphireTest creates temporary
databases) and a fresh manifest.

### Running the tools standalone (as in CI)

From the demo's ddev container, where this checkout is mounted at
`/var/www/silverstripe-flow`:

```sh
ddev exec -d /var/www/silverstripe-flow composer install
ddev exec -d /var/www/silverstripe-flow composer lint      # PHPCS (PSR-12)
ddev exec -d /var/www/silverstripe-flow composer phpstan   # PHPStan + Silverstan
ddev exec -d /var/www/silverstripe-flow \
    SS_DATABASE_CLASS=MySQLDatabase SS_DATABASE_SERVER=db \
    SS_DATABASE_USERNAME=root SS_DATABASE_PASSWORD=root SS_DATABASE_NAME=flow_test \
    composer test
```

The test database user needs `CREATE DATABASE` privileges (`root` in ddev).

## License

BSD-3-Clause
