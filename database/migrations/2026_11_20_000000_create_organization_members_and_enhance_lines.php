<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Organizations Table
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('identifier', 64)->unique();
                $table->string('status', 32)->default('active');
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->index(['owner_user_id', 'status']);
            });
        }

        // 2. Organization Members (Team & Staff)
        if (! Schema::hasTable('organization_members')) {
            Schema::create('organization_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 32)->default('member');
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->unique(['organization_id', 'user_id']);
                $table->index(['organization_id', 'status']);
            });
        }

        // 3. Corporate Wallets
        if (! Schema::hasTable('organization_wallets')) {
            Schema::create('organization_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
                $table->decimal('balance', 18, 4)->default(0);
                $table->string('currency', 10)->default('COIN');
                $table->timestamps();
            });
        }

        // 4. Corporate Ledger / Transactions
        if (! Schema::hasTable('organization_wallet_transactions')) {
            Schema::create('organization_wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_wallet_id')->constrained('organization_wallets')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 32)->index();
                $table->decimal('amount', 18, 4);
                $table->decimal('balance_before', 18, 4);
                $table->decimal('balance_after', 18, 4);
                $table->string('reference_id', 64)->unique();
                $table->string('description', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['organization_wallet_id', 'created_at'], 'org_wallet_tx_time_idx');
            });
        }

        // 5. Enhance Phone Numbers for Organization & Dedicated Staff Allocation
        if (Schema::hasTable('phone_numbers')) {
            Schema::table('phone_numbers', function (Blueprint $table) {
                if (! Schema::hasColumn('phone_numbers', 'organization_id')) {
                    $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                }
                if (! Schema::hasColumn('phone_numbers', 'assigned_user_id')) {
                    $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('phone_numbers', 'is_organization')) {
                    $table->boolean('is_organization')->default(false)->index();
                }
            });
        }

        // 6. Enhance Service Resource Allocations (Custom IDs / Virtual lines)
        if (Schema::hasTable('service_resource_allocations')) {
            Schema::table('service_resource_allocations', function (Blueprint $table) {
                if (! Schema::hasColumn('service_resource_allocations', 'organization_id')) {
                    $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                }
                if (! Schema::hasColumn('service_resource_allocations', 'assigned_user_id')) {
                    $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('service_resource_allocations', 'is_organization')) {
                    $table->boolean('is_organization')->default(false)->index();
                }
            });
        }

        // 7. Enhance Contacts for Shared Corporate Directory
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table) {
                if (! Schema::hasColumn('contacts', 'organization_id')) {
                    $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                }
                if (! Schema::hasColumn('contacts', 'is_shared')) {
                    $table->boolean('is_shared')->default(false)->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropColumn('is_shared');
            });
        }

        if (Schema::hasTable('service_resource_allocations')) {
            Schema::table('service_resource_allocations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropConstrainedForeignId('assigned_user_id');
                $table->dropColumn('is_organization');
            });
        }

        if (Schema::hasTable('phone_numbers')) {
            Schema::table('phone_numbers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropConstrainedForeignId('assigned_user_id');
                $table->dropColumn('is_organization');
            });
        }

        Schema::dropIfExists('organization_wallet_transactions');
        Schema::dropIfExists('organization_wallets');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
