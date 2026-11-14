<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create tld_pricing table if not exists
        if (! Schema::hasTable('tld_pricing')) {
            Schema::create('tld_pricing', function (Blueprint $table) {
                $table->id();
                $table->string('tld', 32)->unique();
                $table->decimal('base_price', 18, 4)->default(50.0000);
                $table->decimal('multiplier_rond', 8, 2)->default(2.50);
                $table->decimal('multiplier_semi_rond', 8, 2)->default(1.50);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // Seed default TLDs
        $defaultTlds = [
            ['tld' => 'web3', 'base_price' => 50.0000, 'multiplier_rond' => 2.50, 'multiplier_semi_rond' => 1.50],
            ['tld' => 'trx', 'base_price' => 60.0000, 'multiplier_rond' => 2.50, 'multiplier_semi_rond' => 1.50],
            ['tld' => 'id3', 'base_price' => 50.0000, 'multiplier_rond' => 2.20, 'multiplier_semi_rond' => 1.40],
            ['tld' => 'dao', 'base_price' => 85.0000, 'multiplier_rond' => 3.00, 'multiplier_semi_rond' => 1.80],
            ['tld' => 'org3', 'base_price' => 80.0000, 'multiplier_rond' => 2.80, 'multiplier_semi_rond' => 1.60],
            ['tld' => 'corp', 'base_price' => 95.0000, 'multiplier_rond' => 3.00, 'multiplier_semi_rond' => 1.80],
        ];

        foreach ($defaultTlds as $tldData) {
            DB::table('tld_pricing')->updateOrInsert(
                ['tld' => $tldData['tld']],
                array_merge($tldData, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // 2. Create web3_domains table if not exists
        if (! Schema::hasTable('web3_domains')) {
            Schema::create('web3_domains', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('tld_pricing_id')->nullable()->constrained('tld_pricing')->nullOnDelete();
                $table->string('domain_name', 128);
                $table->string('tld', 32);
                $table->string('tier_badge', 32)->default('regular');
                $table->string('status', 32)->default('active');
                $table->unsignedInteger('max_sub_emails')->default(100);
                $table->timestamp('registered_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['domain_name', 'tld'], 'idx_domain_tld_unique');
                $table->index(['user_id', 'status']);
            });
        }

        // 3. Create email_mailboxes table if not exists
        if (! Schema::hasTable('email_mailboxes')) {
            Schema::create('email_mailboxes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('web3_domain_id')->nullable()->constrained('web3_domains')->cascadeOnDelete();
                $table->string('address', 191)->unique();
                $table->string('display_name', 128)->nullable();
                $table->string('status', 32)->default('active');
                $table->unsignedInteger('quota_mb')->default(1024);
                $table->unsignedInteger('used_mb')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 4. Create email_aliases table if not exists
        if (! Schema::hasTable('email_aliases')) {
            Schema::create('email_aliases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mailbox_id')->constrained('email_mailboxes')->cascadeOnDelete();
                $table->string('alias_address', 191)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 5. Create voice_calls table if not exists
        if (! Schema::hasTable('voice_calls')) {
            Schema::create('voice_calls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
                $table->string('source_identifier', 64);
                $table->string('destination', 64);
                $table->string('direction', 16)->default('outbound');
                $table->string('status', 32)->default('initiated');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->unsignedInteger('billed_minutes')->default(0);
                $table->decimal('rate_per_minute', 12, 4)->default(0.0500);
                $table->decimal('cost', 14, 4)->default(0.0000);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['phone_number_id', 'created_at']);
            });
        }

        // 6. Drop dead duplicate table user_auth_identities if exists
        Schema::dropIfExists('user_auth_identities');

        // 7. Data sync: Ensure all existing users have their identities in user_identities
        if (Schema::hasColumn('users', 'username') || Schema::hasColumn('users', 'email')) {
            $existingUsers = DB::table('users')->get();
            foreach ($existingUsers as $user) {
                // Migrate username identity
                if (isset($user->username) && ! empty($user->username)) {
                    $hasUsername = DB::table('user_identities')
                        ->where('user_id', $user->id)
                        ->where('provider', 'username')
                        ->exists();

                    if (! $hasUsername) {
                        DB::table('user_identities')->insert([
                            'user_id' => $user->id,
                            'provider' => 'username',
                            'provider_id' => strtolower($user->username),
                            'password' => $user->password ?? null,
                            'provider_email' => $user->email ?? null,
                            'provider_data' => json_encode(['migrated' => true]),
                            'linked_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Migrate email identity
                if (isset($user->email) && ! empty($user->email)) {
                    $hasEmail = DB::table('user_identities')
                        ->where('user_id', $user->id)
                        ->where('provider', 'email')
                        ->exists();

                    if (! $hasEmail) {
                        DB::table('user_identities')->insert([
                            'user_id' => $user->id,
                            'provider' => 'email',
                            'provider_id' => strtolower($user->email),
                            'password' => $user->password ?? null,
                            'provider_email' => strtolower($user->email),
                            'verified_at' => $user->email_verified_at ?? null,
                            'provider_data' => json_encode(['migrated' => true]),
                            'linked_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        // 8. Safely add account_id to users table if not exists
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'account_id')) {
                $table->string('account_id', 32)->nullable()->after('id')->index();
            }
        });

        // Populate account_id for existing users
        $allUsers = DB::table('users')->get();
        foreach ($allUsers as $u) {
            if (empty($u->account_id)) {
                DB::table('users')->where('id', $u->id)->update([
                    'account_id' => 'ACC-' . str_pad((string) $u->id, 6, '0', STR_PAD_LEFT),
                ]);
            }
        }

        // 9. Drop indexes on username and email in SQLite if they exist
        try {
            DB::statement('DROP INDEX IF EXISTS users_username_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_index');
        } catch (\Throwable) {
        }

        // 10. Safely drop raw password, email, username columns from users table
        Schema::table('users', function (Blueprint $table) {
            $colsToDrop = [];
            if (Schema::hasColumn('users', 'username')) {
                $colsToDrop[] = 'username';
            }
            if (Schema::hasColumn('users', 'email')) {
                $colsToDrop[] = 'email';
            }
            if (Schema::hasColumn('users', 'password')) {
                $colsToDrop[] = 'password';
            }

            if (! empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add columns to users table
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->after('username');
            }
            if (! Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->after('email');
            }
        });

        // Restore user credentials from user_identities
        $identities = DB::table('user_identities')->get();
        foreach ($identities as $identity) {
            $update = [];
            if ($identity->provider === 'username') {
                $update['username'] = $identity->provider_id;
                if (! empty($identity->password)) {
                    $update['password'] = $identity->password;
                }
            } elseif ($identity->provider === 'email') {
                $update['email'] = $identity->provider_id;
                if (! empty($identity->password)) {
                    $update['password'] = $identity->password;
                }
            }

            if (! empty($update)) {
                DB::table('users')->where('id', $identity->user_id)->update($update);
            }
        }

        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('email_aliases');
        Schema::dropIfExists('email_mailboxes');
        Schema::dropIfExists('web3_domains');
        Schema::dropIfExists('tld_pricing');
    }
};
