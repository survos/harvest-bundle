# Harvest Bundle

Symfony integration for applications consuming folios from the Harvest server.

## Publication scaffold

This package currently provides a bootable bundle and the `survos_harvest`
configuration namespace. It does **not** yet provide `harvest:sync`, catalog
services, folio sets, or checkpoint storage. Keep existing consumer dependencies
and configuration until the extraction is released.

The intended boundary is:

- `survos/dataset-bundle`: the producer registry used by the Harvest application.
- `survos/harvest-bundle`: consumer synchronization, catalog, selection and scheduling.
- `survos/folio-bundle`: opening, reading, archiving, restoring, inflating,
  pulling one folio entry, and browsing folios.

Rutado, ZM, Ink and Fotostory will migrate in a subsequent change. The existing
Harvest attribute and metadata classes remain available but are not registered
as services by this scaffold.

## Installation

Requires PHP 8.5+, Symfony 8.1+ and `survos/kit-bundle`.
After the package is published:

```bash
composer require survos/harvest-bundle
```

Symfony Flex discovers `Survos\HarvestBundle\SurvosHarvestBundle`. If Flex is
not used, register it and `Survos\Kit\SurvosKitBundle` in `config/bundles.php`.
Copy `config/survos_harvest.yaml` to the application's `config/packages/`:

```yaml
survos_harvest: {}
```

No API Platform dependency is required.

## Validation

In a standalone package checkout:

```bash
composer install
composer test
```

From the monorepo:

```bash
vendor/bin/phpunit -c bu/harvest-bundle/phpunit.xml.dist
composer validate --strict bu/harvest-bundle/composer.json
```

## Publication

The source is maintained in `survos/mono`, under `bu/harvest-bundle`, already
listed in `.github/workflows/split.yml`. After an approved merge to `main`, the
split workflow updates `https://github.com/survos/harvest-bundle`. Submit that
repository to Packagist. Coordinate any release tag with the release owner;
do not replace published tags.
