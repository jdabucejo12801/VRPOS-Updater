# TODO - Dynamic JSON Relay Proxy (VRPOS-Updater)

- [x] Update `routes/api.php` to expose a generic relay endpoint (POST /api/relay).
- [x] Update `app/Http/Controllers/PayloadController.php`:
  - [x] Validate incoming JSON contains `table`, `primaryKey`, and `records`.
  - [x] Extract `table` and `primaryKey`.
  - [x] Forward the payload immediately to the back-office using Laravel `Http`.
  - [x] Return back-office response (status + body) or error codes (422/502).
- [x] Update `config/services.php` to include back-office destination URL and optional auth token.
- [ ] Provide usage instructions for the external program sending payloads.
- [ ] (Optional) Add timeout/retry behavior and logging for observability.


