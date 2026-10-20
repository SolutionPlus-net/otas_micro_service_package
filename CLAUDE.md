# otas/microservice

- The service provider does not call `mergeConfigFrom`, and apps keep their own published `config/microservice.php`. Read every new config key with a default in code (`config('microservice.key', default)`) and list it in the release notes, or apps that didn't add it by hand break.
- Changing the request format (`RequestEncryption`, `X-MS-Access-Key`) breaks communication with services on the previous version: release it as a major version and upgrade all services together.
- Tests run on orchestra/testbench: `vendor/bin/phpunit`. Migrations are `.php.stub` files, so tests run them with `TestCase::migratePackageTables()`.
