# Contributing

## Running the checks

```sh
composer install
composer test        # phpunit — no network, no WordPress needed
composer lint        # phpcs, WordPress Coding Standards
composer analyse     # phpstan level 6
```

## Integration tests

The unit tests stub WordPress. They are useful but they have already missed real
bugs — three of them, including type hints that took the site down on upload —
because the stubs agreed with the assumptions being tested. Anything touching a
WordPress hook needs the integration harness:

```sh
docker compose -f docker-compose.test.yml up -d --build --wait
docker compose -f docker-compose.test.yml exec -T wp bash -c '
  printf "\nrequire_once \x27/harness/anvil-config.php\x27;\n" >> /var/www/html/wp-config.php
  mkdir -p /var/www/html/wp-content/mu-plugins
  cp /mu-plugin/anvil-media-gcs.php /var/www/html/wp-content/mu-plugins/'
docker compose -f docker-compose.test.yml exec -T wp bash /harness/run.sh
```

It runs against `fake-gcs-server`, so it needs no credentials and no network.

## Coding standards

WordPress Coding Standards, not PSR-12 — tabs, spaced parentheses. Matching the
host application matters more than matching the PHP-FIG default. `composer
lint:fix` fixes most violations automatically.

`src/` is held to WordPress-Docs as well: this is a library other people read.
`tests/` is not — a test method name states the behaviour, and a docblock
repeating it is noise.

## Commit messages

Conventional Commits. Explain *why* in the body; the diff already shows what.
