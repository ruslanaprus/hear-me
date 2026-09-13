# Quality Gates

HearMe's GitHub Actions workflow runs the module against a fresh Drupal 11 project on both PHP 8.3, the declared minimum, and PHP 8.5, the current supported runtime used by the project. Both matrix jobs run Composer validation and audits, PHPStan, PHPCS, and the full PHPUnit suite.

## Local checks

Install development dependencies from the module directory, then run:

```bash
composer validate --strict
composer audit --locked
composer phpstan
vendor/bin/phpcs
SIMPLETEST_BASE_URL=<drupal-base-url> \
SIMPLETEST_DB=<database-url> \
BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browser_output \
php tests/phpunit.php -c phpunit.xml.dist \
  --log-events-text=/tmp/hear-me-phpunit-events.log
bash scripts/check-deprecation-events.sh /tmp/hear-me-phpunit-events.log
bash scripts/check-release-fixtures.sh
bash scripts/check-package.sh
```

The development dependencies include Drush so PHPStan can analyze the optional command integration. This does not make Drush a runtime requirement for sites installing HearMe.

## PHPStan and deprecations

`phpstan.neon.dist` analyzes production PHP, module, and install code at level 1 with `phpstan-drupal` and its Drupal-aware deprecation rules. The checked-in baseline records 15 pre-existing, precisely matched static-analysis findings. New findings fail CI, and baseline entries containing deprecation findings are prohibited.

PHPUnit continues to print indirect Drupal core and dependency deprecations. CI inspects PHPUnit's event report with a self-tested classifier and fails when PHPUnit classifies a deprecation as self or direct, which catches HearMe code that emits a deprecation or directly calls a deprecated dependency API. Unrecognized classified-event formats also fail so an upstream wording change cannot silently disable the gate. Do not add broad ignore patterns, disable deprecation reporting, or move deprecation findings into the PHPStan baseline. Existing indirect dependency deprecations remain visible until their upstream owners resolve them.

## Update-path fixtures

No HearMe release has been tagged, so release-fixture update testing is not yet applicable. Once release tags exist, CI requires committed files below `tests/fixtures/releases/<tag>/` for every tag and a committed `*Update*Test.php` under `tests/src/` that references the fixture root. Before the first tag is created, add an exported fixture representing that release and an update-path test that installs the fixture and exercises every supported update to the current code. Preserve release fixtures after publication; do not rewrite them to match current install defaults.

## Release archives

`.gitattributes` excludes CI configuration, tests, analysis/test configuration and baselines, helper scripts, local caches, lock files, IDE/environment files, and `vendor/` from exported archives. Product documentation and Composer/module metadata remain included.

`bash scripts/check-package.sh` builds a Git archive from `HEAD`, verifies required installable module files and every tracked production file are present, and rejects known development artifacts. Run it against the committed release candidate; CI also runs it before release. Never add credentials, local environment values, dependency vendors, test output, or generated voice/audio assets to a release archive.
