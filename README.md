# Harvest Bundle

Consume Harvest's catalog and selected folios over HTTP. Persist the catalog in
**your application's existing database**, without installing dataset-bundle or
connecting to Harvest's database.

## Ownership and storage

- `survos/dataset-bundle` owns the producer registry and pipeline in Harvest.
- `survos/harvest-bundle` owns the consumer's catalog, selection and sync checkpoint.
- `survos/folio-bundle` opens, reads, archives, restores and downloads individual
  folio files. Their contents remain in those files, not in the application DB.

The bundle extends `AbstractSurvosBundle` and uses kit's `HasDoctrineEntities`.
The four entities in `src/Entity` auto-register with the default entity manager:

| Entity | Table | Purpose |
| --- | --- | --- |
| `Dataset` | `harvest_dataset` | Remote identity, source, provider and public metadata snapshot; `row_count`, `tags`, `content_type` and `locales` are also columns so the app can sort and facet in DQL |
| `Artifact` | `harvest_artifact` | Each artifact type/code, remote revision, URL and last locally acquired revision |
| `SetMembership` | `harvest_set_membership` | Recorded dataset membership and download policy per named set |
| `HarvestSyncCheckpoint` | `harvest_sync_checkpoint` | Last successfully applied feed cursor and dataset map |

No connection or entity manager is created. The application owns its schema
migrations. Producer pipeline fields are not modeled here; arbitrary artifact
types are tracked, while file acquisition currently supports folios only.

## Install and configure

Requires PHP 8.5+, Symfony 8.1+, Doctrine ORM, kit-bundle and folio-bundle.
API Platform and dataset-bundle are not dependencies.

```bash
composer require survos/harvest-bundle
```

Flex registers `Survos\HarvestBundle\SurvosHarvestBundle`; without Flex,
register it and its installed dependency bundles in `config/bundles.php`.
Options are defined in `SurvosHarvestBundle::configure()`. Configure selections
in the **application's** `config/packages/survos_harvest.yaml`:

```yaml
survos_harvest:
    folio_sets:
        tours:
            label: Tours
            download: true
            criteria:
                provider: [curatescape]
                minRows: 1
```

Set `download: false` to record membership and metadata without acquiring files.
When sets overlap, an artifact is downloaded if any matching set requests it.
Supported criteria are `provider`, `tags` (any), `tagsAll`, `contentType`,
`minRows` and explicit `folios` (dataset keys). Metadata criteria intersect;
explicit keys additionally select those datasets. A missing explicit key fails
loudly. Empty criteria select nothing. All remote datasets and artifact metadata
are stored in the local catalog; sets determine membership and file acquisition.

The default server is `https://harvest.survos.com`. An absent or empty
`HARVEST_SERVER` uses that default. Override it in `.env.local` for development:

```dotenv
HARVEST_SERVER=https://127.0.0.1:8011
```

