# VRPOS Queue Service Deployment Guide

This guide is for deploying the Laravel queue worker for this project in a way that starts automatically with the server and writes worker process logs to:

- `storage/logs/Output/vrpos-queue-worker.log`
- `storage/logs/Error/vrpos-queue-error.log`

It also explains the Linux equivalent.

## Scope

This project dispatches relay jobs from:

- `app/Http/Controllers/PayloadController.php`

and processes them in:

- `app/Jobs/ProcessDatabaseRelay.php`

Current queue defaults from `config/queue.php`:

- `QUEUE_CONNECTION=database` by default
- queue table: `jobs`
- failed jobs table: `failed_jobs`

Current relay job runtime settings from `ProcessDatabaseRelay`:

- `$timeout = 120`
- `$tries = 4`
- `$backoff = [10, 30, 60]`

## Important deployment note

IIS does not process Laravel queued jobs by itself.

IIS only accepts the API request. The queue worker must run as a separate long-running process.

If you want deployment "without a command", that is realistic for startup and server reboot, but not for code refresh. A long-running `queue:work` process keeps old PHP code in memory until the worker process is restarted.

That means:

- no manual terminal is needed once the service is installed
- but after deploying new queue code, the worker service must still be restarted

On Windows, that means restarting the NSSM service.
On Linux, that means restarting the systemd or Supervisor-managed worker.

## Recommended worker command

Use this command for the queue worker:

```powershell
php artisan queue:work database --queue=default --sleep=3 --tries=4 --timeout=120
```

Why this matches the project:

- `database` matches the default queue connection in `config/queue.php`
- `--timeout=120` matches the relay job timeout
- `--tries=4` matches the relay job retry count

## Windows / IIS / NSSM

### 1. Prerequisites

Install:

- PHP CLI
- SQL Server PHP extensions if this project writes to SQL Server
- NSSM: `https://nssm.cc/download`

Make sure these paths exist:

- `C:\path\to\project\artisan`
- `C:\path\to\php\php.exe`
- `C:\path\to\project\storage\logs\Output`
- `C:\path\to\project\storage\logs\Error`

For this repo, the project path is typically:

```text
C:\inetpub\wwwroot\VRPOS-Updater
```

Adjust the examples if your actual IIS site folder is different.

### 2. Install the queue worker as an NSSM service

Open Command Prompt or PowerShell as Administrator.

Example:

```powershell
nssm install VRPOSQueueWorker
```

In the NSSM UI, set:

#### Application tab

- Path:
  `C:\path\to\php\php.exe`
- Startup directory:
  `C:\path\to\project`
- Arguments:
  `artisan queue:work database --queue=default --sleep=3 --tries=4 --timeout=120`

#### Details tab

- Display name:
  `VRPOS Queue Worker`
- Description:
  `Processes Laravel queued jobs for VRPOS-Updater`

#### I/O tab

- Output (stdout):
  `C:\path\to\project\storage\logs\Output\vrpos-queue-worker.log`
- Error (stderr):
  `C:\path\to\project\storage\logs\Error\vrpos-queue-error.log`

Recommended options:

- Enable file append for stdout
- Enable file append for stderr

#### Exit actions tab

- Restart application on exit

Recommended throttling:

- Delay restart by `5000` ms

### 3. Install the service without the NSSM UI

If you prefer commands:

```powershell
nssm install VRPOSQueueWorker "C:\path\to\php\php.exe" "artisan queue:work database --queue=default --sleep=3 --tries=4 --timeout=120"
nssm set VRPOSQueueWorker AppDirectory "C:\path\to\project"
nssm set VRPOSQueueWorker AppStdout "C:\path\to\project\storage\logs\Output\vrpos-queue-worker.log"
nssm set VRPOSQueueWorker AppStderr "C:\path\to\project\storage\logs\Error\vrpos-queue-error.log"
nssm set VRPOSQueueWorker AppStdoutCreationDisposition 4
nssm set VRPOSQueueWorker AppStderrCreationDisposition 4
nssm set VRPOSQueueWorker Start SERVICE_AUTO_START
nssm set VRPOSQueueWorker AppExit Default Restart
nssm set VRPOSQueueWorker AppThrottle 5000
```

Notes:

- creation disposition `4` means append
- `SERVICE_AUTO_START` starts the worker automatically after reboot

### 4. Start and verify the service

```powershell
nssm start VRPOSQueueWorker
nssm status VRPOSQueueWorker
```

Also verify:

- `storage/logs/Output/vrpos-queue-worker.log`
- `storage/logs/Error/vrpos-queue-error.log`
- `storage/logs/laravel.log`
- `storage/logs/relay-YYYY-MM-DD.log`

Expected behavior:

- stdout log shows worker boot and queue activity
- stderr log shows PHP/runtime errors from the worker process
- Laravel relay log shows `queued`, `processing`, `success`, or `failed`

### 5. Deployment guideline on Windows

If you deploy new queue code, do this as part of the deployment process:

```powershell
nssm restart VRPOSQueueWorker
```

This is the key point: without restarting the worker service, `queue:work` may continue processing jobs using old code loaded before deployment.

Recommended Windows deployment order:

1. Copy new project files
2. Run Composer install if needed
3. Run migrations if needed
4. Clear caches if config/routes/views changed
5. Restart IIS app pool if needed for web code
6. Restart `VRPOSQueueWorker`

