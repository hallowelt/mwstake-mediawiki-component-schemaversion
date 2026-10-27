## MediaWiki Stakeholders Group - Components
# Schema Version tracking

## Storing schema versions

After every execution of `update.php`, schema version will be recorded to the database (`mws_schema_version` table),
unless current schema is the same as the one recorded.

## Checking is update is required

Before deciding whether to run `update.php`, you can execute

```php
php maintenance/SchemaVersion.php

// {"type":"current","hash":"cc7f3a979aac6a9d5dac1679ff091664d88a8018e6633445a8abd0d7d2e55f35","timestamp":"20261027085614","wiki_id":"my_wiki"}
```

to get the hash of the schema in current codebase

and 

```php
php maintenance/SchemaVersion.php --stored

// {"type":"stored","hash":"cc7f3a979aac6a9d5dac1679ff091664d88a8018e6633445a8abd0d7d2e55f35","timestamp":"20261027084437","wiki_id":"my_wiki"}
```

to get the stored hash of the schema in the database.

Then you can compare the two to decide whether to run `update.php`.

## Running in a farming/shared environment

Table holding schema versions can be shared between multiple wikis.

```php
$GLOBALS['wgSharedTables'][] = 'mws_schema_version';
```

In such setup, you can get the current schema hash as described above and then execute:

```php
php maintenance/GetPendingWikis.php --compare-hash=bb77dec285a00822920fa5281954ec450d689d96f0aba005358774e7c61eb9a1

// { "my_wiki": hash, "my_other_wiki": hash... }
```

to get all wiki_ids that do not match the passed hash, and therefore need update.

Additionally, you can use `--exclude-wikis=my_wiki,my_other_wiki` to exclude those wikis from the checks.

## Programmatic usage

Use `MWStake\MediaWiki\Component\SchemaVersion\SchemaVersionManager` to fetch information. See this class for more details.
