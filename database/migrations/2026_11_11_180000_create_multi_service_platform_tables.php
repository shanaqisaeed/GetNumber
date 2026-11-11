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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'account_tier')) {
                $table->string('account_tier', 32)->default('individual')->after('role')->index();
            }
            if (! Schema::hasColumn('users', 'organization_name')) {
                $table->string('organization_name', 120)->nullable()->after('account_tier');
            }
            if (! Schema::hasColumn('users', 'enterprise_contract_code')) {
                $table->string('enterprise_contract_code', 64)->nullable()->after('organization_name');
            }
            if (! Schema::hasColumn('users', 'enterprise_settings')) {
                $table->json('enterprise_settings')->nullable()->after('enterprise_contract_code');
            }
        });

        Schema::create('service_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('driver_class', 191);
            $table->string('category', 64)->index();
            $table->string('channel_type', 64)->index();
            $table->string('icon', 64)->nullable();
            $table->string('translation_prefix', 128);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_purchasable')->default(true)->index();
            $table->boolean('requires_approval_for_enterprise')->default(false);
            $table->json('available_for_roles');
            $table->json('form_schema')->nullable();
            $table->json('validation_policies')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_definition_id')->constrained('service_definitions')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->string('target_tier', 32)->default('individual')->index();
            $table->string('billing_mode', 32)->default('one_time');
            $table->decimal('base_price', 18, 4)->default(0);
            $table->unsignedInteger('quota_units')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_definition_id')->constrained('service_definitions')->restrictOnDelete();
            $table->foreignId('service_plan_id')->nullable()->constrained('service_plans')->nullOnDelete();
            $table->string('account_tier', 32)->default('individual')->index();
            $table->string('order_context', 32)->default('services')->index();
            $table->string('fulfillment_mode', 32)->default('instant');
            $table->string('status', 32)->default('completed')->index();
            $table->string('requested_identifier', 191)->index();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('final_amount', 18, 4)->default(0);
            $table->decimal('held_amount', 18, 4)->default(0);
            $table->string('wallet_reference_id', 64)->nullable()->index();
            $table->foreignId('number_request_id')->nullable()->constrained('number_requests')->nullOnDelete();
            $table->json('input_payload')->nullable();
            $table->json('validation_snapshot')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_definition_id')->constrained('service_definitions')->restrictOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->string('service_key', 64)->index();
            $table->string('channel_type', 64)->index();
            $table->string('resource_identifier', 191)->index();
            $table->string('display_title', 120)->nullable();
            $table->string('account_tier', 32)->default('individual');
            $table->string('tier_badge', 48)->default('standard');
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('total_quota')->default(1);
            $table->unsignedInteger('used_quota')->default(0);
            $table->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
            $table->json('capabilities')->nullable();
            $table->json('routing_config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['service_key', 'resource_identifier', 'status'], 'svc_alloc_key_ident_status_idx');
            $table->index(['user_id', 'service_key', 'status'], 'svc_alloc_user_key_status_idx');
        });

        Schema::create('service_sub_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_allocation_id')->constrained('service_resource_allocations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sub_identifier', 64)->index();
            $table->string('full_address', 191)->unique();
            $table->string('display_name', 120)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('routing_metadata')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_sub_resources');
        Schema::dropIfExists('service_resource_allocations');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('service_plans');
        Schema::dropIfExists('service_definitions');

        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['account_tier', 'organization_name', 'enterprise_contract_code', 'enterprise_settings'],
                fn (string $col) => Schema::hasColumn('users', $col)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