Suggested optional post-deploy commands:

```powershell
php artisan optimize:clear
```

If you truly want zero manual commands, put the service restart into your deployment script or CI/CD job. There still needs to be a restart step somewhere.

### 6. NSSM service guidelines

Use these rules consistently:

1. Keep one dedicated queue worker service per app unless you intentionally separate queues.
2. Point stdout and stderr to files under `storage/logs/Output` and `storage/logs/Error`.
3. Use `queue:work`, not `queue:listen`, for production stability.
4. Use `SERVICE_AUTO_START`.
5. Use restart-on-exit so the worker recovers after crashes.
6. Keep the startup directory at the Laravel project root.
7. Use the PHP CLI executable, not the IIS PHP CGI binary.
8. After any queue code deployment, restart the NSSM service.

## Linux guidelines

There are two solid choices:

- `systemd`
- `Supervisor`

If you want the most standard Linux approach today, use `systemd`.

### Option A: systemd

Create:

```text
/etc/systemd/system/vrpos-queue-worker.service
```

Example:

```ini
[Unit]
Description=VRPOS Laravel Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/VRPOS-Updater
ExecStart=/usr/bin/php /var/www/VRPOS-Updater/artisan queue:work database --queue=default --sleep=3 --tries=4 --timeout=120
Restart=always
RestartSec=5
StandardOutput=append:/var/www/VRPOS-Updater/storage/logs/Output/vrpos-queue-worker.log
StandardError=append:/var/www/VRPOS-Updater/storage/logs/Error/vrpos-queue-error.log

[Install]
WantedBy=multi-user.target
```

Then run:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now vrpos-queue-worker
sudo systemctl status vrpos-queue-worker
```

After each deployment:

```bash
sudo systemctl restart vrpos-queue-worker
```

### Option B: Supervisor

Create:

```text
/etc/supervisor/conf.d/vrpos-queue-worker.conf
```

Example:

```ini
[program:vrpos-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/VRPOS-Updater/artisan queue:work database --queue=default --sleep=3 --tries=4 --timeout=120
directory=/var/www/VRPOS-Updater
user=www-data
autostart=true
autorestart=true
startretries=10
numprocs=1
stdout_logfile=/var/www/VRPOS-Updater/storage/logs/Output/vrpos-queue-worker.log
stderr_logfile=/var/www/VRPOS-Updater/storage/logs/Error/vrpos-queue-error.log
stdout_logfile_maxbytes=20MB
stderr_logfile_maxbytes=20MB
stdout_logfile_backups=10
stderr_logfile_backups=10
stopwaitsecs=360
startsecs=5
```

Then run:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

After deployment:

```bash
sudo supervisorctl restart vrpos-queue-worker:*
```

## Log interpretation

There are now three useful layers of logging:

### 1. Process manager logs

Windows NSSM or Linux systemd/Supervisor logs:

- `storage/logs/Output/vrpos-queue-worker.log`
- `storage/logs/Error/vrpos-queue-error.log`

Use these to confirm:

- the worker process started
- the worker crashed
- PHP process-level errors happened

### 2. Laravel app log

- `storage/logs/laravel.log`

Use this for normal Laravel exceptions and framework-level issues.

### 3. Relay monitoring log

- `storage/logs/relay-YYYY-MM-DD.log`

Use this to track relay job lifecycle:

- `queued`
- `queue_failed`
- `processing`
- `success`
- `failed`
- `failed_permanently`
- `empty_records`

Search by `relay_id` to trace one request end to end.

## Troubleshooting

### Worker is installed but jobs are not processed

Check:

1. `.env` uses a queued connection, usually `QUEUE_CONNECTION=database`
2. the `jobs` table exists
3. the worker service is running
4. stdout/stderr log files are being written

### API returns queued but nothing happens

Likely causes:

1. NSSM/systemd/Supervisor service is not running
2. worker is pointing to the wrong project directory
3. worker is using a different PHP executable or wrong `.env`

### New code was deployed but old behavior continues

That usually means the long-running worker was not restarted.

Fix:

- Windows: `nssm restart VRPOSQueueWorker`
- Linux systemd: `sudo systemctl restart vrpos-queue-worker`
- Linux Supervisor: `sudo supervisorctl restart vrpos-queue-worker:*`

### SQL Server relay issues

Check:

- PHP SQLSRV extensions are installed for CLI PHP
- the CLI PHP used by the queue worker is the same expected PHP install
- SQL Server host is reachable from the server
- `relay-YYYY-MM-DD.log` and `laravel.log` for exact exception messages

## Recommended handoff checklist

For Windows/IIS:

1. Confirm PHP CLI path
2. Confirm project root path
3. Confirm `storage/logs/Output` exists
4. Confirm `storage/logs/Error` exists
5. Install NSSM service
6. Start NSSM service
7. Send a test relay payload
8. Confirm:
   - worker stdout log updated
   - worker stderr log stayed clean
   - relay log shows `queued` then `success`

For Linux:

1. Confirm PHP CLI path
2. Confirm project root path
3. Confirm log directories exist and are writable
4. Install systemd or Supervisor config
5. Enable/start service
6. Send a test relay payload
7. Confirm:
   - process manager log updated
   - relay log shows `queued` then `success`

