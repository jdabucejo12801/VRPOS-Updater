# VRPOS-Updater — Laravel Queue Worker (Supervisor) Deployment Guide

This document explains how to run the Laravel queue worker **persistently** on a production Linux server using **Supervisor**, so jobs (including `ProcessDatabaseRelay`) are processed automatically without manual `php artisan queue:work` execution.

---

## 1) Prerequisites

### 1.1 Confirm queue is being used
This project dispatches `ProcessDatabaseRelay` jobs via Laravel queues (see `app/Http/Controllers/PayloadController.php`).

Your queue worker will run the `queue:work` command. The Supervisor config below uses the **database queue driver** by default.

### 1.2 SQL Server (`sqlsrv`) extension
Because `ProcessDatabaseRelay` explicitly uses:

- `DB::connection('sqlsrv')`

Ensure the PHP `sqlsrv` / PDO SQLSRV drivers are installed and enabled on the production server.

Check:
```bash
php -m | findstr /I sqlsrv
php -m | findstr /I pdo
```

(Exact commands vary by Linux distro; the check you want is: `sqlsrv` and `pdo_sqlsrv` appear in the loaded modules.)

Optional quick test on the server:
```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::connection('sqlsrv')->select('SELECT 1 as ok')[0]->ok;" 
```

---

## 2) Install Supervisor (Linux)

### Debian/Ubuntu
```bash
sudo apt-get update
sudo apt-get install -y supervisor
sudo systemctl enable --now supervisor
```

### RHEL/CentOS/Amazon Linux
```bash
yum install -y supervisor
sudo systemctl enable --now supervisord
```

Verify Supervisor is running:
```bash
sudo systemctl status supervisor || sudo systemctl status supervisord
```

---

## 3) Configure Supervisor to run Laravel queue worker

### 3.1 Choose a Linux user
Recommended: create a dedicated service user (example: `laravel`).

```bash
sudo adduser --disabled-password --gecos "" laravel
```

Ensure this user can read the Laravel application files and write to `storage/`:

```bash
sudo chown -R laravel:laravel /var/www/VRPOS-Updater
sudo chown -R laravel:laravel /var/www/VRPOS-Updater/storage
```

> Replace `/var/www/VRPOS-Updater` with your actual Laravel path.

### 3.2 Create Supervisor config file
Create:

`/etc/supervisor/conf.d/laravel-worker.conf`

Example configuration:

```ini
[program:laravel-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/VRPOS-Updater/artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-jobs=0
autostart=true
autorestart=true
startretries=10

user=laravel
numprocs=1
redirect_stderr=true

stdout_logfile=/var/www/VRPOS-Updater/storage/logs/queue-worker-stdout.log
stderr_logfile=/var/www/VRPOS-Updater/storage/logs/queue-worker-stderr.log
stdout_logfile_maxbytes=20MB
stderr_logfile_maxbytes=20MB
stdout_logfile_backups=10
stderr_logfile_backups=10

stopwaitsecs=360
startsecs=5
```

#### Why these settings match this project
- `--timeout=120` matches the job’s `$timeout = 120` in `ProcessDatabaseRelay`.
- `--tries=3` matches `$tries = 3`.
- `--max-jobs=0` means “run forever” (typical for persistent workers).
- Laravel logs are written to `storage/logs/`.

### 3.3 Reload Supervisor
Run:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

To confirm it’s actively running:

```bash
sudo supervisorctl tail -f laravel-queue-worker:*
```

---

## 4) Restart queue worker after deployments

During deployments, code changes require restarting workers so the worker process loads the new code.

### 4.1 Deployment command
Run after deploying code (and after composer install / migrations if applicable):

```bash
cd /var/www/VRPOS-Updater
php artisan queue:restart
```

### 4.2 What happens
- Laravel updates its internal “restart token”
- Worker finishes the current job
- Worker exits
- Supervisor detects the exit and restarts the worker with the latest code

---

## 5) Recommended production `.env` settings

Create/edit your production `.env`.

### 5.1 Queue driver (important)
```env
APP_ENV=production
APP_DEBUG=false

QUEUE_CONNECTION=database

DB_QUEUE_CONNECTION=database
DB_QUEUE_TABLE=jobs
DB_QUEUE=default

DB_QUEUE_RETRY_AFTER=120
```

#### Why `DB_QUEUE_RETRY_AFTER=120`
Your job timeout is 120 seconds. Setting retry-after around the same value helps prevent the queue from re-dispatching a job before the worker’s attempt is actually done.

### 5.2 SQL Server connection (`nec5-pc`)
Set the correct SQL Server connection details:
```env
DB_CONNECTION=sqlsrv
DB_HOST=nec5-pc
DB_PORT=1433
DB_DATABASE=EnablerDB
DB_USERNAME=...
DB_PASSWORD=...
```

> Confirm your actual SQL Server DB name. The example uses `EnablerDB` from your description.

---

## 6) Operational checklist (quick)

> Windows note: Supervisor is typically a Linux service. If you need queue automation on Windows, the recommended approach is to use a Windows-friendly process manager (e.g., **NSSM** or **WinSW**) to run `php artisan queue:work` as a background service.

1. Supervisor installed and enabled
2. `laravel-worker.conf` created under `/etc/supervisor/conf.d/`
3. Worker user owns `/var/www/.../storage` (write access)
4. `.env` has correct queue + SQLSRV settings
5. After each deployment, run:
   - `php artisan queue:restart`
6. Monitor:
   - `/var/www/.../storage/logs/queue-worker-stdout.log`
   - `/var/www/.../storage/logs/queue-worker-stderr.log`

---

## 7) Troubleshooting

### 7.1 Worker not starting
Check:
```bash
sudo supervisorctl status
sudo tail -n 200 /var/www/VRPOS-Updater/storage/logs/queue-worker-stderr.log
```

Also confirm the PHP path in Supervisor: `command=/usr/bin/php ...`.

### 7.2 `sqlsrv` connection failures
- Verify network/firewall from the app server to `nec5-pc:1433`
- Verify credentials
- Verify PHP SQLSRV extensions are installed
- Look at Laravel logs (`storage/logs/laravel.log`) for job failure details

### 7.3 Jobs repeatedly failing
Because `ProcessDatabaseRelay` uses `$tries = 3` and `$backoff = [10,30,60]`, failures should eventually go to Laravel failed jobs (if configured) or be visible in logs.

---

## Appendix: Common paths to edit
Update the Supervisor config if your server uses different values:
- Application path: `/var/www/VRPOS-Updater`
- PHP binary: `/usr/bin/php`
- Worker user: `laravel`
- Log paths under `storage/logs/`

End of guide.

