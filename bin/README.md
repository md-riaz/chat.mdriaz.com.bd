# CLI Scripts

This directory contains command-line scripts and daemons for the application.

## Scripts

### `scheduler.php`
Scheduler daemon that runs scheduled tasks at specified intervals.

**Usage:**
```bash
php bin/scheduler.php
```

**Features:**
- Runs tasks implementing `TaskInterface`
- Supports "every X min" schedule format
- Graceful shutdown on SIGTERM
- Automatic error handling and logging

### `worker.php` 
Worker daemon that processes queued jobs from the database.

**Usage:**
```bash
php bin/worker.php
```

**Features:**
- Processes jobs implementing `JobInterface`
- Database row locking to prevent race conditions
- Automatic retry and error handling
- Graceful shutdown on SIGTERM

## Running as Services

For production, these scripts should be run as system services (systemd, supervisor, etc.) to ensure they restart automatically on failure.

### Example systemd service for scheduler:
```ini
[Unit]
Description=Task Scheduler Daemon
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/project
ExecStart=/usr/bin/php bin/scheduler.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
