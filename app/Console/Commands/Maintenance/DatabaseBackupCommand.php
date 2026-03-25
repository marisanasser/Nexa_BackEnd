<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'maintenance:db-backup
        {--dry-run : Show resolved configuration without generating a backup}
        {--upload-gcs : Force GCS upload for this execution}
        {--notify= : Comma-separated recipient list for this execution}
        {--keep-days= : Retention days override}
        {--skip-prune : Skip retention cleanup}';

    protected $description = 'Create a compressed daily PostgreSQL backup with optional GCS upload and email notification';

    public function handle(): int
    {
        if (!(bool) config('backup.enabled', true) && !$this->option('dry-run')) {
            $this->warn('Database backup is disabled by configuration.');

            return self::SUCCESS;
        }

        $connectionName = (string) config('database.default', 'pgsql');
        $connection = config("database.connections.{$connectionName}");

        if (!is_array($connection)) {
            $this->error("Database connection config not found: {$connectionName}");

            return self::FAILURE;
        }

        if ('pgsql' !== (string) ($connection['driver'] ?? '')) {
            $this->error('This backup command currently supports only PostgreSQL connections.');

            return self::FAILURE;
        }

        $localDirectory = trim((string) config('backup.directory', 'backups/database'), '/\\');
        $localDiskPath = storage_path('app'.DIRECTORY_SEPARATOR.$localDirectory);
        $filenamePrefix = trim((string) config('backup.filename_prefix', 'nexa'));
        $filenamePrefix = '' !== $filenamePrefix ? $filenamePrefix : 'nexa';
        $timestamp = Carbon::now('UTC')->format('Ymd_His');
        $filename = "{$filenamePrefix}_{$connectionName}_{$timestamp}_utc.sql.gz";
        $tempSqlPath = $localDiskPath.DIRECTORY_SEPARATOR.str_replace('.sql.gz', '.sql', $filename);
        $compressedPath = $localDiskPath.DIRECTORY_SEPARATOR.$filename;
        $relativeCompressedPath = $localDirectory.'/'.$filename;
        try {
            $keepDays = $this->resolveRetentionDays();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $gcsEnabled = (bool) config('backup.gcs.enabled', false) || (bool) $this->option('upload-gcs');
        $gcsDisk = (string) config('backup.gcs.disk', 'gcs');
        $gcsDirectory = trim((string) config('backup.gcs.directory', 'db-backups'), '/\\');
        $gcsPath = '' !== $gcsDirectory ? "{$gcsDirectory}/{$filename}" : $filename;

        $notifyRecipients = $this->resolveNotifyRecipients();

        if ((bool) $this->option('dry-run')) {
            $this->table(
                ['key', 'value'],
                [
                    ['connection', $connectionName],
                    ['driver', (string) ($connection['driver'] ?? '')],
                    ['local_directory', $localDiskPath],
                    ['backup_filename', $filename],
                    ['retention_days', (string) $keepDays],
                    ['gcs_enabled', $gcsEnabled ? 'yes' : 'no'],
                    ['gcs_disk', $gcsDisk],
                    ['gcs_path', $gcsPath],
                    ['notify_recipients', empty($notifyRecipients) ? '(none)' : implode(', ', $notifyRecipients)],
                    ['pg_dump_binary', (string) config('backup.pg_dump_binary', 'pg_dump')],
                ]
            );

            $this->info('Dry-run finished.');

            return self::SUCCESS;
        }

        try {
            File::ensureDirectoryExists($localDiskPath);

            $this->runPgDump($connection, $tempSqlPath);
            $this->compressSqlFile($tempSqlPath, $compressedPath);
            File::delete($tempSqlPath);
        } catch (Throwable $e) {
            File::delete($tempSqlPath);
            File::delete($compressedPath);

            Log::error('Database backup failed while generating dump', [
                'error' => $e->getMessage(),
                'connection' => $connectionName,
                'path' => $compressedPath,
            ]);

            $this->error('Backup generation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $fileSizeBytes = (int) File::size($compressedPath);
        $cloudUploadError = null;
        $cloudUploadResult = null;

        if ($gcsEnabled) {
            try {
                $cloudUploadResult = $this->uploadToCloud($gcsDisk, $gcsPath, $compressedPath);
            } catch (Throwable $e) {
                $cloudUploadError = $e->getMessage();

                Log::error('Database backup generated but cloud upload failed', [
                    'error' => $cloudUploadError,
                    'disk' => $gcsDisk,
                    'path' => $gcsPath,
                    'local_path' => $compressedPath,
                ]);
            }
        }

        $localPruned = 0;
        $cloudPruned = 0;
        if (!(bool) $this->option('skip-prune') && $keepDays > 0) {
            $cutoff = Carbon::now()->subDays($keepDays);
            $localPruned = $this->pruneLocalBackups($localDiskPath, $cutoff);

            if ($gcsEnabled && null === $cloudUploadError) {
                try {
                    $cloudPruned = $this->pruneCloudBackups($gcsDisk, $gcsDirectory, $cutoff);
                } catch (Throwable $e) {
                    Log::warning('Cloud backup prune failed', [
                        'disk' => $gcsDisk,
                        'directory' => $gcsDirectory,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $emailError = null;
        if (!empty($notifyRecipients)) {
            try {
                $this->sendSummaryEmail(
                    $notifyRecipients,
                    $connectionName,
                    $relativeCompressedPath,
                    $fileSizeBytes,
                    $cloudUploadResult,
                    $cloudUploadError,
                    $localPruned,
                    $cloudPruned,
                    $compressedPath
                );
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
                Log::warning('Backup summary email failed', [
                    'recipients' => $notifyRecipients,
                    'error' => $emailError,
                ]);
            }
        }

        $this->info('Backup created successfully.');
        $this->line('Local file: storage/app/'.$relativeCompressedPath);
        $this->line('Size: '.$this->formatBytes($fileSizeBytes));

        if ($gcsEnabled) {
            if (null === $cloudUploadError) {
                $this->line("GCS upload: ok ({$gcsDisk}:{$gcsPath})");
            } else {
                $this->warn('GCS upload failed: '.$cloudUploadError);
            }
        }

        if (!empty($notifyRecipients)) {
            if (null === $emailError) {
                $this->line('Email notification sent to: '.implode(', ', $notifyRecipients));
            } else {
                $this->warn('Email notification failed: '.$emailError);
            }
        }

        if (!(bool) $this->option('skip-prune') && $keepDays > 0) {
            $this->line("Pruned local backups: {$localPruned}");
            if ($gcsEnabled && null === $cloudUploadError) {
                $this->line("Pruned cloud backups: {$cloudPruned}");
            }
        }

        if (null !== $cloudUploadError) {
            $this->warn('Backup exists locally, but cloud upload failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $connection
     */
    private function runPgDump(array $connection, string $destinationPath): void
    {
        $host = (string) ($connection['host'] ?? '');
        $port = (string) ($connection['port'] ?? '5432');
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $sslmode = (string) ($connection['sslmode'] ?? 'prefer');

        if ('' === $host || '' === $database || '' === $username) {
            throw new RuntimeException('Invalid DB config for backup: host/database/username are required.');
        }

        $command = [
            (string) config('backup.pg_dump_binary', 'pg_dump'),
            '--format=plain',
            '--encoding=UTF8',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--host', $host,
            '--port', $port,
            '--username', $username,
            '--dbname', $database,
            '--file', $destinationPath,
        ];

        $process = new Process(
            $command,
            base_path(),
            [
                'PGPASSWORD' => $password,
                'PGSSLMODE' => $sslmode,
            ]
        );
        $process->setTimeout((int) config('backup.timeout_seconds', 600));
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput().' '.$process->getOutput());
            $normalizedError = strtolower($errorOutput);

            if (str_contains($normalizedError, 'not recognized')
                || str_contains($normalizedError, 'not found')
                || str_contains($normalizedError, 'nao e reconhecido')
                || str_contains($normalizedError, 'não é reconhecido')
            ) {
                throw new RuntimeException('pg_dump was not found. Install PostgreSQL client tools or set DB_BACKUP_PG_DUMP_BINARY with the full binary path.');
            }

            throw new RuntimeException($errorOutput);
        }
    }

    private function compressSqlFile(string $sourcePath, string $destinationPath): void
    {
        $source = fopen($sourcePath, 'rb');
        if (false === $source) {
            throw new RuntimeException("Could not open SQL dump for reading: {$sourcePath}");
        }

        $target = gzopen($destinationPath, 'wb9');
        if (false === $target) {
            fclose($source);
            throw new RuntimeException("Could not open compressed output for writing: {$destinationPath}");
        }

        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if (false === $chunk) {
                    throw new RuntimeException("Error while reading SQL dump: {$sourcePath}");
                }

                gzwrite($target, $chunk);
            }
        } finally {
            fclose($source);
            gzclose($target);
        }
    }

    /**
     * @return array{disk:string,path:string,url:string|null}
     */
    private function uploadToCloud(string $diskName, string $cloudPath, string $localPath): array
    {
        $stream = fopen($localPath, 'rb');
        if (false === $stream) {
            throw new RuntimeException("Could not open local file for upload: {$localPath}");
        }

        try {
            Storage::disk($diskName)->put($cloudPath, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }

        $url = null;
        try {
            $url = Storage::disk($diskName)->url($cloudPath);
        } catch (Throwable) {
            // URL may be unavailable depending on disk visibility/policy.
        }

        return [
            'disk' => $diskName,
            'path' => $cloudPath,
            'url' => $url,
        ];
    }

    private function pruneLocalBackups(string $directoryPath, Carbon $cutoff): int
    {
        if (!File::exists($directoryPath)) {
            return 0;
        }

        $deleted = 0;
        foreach (File::files($directoryPath) as $file) {
            if (!str_ends_with($file->getFilename(), '.sql.gz')) {
                continue;
            }

            if ($file->getMTime() >= $cutoff->getTimestamp()) {
                continue;
            }

            if (File::delete($file->getPathname())) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function pruneCloudBackups(string $diskName, string $directory, Carbon $cutoff): int
    {
        $disk = Storage::disk($diskName);
        $prefix = trim($directory, '/\\');

        $deleted = 0;
        foreach ($disk->files($prefix) as $filePath) {
            if (!str_ends_with($filePath, '.sql.gz')) {
                continue;
            }

            $lastModified = (int) $disk->lastModified($filePath);
            if ($lastModified >= $cutoff->getTimestamp()) {
                continue;
            }

            if ($disk->delete($filePath)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @param string[] $recipients
     * @param array{disk:string,path:string,url:string|null}|null $cloudUploadResult
     */
    private function sendSummaryEmail(
        array $recipients,
        string $connectionName,
        string $relativeCompressedPath,
        int $fileSizeBytes,
        ?array $cloudUploadResult,
        ?string $cloudUploadError,
        int $localPruned,
        int $cloudPruned,
        string $localAbsolutePath
    ): void {
        $subjectPrefix = trim((string) config('backup.mail.subject_prefix', '[Nexa DB Backup]'));
        $subject = "{$subjectPrefix} ".Carbon::now()->format('Y-m-d H:i:s');

        $lines = [
            'Database backup finished.',
            '',
            'Connection: '.$connectionName,
            'Generated at (UTC): '.Carbon::now('UTC')->toDateTimeString(),
            'Local file: storage/app/'.$relativeCompressedPath,
            'File size: '.$this->formatBytes($fileSizeBytes),
            'Pruned local backups: '.$localPruned,
            'Pruned cloud backups: '.$cloudPruned,
        ];

        if (null !== $cloudUploadResult) {
            $lines[] = 'Cloud upload: OK';
            $lines[] = 'Cloud disk: '.$cloudUploadResult['disk'];
            $lines[] = 'Cloud path: '.$cloudUploadResult['path'];
            if (null !== $cloudUploadResult['url']) {
                $lines[] = 'Cloud URL: '.$cloudUploadResult['url'];
            }
        } elseif (null !== $cloudUploadError) {
            $lines[] = 'Cloud upload: FAILED';
            $lines[] = 'Cloud error: '.$cloudUploadError;
        } else {
            $lines[] = 'Cloud upload: not requested';
        }

        $attachFile = (bool) config('backup.mail.attach_file', false);
        $maxAttachmentBytes = max(1, (int) config('backup.mail.max_attachment_mb', 8)) * 1024 * 1024;
        if ($fileSizeBytes > $maxAttachmentBytes) {
            $attachFile = false;
            $lines[] = 'Attachment: skipped because file is larger than configured email limit.';
        }

        Mail::raw(implode(PHP_EOL, $lines), function (Message $message) use ($recipients, $subject, $attachFile, $localAbsolutePath): void {
            $message->to($recipients)->subject($subject);

            if ($attachFile) {
                $message->attach($localAbsolutePath, [
                    'as' => basename($localAbsolutePath),
                    'mime' => 'application/gzip',
                ]);
            }
        });
    }

    /**
     * @return string[]
     */
    private function resolveNotifyRecipients(): array
    {
        $runtime = trim((string) $this->option('notify'));
        if ('' !== $runtime) {
            return array_values(array_filter(array_map(
                static fn(string $item): string => trim($item),
                explode(',', $runtime)
            )));
        }

        $enabled = (bool) config('backup.mail.enabled', false);
        if (!$enabled) {
            return [];
        }

        $recipients = config('backup.mail.recipients', []);
        if (!is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            $recipients
        )));
    }

    private function resolveRetentionDays(): int
    {
        $runtime = $this->option('keep-days');
        if (null !== $runtime && '' !== (string) $runtime) {
            if (!is_numeric((string) $runtime)) {
                throw new RuntimeException('Option --keep-days must be numeric.');
            }

            return max(0, (int) $runtime);
        }

        return max(0, (int) config('backup.retention_days', 14));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, 2).' KB';
        }

        $mb = $kb / 1024;
        if ($mb < 1024) {
            return number_format($mb, 2).' MB';
        }

        return number_format($mb / 1024, 2).' GB';
    }
}
