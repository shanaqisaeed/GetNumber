<?php

namespace Tests\Feature\System;

use App\Contracts\SystemCacheServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PhoneNumber $phone;
    protected SystemCacheServiceInterface $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = app(SystemCacheServiceInterface::class);

        $prefix = PhonePrefix::create([
            'prefix' => '98',
            'name' => 'ایران',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->user = User::factory()->create([
            'name' => 'کاربر تستی کش',
            'username' => 'cache_test_user',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->phone = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '10002000',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);
    }

    public function test_message_creation_invalidates_and_primes_fresh_cache(): void
    {
        $cacheKey = "line:{$this->phone->id}:conversations";

        // Initial get primes cache (empty conversations)
        $initial = $this->cacheService->getLineConversations($this->phone);
        $this->assertCount(0, $initial);
        $this->assertTrue(Cache::has($cacheKey));

        // Create new incoming message
        $msg = Message::create([
            'phone_number_id' => $this->phone->id,
            'destination_number' => $this->phone->number,
            'sender' => '989120000000',
            'peer_number' => '989120000000',
            'body' => 'سلام، این یک پیامک تستی برای تست کش است.',
            'direction' => MessageDirection::Inbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        // Cache must have been immediately re-primed with the fresh conversation!
        $this->assertTrue(Cache::has($cacheKey));
        $cachedConversations = $this->cacheService->getLineConversations($this->phone);
        $this->assertCount(1, $cachedConversations);
        $this->assertEquals('989120000000', $cachedConversations[0]['peer_number']);
        $this->assertEquals('سلام، این یک پیامک تستی برای تست کش است.', $cachedConversations[0]['latest_message']['body']);

        // Latest message tracker must be updated
        $this->assertEquals($msg->id, $this->cacheService->getLineLatestMessageId($this->phone->id));
        $this->assertEquals($msg->id, $this->cacheService->getGlobalLatestMessageId());
    }

    public function test_user_allocated_numbers_are_cached_and_invalidated_on_change(): void
    {
        $cacheKey = "user:{$this->user->id}:allocated_number_ids";

        // First call caches user numbers
        $numbers = $this->cacheService->getUserAllocatedNumbers($this->user);
        $this->assertInstanceOf(Collection::class, $numbers);
        $this->assertCount(1, $numbers);
        $this->assertTrue(Cache::has($cacheKey));

        // Allocating another number should invalidate cache
        $prefix = PhonePrefix::first();
        PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '30004000',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->assertFalse(Cache::has($cacheKey));

        // Re-fetch should now return 2 numbers and re-cache
        $fresh = $this->cacheService->getUserAllocatedNumbers($this->user);
        $this->assertInstanceOf(Collection::class, $fresh);
        $this->assertCount(2, $fresh);
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_admin_users_dropdown_returns_collection_and_is_cached(): void
    {
        $users = $this->cacheService->getAdminUsersDropdown();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertNotEmpty($users);
        $this->assertTrue(Cache::has('admin:users:dropdown'));

        // Each element has id, name, username
        $first = $users->first();
        $this->assertNotNull($first->id);
        $this->assertNotNull($first->name);
        $this->assertNotNull($first->username);
    }

    public function test_self_healing_from_incomplete_class_in_cache(): void
    {
        // Simulate an incomplete class in cache (unserialized object without definition)
        $incomplete = unserialize('O:16:"NonExistentClass":0:{}');

        Cache::put('admin:users:dropdown', $incomplete, 3600);
        Cache::put("user:{$this->user->id}:allocated_number_ids", $incomplete, 3600);
        Cache::put("line:{$this->phone->id}:conversations", $incomplete, 3600);

        // SystemCacheService must detect the incomplete class, purge it, and return valid Collections!
        $users = $this->cacheService->getAdminUsersDropdown();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertNotEmpty($users);

        $allocated = $this->cacheService->getUserAllocatedNumbers($this->user);
        $this->assertInstanceOf(Collection::class, $allocated);
        $this->assertCount(1, $allocated);

        $convs = $this->cacheService->getLineConversations($this->phone);
        $this->assertInstanceOf(Collection::class, $convs);
    }

    public function test_admin_dashboard_stats_are_cached_and_invalidated_on_user_creation(): void
    {
        $initialStats = $this->cacheService->getAdminDashboardStats();
        $this->assertTrue(Cache::has('admin:dashboard:stats'));
        $initialUserCount = $initialStats['users']['total'];

        // Creating a new user must bust admin:dashboard:stats
        User::factory()->create();

        $this->assertFalse(Cache::has('admin:dashboard:stats'));

        $freshStats = $this->cacheService->getAdminDashboardStats();
        $this->assertEquals($initialUserCount + 1, $freshStats['users']['total']);
    }

    public function test_user_dashboard_stats_are_cached(): void
    {
        $stats = $this->cacheService->getUserDashboardStats($this->user);
        $this->assertTrue(Cache::has("user:{$this->user->id}:dashboard_stats"));
        $this->assertEquals(1, $stats['quota']['active']);
    }
}
