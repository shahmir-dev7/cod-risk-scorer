# Contributing
1. Fork, branch from `main`.
2. `composer install`, then `composer lint && composer stan && composer test:unit` must pass.
3. Integration tests run in wp-env (see README > Development). New behaviour needs a test.
4. Conventional commits (`feat:`, `fix:`, `test:`, `docs:`). Open a pull request.