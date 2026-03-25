<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('DB_BACKUP_ENABLED', true),

    'schedule_time' => (string) env('DB_BACKUP_SCHEDULE_TIME', '03:30'),

    'directory' => (string) env('DB_BACKUP_LOCAL_DIRECTORY', 'backups/database'),

    'filename_prefix' => (string) env('DB_BACKUP_FILENAME_PREFIX', 'nexa'),

    'retention_days' => (int) env('DB_BACKUP_RETENTION_DAYS', 14),

    'pg_dump_binary' => (string) env('DB_BACKUP_PG_DUMP_BINARY', 'pg_dump'),

    'timeout_seconds' => (int) env('DB_BACKUP_TIMEOUT_SECONDS', 600),

    'gcs' => [
        'enabled' => (bool) env('DB_BACKUP_GCS_ENABLED', false),
        'disk' => (string) env('DB_BACKUP_GCS_DISK', 'gcs'),
        'directory' => (string) env('DB_BACKUP_GCS_DIRECTORY', 'db-backups'),
    ],

    'mail' => [
        'enabled' => (bool) env('DB_BACKUP_EMAIL_ENABLED', false),
        'recipients' => (static function (): array {
            $raw = (string) env('DB_BACKUP_NOTIFY_EMAILS', '');

            return array_values(array_filter(array_map(
                static fn(string $item): string => trim($item),
                explode(',', $raw)
            )));
        })(),
        'attach_file' => (bool) env('DB_BACKUP_EMAIL_ATTACH_FILE', false),
        'max_attachment_mb' => (int) env('DB_BACKUP_EMAIL_MAX_ATTACHMENT_MB', 8),
        'subject_prefix' => (string) env('DB_BACKUP_EMAIL_SUBJECT_PREFIX', '[Nexa DB Backup]'),
    ],
];