Folios land under `APP_DATA_DIR` (folio-bundle's `data_dir`). Published folios are
public, so no token is needed; `HARVEST_READ_TOKEN` is optional and reserved for
private folios. A token that is sent must be valid. You can also configure `server`
and `read_token` directly. The bundle configures folio-bundle's dataset API and
server for downloads; remove conflicting legacy `survos_folio.folio_server` or
`dataset_api` settings. `local_passthrough: true` uses an existing shared folio
volume; it never shares a registry database. `lock_factory` defaults to
`lock.factory` and must use a store shared by sync workers for this application.
The lock key is derived from the application's database, so separate apps on one
machine (each with `LOCK_DSN=flock`) never block each other's syncs.

## Preview, migrate and sync

```bash
bin/console harvest:sync --dry
bin/console doctrine:migrations:diff
# Review the generated migration, then apply it:
bin/console doctrine:migrations:migrate
bin/console harvest:sync
bin/console harvest:sync:status
```

`--dry` reads Harvest and any existing local checkpoint, evaluates sets and prints
the proposed selection. It writes no catalog, checkpoint, membership, lock or
folio files and performs no downloads. With no Harvest tables, it previews an
initial full sync without creating schema. `--full` forces complete reconciliation.

A real sync locks, fetches the current catalog/change feed, acquires selected
folios, then commits dataset metadata, artifacts, membership and checkpoint in
one database transaction. A failed download or database write leaves the cursor
unchanged. Downloads are outside the DB transaction and may already exist after
a later failure; retries are idempotent. Removed remote records/membership are
reconciled, but local folio files are never deleted automatically. Uncompressed
artifacts remain tracked; acquisition checks for an existing local file until
Harvest publishes an archive.

Inject `Survos\HarvestBundle\Catalog\HarvestCatalog` and call
`members('tours')` to get `FolioCatalogEntry` objects from recorded membership.
This read makes no HTTP call. Migrate and run an initial sync before switching
application pages to the local catalog.

## Administration UI

Visit `/admin/harvest/` as a user with `ROLE_ADMIN`. The local catalog, dataset
details, artifact revisions and folio-set membership are read from the app DB.
**Preview sync** and **Sync now** use the same service and lock as the console
command. Both actions require a CSRF-protected POST. Sync runs within the web
request; use the console for acquisitions that exceed your web request timeout.

**Search Harvest API** queries Harvest from the application server using its
configured read token, without sending that token to the browser or requiring a
Harvest login. Search filters the paginated remote catalog by text and provider;
the catalog is cached for five minutes, with an explicit refresh. Results include
dataset keys for adding to folio-set configuration. Searching does not change
membership or download files. **Help & folio sets** explains selections,
track-only sets, sync, setup and the application's current selection rules.

With Tabler installed, a Harvest menu is added to `ADMIN_NAVBAR_MENU` for admins.
Other layouts can link to the `survos_harvest_admin` route, as Rutado's Settings
page does. Templates extend `base.html.twig`; override `base_layout` if needed.
Kit's `routes_enabled` and `route_prefix` options control route registration;
set `admin_navbar_menu: false` to omit the menu entry.

## Migration status

Rutado is the first consumer integration. Published `2.37.1` was validated there
with local migrations, a dry run and a real sync. The administration UI described
above is included starting with `2.37.2`.

Existing dataset-bundle sync services and folio-bundle configuration remain
available for unmigrated consumers. Do not enable the old
`survos_dataset.harvest_sync` alongside this bundle: both expose `harvest:sync`.
Remaining consumers can migrate independently; legacy dataset-bundle sync must be disabled first. The old `Harvest` attribute and
metadata registry are deprecated scaffold classes, not the persistent catalog.

## Validation and release

```bash
# In mono
vendor/bin/phpunit -c bu/harvest-bundle/phpunit.xml.dist
composer validate --strict bu/harvest-bundle/composer.json
# Standalone package checkout
composer install
composer test
```

Source lives in `survos/mono`, under `bu/harvest-bundle`, already registered in
`.github/workflows/split.yml`. Coordinate releases with the release owner;
never replace a published tag.

## Scheduled sync and application projections (next patch)

The bundle registers Symfony's `harvest` schedule by default. It runs `harvest:sync`
every two minutes (`schedule_interval`, or the `HARVEST_SYNC_INTERVAL` env var, e.g.
`"30 seconds"`), retaining its schedule state and replaying only the last missed
run. Set `schedule_enabled: false` to opt out. A set that names a folio Harvest does
not list logs a warning; the rest of the sync proceeds. Run a persistent worker:

```bash
bin/console messenger:consume scheduler_harvest --time-limit=3600 --memory-limit=256M
bin/console debug:scheduler harvest
```

For Dokku, declare that command as a Procfile process and scale it to one worker;
a schedule definition alone does not start a background process. Use a shared
Symfony Lock store across web, scheduler and CLI instances.

`survos_harvest.sites` owns the host-to-set mappings, and each set can specify its
presentation `core` (default `obj`). These are forwarded to folio-bundle's existing
presentation services for compatibility. Their recorded membership is read from the
application database through `RecordedMembershipProvider`, with no file-cache or
HTTP fallback. `catalog_ttl` also belongs under `survos_harvest`.

`Survos\HarvestBundle\Event\HarvestSyncedEvent` is dispatched after membership
is flushed, inside the transaction and before advancing the checkpoint. Listeners
can read the new membership and update app-owned projections using the default
entity manager; throwing rolls back both projections and the catalog/checkpoint.
Listeners run on every real sync so configuration or local-file changes are applied
without requiring a remote revision change. Dry runs do not dispatch the event.
Keep irreversible external effects out of these listeners.

## Application-owned selection

Apps whose database determines which folios to acquire can implement
`Survos\HarvestBundle\Sync\HarvestSelection` and alias that interface to their
service. `plan()` receives the complete reconciled checkpoint and returns a
`SyncPlan` containing selected artifacts, download flags and set membership.
Read application selection on every call, retain the complete checkpoint, and
keep planning free of writes/downloads so `harvest:sync --dry` stays safe.
The default implementation remains the configured `folio_sets` selector.
