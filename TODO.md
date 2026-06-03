## Upsert relay revamp - progress

### Step 1: Gather & review existing code
- [x] Read `PayloadController.php`
- [x] Read `ProcessDatabaseRelay.php`

### Step 2: Implement requested payload compatibility (current test shape)
- [x] Keep `sqlsrv` as default connection for now (no connection field in payload yet).


### Step 3: Implement dynamic, schema-agnostic upsert
- [x] Update `ProcessDatabaseRelay.php` to:
  - [x] Use provided `primaryKey` to build WHERE clause (support comma-separated composite keys).
  - [x] Derive insert/update columns from record keys only.
  - [x] Exclude primary key fields from update set.


### Step 4: SQL Server IDENTITY_INSERT handling
- [x] Wrap insert with `SET IDENTITY_INSERT [table] ON/OFF` only for `sqlsrv`.


### Step 5: Testing
- [x] Verify endpoint `/api/relay` accepts the provided Postman JSON.
- [x] Confirm insert/update logic via Laravel logs (inserted/updated and COMPLETED successfully).


