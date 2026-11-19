<?php

namespace App\Console\Commands;

use App\Support\InvestmentPackages;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {file : Path to a protected JSON backup file, or object key when --disk is set}
        {--disk= : Read the backup from this configured Laravel filesystem disk}
        {--force : Skip the confirmation prompt; required in production}
        {--dry-run : Validate and report without changing the database}
        {--discard-unexported-data : Delete user-related records not included in the backup}';

    protected $description = 'Replace exported application data from a protected JSON backup';

    private const TABLES = [
        'users',
        'investments',
        'withdrawals',
        'referral_earnings',
        'package_slots',
    ];

    private const UNEXPORTED_USER_DATA_TABLES = [
        'identity_verifications',
        'account_activities',
        'account_invitations',
        'passkeys',
        'push_subscriptions',
        'blocked_ips',
        'franchise_applications',
    ];

    private const TRANSIENT_TABLES = [
        'sessions',
        'password_reset_tokens',
        'pin_reset_tokens',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $disk = $this->option('disk');

        if ($disk !== null) {
            $disk = (string) $disk;
            $diskConfig = config("filesystems.disks.{$disk}");

            if ($disk === 's3') {
                $missingS3Settings = [];
                foreach ([
                    'key' => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                    'bucket' => 'AWS_BUCKET',
                ] as $configKey => $environmentKey) {
                    if (! is_array($diskConfig) || empty($diskConfig[$configKey])) {
                        $missingS3Settings[] = $environmentKey;
                    }
                }

                if ($missingS3Settings !== []) {
                    $this->error('The [s3] disk is incomplete.');
                    $this->line('Missing Laravel Cloud environment values: '.implode(', ', $missingS3Settings));
                    $this->line('Attach a private Object Storage bucket to this environment and redeploy, or configure the bucket variables manually.');

                    return self::FAILURE;
                }
            }

            try {
                $storage = Storage::disk($disk);
            } catch (InvalidArgumentException) {
                $diskConfig = config('filesystems.disks.s3');
                $hasS3Credentials = is_array($diskConfig)
                    && ! empty($diskConfig['key'])
                    && ! empty($diskConfig['secret'])
                    && ! empty($diskConfig['bucket']);

                if ($disk === 'backups' && $hasS3Credentials) {
                    $disk = 's3';
                    $this->warn('The [backups] disk is not configured. Falling back to the configured [s3] disk.');
                    $storage = Storage::disk($disk);
                } else {
                    $configuredDisks = array_keys((array) config('filesystems.disks', []));
                    $this->error("The [$disk] disk is not configured.");
                    $this->line('Configured filesystem disks: '.($configuredDisks === [] ? '(none)' : implode(', ', $configuredDisks)));
                    $this->line('Redeploy after attaching the private Object Storage bucket and verify the disk name in Laravel Cloud.');

                    return self::FAILURE;
                }
            }

            if (! $storage->exists($path)) {
                $this->error("The backup object does not exist on the [{$disk}] disk.");

                return self::FAILURE;
            }

            $contents = $storage->get($path);
        } else {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error('The backup file does not exist or cannot be read.');

                return self::FAILURE;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                $this->error('The backup file could not be read.');

                return self::FAILURE;
            }
        }

        try {
            $backup = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('The backup is not valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($backup) || array_is_list($backup) || ($backup['site'] ?? null) !== 'Lulu') {
            $this->error('This is not a supported Lulu backup.');

            return self::FAILURE;
        }

        foreach (self::TABLES as $table) {
            if (! isset($backup[$table]) || ! is_array($backup[$table]) || ! array_is_list($backup[$table])) {
                $this->error("The backup is missing a valid {$table} collection.");

                return self::FAILURE;
            }

            if (! Schema::hasTable($table)) {
                $this->error("The {$table} table is missing. Run the current database migrations first.");

                return self::FAILURE;
            }

            $columns = [];
            $columnTypes = [];
            foreach (Schema::getColumnListing($table) as $column) {
                $columns[$column] = true;
                $columnTypes[$column] = Schema::getColumnType($table, $column);
            }

            foreach ($backup[$table] as $index => $row) {
                if (! is_array($row) || array_is_list($row)) {
                    $this->error("The backup contains an invalid {$table} record at index {$index}.");

                    return self::FAILURE;
                }

                $unknownColumns = array_diff(array_keys($row), array_keys($columns));
                if ($unknownColumns !== []) {
                    $this->error("The backup has fields not present in the current {$table} table. Deploy the matching application version before restoring.");

                    return self::FAILURE;
                }

                try {
                    $backup[$table][$index] = $this->normalizeRow($row, $columnTypes);
                } catch (JsonException|InvalidFormatException $exception) {
                    $this->error("The backup contains invalid data in {$table} record {$index}: ".$exception->getMessage());

                    return self::FAILURE;
                }
            }
        }

        if ($backup['users'] === []) {
            $this->error('The backup contains no users; refusing to replace the current database with an empty account set.');

            return self::FAILURE;
        }

        if (! is_array($backup['investment_packages'] ?? null)
            || array_is_list($backup['investment_packages'])
            || $backup['investment_packages'] != InvestmentPackages::all()) {
            $this->error('The backup package catalog does not match the catalog in this code version. Reconcile package settings before restoring.');

            return self::FAILURE;
        }

        $unexportedCounts = [];
        foreach (self::UNEXPORTED_USER_DATA_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                $unexportedCounts[$table] = DB::table($table)->count();
            }
        }

        if ($this->option('dry-run')) {
            foreach (self::TABLES as $table) {
                $this->line("Would restore {$table}: ".count($backup[$table]).' records');
            }
            foreach ($unexportedCounts as $table => $count) {
                $this->warn("Would delete {$table}: {$count} records not included in the backup");
            }
            $this->info('Backup validation succeeded. No database changes were made.');

            return self::SUCCESS;
        }

        if ($unexportedCounts !== [] && ! $this->option('discard-unexported-data')) {
            $this->error('Unexported user-related records exist and are not included in this backup:');
            foreach ($unexportedCounts as $table => $count) {
                $this->line("  {$table}: {$count}");
            }
            $this->line('Re-run with --discard-unexported-data only if you intend to delete these records.');

            return self::FAILURE;
        }

        $this->warn('This replaces users, investments, withdrawals, referral earnings, and package slots.');
        $this->warn('Sessions and password-reset tokens are cleared. Identity verifications, account activity, invitations, passkeys, push subscriptions, blocked IP records, and franchise applications are not in the backup.');
        if ($unexportedCounts !== []) {
            $this->warn('Records in the unexported user-related tables listed above will be deleted.');
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Production restores require the explicit --force option.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Restore this backup and replace the current data?')) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($backup, $unexportedCounts): void {
            foreach (array_keys($unexportedCounts) as $table) {
                DB::table($table)->delete();
            }

            foreach (self::TRANSIENT_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            foreach (['referral_earnings', 'withdrawals', 'investments', 'users', 'package_slots'] as $table) {
                DB::table($table)->delete();
            }

            $referrers = [];
            foreach ($backup['users'] as $user) {
                $referrers[$user['id']] = $user['referred_by'] ?? null;
                unset($user['referred_by']);
                DB::table('users')->insert($user);
            }

            foreach ($referrers as $userId => $referrerId) {
                if ($referrerId !== null) {
                    DB::table('users')->where('id', $userId)->update(['referred_by' => $referrerId]);
                }
            }

            foreach (['investments', 'withdrawals', 'referral_earnings', 'package_slots'] as $table) {
                foreach ($backup[$table] as $row) {
                    DB::table($table)->insert($row);
                }
            }
        });

        foreach (self::TABLES as $table) {
            $this->line("Restored {$table}: ".count($backup[$table]).' records');
        }
        $this->info('Backup restore completed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columnTypes
     * @return array<string, mixed>
     *
     * @throws JsonException
     * @throws InvalidFormatException
     */
    private function normalizeRow(array $row, array $columnTypes): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value)) {
                $row[$column] = json_encode($value, JSON_THROW_ON_ERROR);

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $columnType = $columnTypes[$column] ?? null;
            if (in_array($columnType, ['date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz'], true)) {
                $format = $columnType === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
                $row[$column] = Carbon::parse($value)->utc()->format($format);
            } elseif ($columnType === 'time') {
                $row[$column] = Carbon::parse($value)->format('H:i:s');
            }
        }

        return $row;
    }
}
