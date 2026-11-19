<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InvestmentPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupRestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replaces_exported_records_and_preserves_user_referrals(): void
    {
        $oldUser = User::factory()->create();
        $backup = $this->backupPayload();
        $backup['users'][0]['referred_by'] = 32;

        $this->withBackupFile($backup, function (string $path): void {
            $this->artisan('backup:restore', [
                'file' => $path,
                '--force' => true,
                '--discard-unexported-data' => true,
            ])->assertExitCode(0);
        });

        $this->assertDatabaseMissing('users', ['id' => $oldUser->id]);
        $this->assertDatabaseHas('users', [
            'id' => 31,
            'email' => 'restored@example.test',
            'referred_by' => 32,
            'notifications_read' => '[1,2]',
        ]);
        $this->assertDatabaseHas('investments', ['id' => 501, 'user_id' => 31]);
        $this->assertDatabaseHas('withdrawals', ['id' => 601, 'user_id' => 31]);
        $this->assertDatabaseHas('referral_earnings', ['id' => 701, 'investment_id' => 501]);
        $this->assertDatabaseHas('package_slots', ['package_key' => 'crunch', 'remaining_slots' => 129]);
    }

    public function test_it_refuses_to_delete_unexported_user_data_without_explicit_opt_in(): void
    {
        $user = User::factory()->create();
        DB::table('account_activities')->insert([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Test activity',
            'description' => 'Synthetic test record',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withBackupFile($this->backupPayload(), function (string $path): void {
            $this->artisan('backup:restore', [
                'file' => $path,
                '--force' => true,
            ])->assertExitCode(1);
        });

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('account_activities', 1);
    }

    public function test_it_rejects_a_package_catalog_mismatch_without_changing_database_data(): void
    {
        $user = User::factory()->create();
        $backup = $this->backupPayload();
        $backup['investment_packages']['crunch']['price'] = 1;

        $this->withBackupFile($backup, function (string $path): void {
            $this->artisan('backup:restore', [
                'file' => $path,
                '--force' => true,
            ])->assertExitCode(1);
        });

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_dry_run_validates_the_backup_without_changing_database_data(): void
    {
        $user = User::factory()->create();
        $backup = $this->backupPayload();

        $this->withBackupFile($backup, function (string $path): void {
            $this->artisan('backup:restore', [
                'file' => $path,
                '--dry-run' => true,
            ])->assertExitCode(0);
        });

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('investments', 0);
    }

    public function test_it_requires_force_before_restoring_in_production(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'production';

        $this->withBackupFile($this->backupPayload(), function (string $path): void {
            $this->artisan('backup:restore', [
                'file' => $path,
            ])->assertExitCode(1);
        });

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function backupPayload(): array
    {
        $timestamp = '2026-11-17 10:44:37';

        return [
            'site' => 'Lulu',
            'users' => [
                [
                    'id' => 31,
                    'name' => 'Restored User',
                    'email' => 'restored@example.test',
                    'password' => bcrypt('synthetic-test-password'),
                    'balance' => 50,
                    'notifications_read' => [1, 2],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => 32,
                    'name' => 'Referrer User',
                    'email' => 'referrer@example.test',
                    'password' => bcrypt('synthetic-test-password'),
                    'balance' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'investments' => [
                [
                    'id' => 501,
                    'user_id' => 31,
                    'package_key' => 'crunch',
                    'package_name' => 'Silver',
                    'package_price' => 129,
                    'amount' => 129,
                    'daily_interest_rate' => 0.7,
                    'duration_days' => 150,
                    'starts_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'withdrawals' => [
                [
                    'id' => 601,
                    'user_id' => 31,
                    'amount' => 10,
                    'payment_method' => 'bank_transfer',
                    'status' => 'pending',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'referral_earnings' => [
                [
                    'id' => 701,
                    'user_id' => 32,
                    'referred_user_id' => 31,
                    'investment_id' => 501,
                    'amount' => 5,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'package_slots' => [
                [
                    'id' => 801,
                    'package_key' => 'crunch',
                    'remaining_slots' => 129,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'investment_packages' => InvestmentPackages::all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $backup
     */
    private function withBackupFile(array $backup, callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lulu-backup-');
        file_put_contents($path, json_encode($backup, JSON_THROW_ON_ERROR));

        try {
            $callback($path);
        } finally {
            unlink($path);
        }
    }
}
