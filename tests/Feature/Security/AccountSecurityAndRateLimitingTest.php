<?php

namespace Tests\Feature\Security;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Exceptions\ContentSecurityViolationException;
use App\Exceptions\TierLimitExceededException;
use App\Models\PhoneNumber;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\MessageService;
use App\Services\Security\ContentSecurityFilterService;
use App\Services\Security\SuspiciousBehaviorEngine;
use App\Services\Security\TieredRateLimiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccountSecurityAndRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected MessageService $messageService;
    protected ContentSecurityFilterService $contentFilter;
    protected TieredRateLimiterService $rateLimiter;
    protected SuspiciousBehaviorEngine $behaviorEngine;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->messageService = app(MessageService::class);
        $this->contentFilter = new ContentSecurityFilterService();
        $this->rateLimiter = new TieredRateLimiterService();
        $this->behaviorEngine = new SuspiciousBehaviorEngine();
    }

    public function test_content_filter_blocks_phishing_keywords(): void
    {
        $user = User::factory()->create();

        $this->expectException(ContentSecurityViolationException::class);

        // Contains phishing judicial keyword "سامانه ثنا"
        $this->contentFilter->validateContent('ابلاغیه جدید در سامانه ثنا برای شما ثبت گردید', $user);
    }

    public function test_content_filter_blocks_raw_ip_urls(): void
    {
        $user = User::factory()->create();

        $this->expectException(ContentSecurityViolationException::class);

        // Contains raw IP link
        $this->contentFilter->validateContent('برای دریافت جایزه وارد لینک http://185.220.10.5/claim شوید', $user);
    }

    public function test_phishing_attempt_creates_security_incident_and_increases_risk_score(): void
    {
        $user = User::factory()->create(['risk_score' => 0]);

        try {
            $this->contentFilter->validateContent('واریز یارانه معیشتی از طریق لینک زیر', $user);
        } catch (ContentSecurityViolationException) {
            // Expected
        }

        $user->refresh();
        $this->assertEquals(25, $user->risk_score);

        $incident = SecurityIncident::where('user_id', $user->id)->first();
        $this->assertNotNull($incident);
        $this->assertEquals('phishing_attempt', $incident->type);
        $this->assertEquals('high', $incident->severity);
        $this->assertEquals('blocked', $incident->action_taken);
    }

    public function test_new_user_tier_enforces_bulk_batch_size_limit(): void
    {
        // New user has max 3 destinations in bulk request
        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'trust_tier' => 'new',
        ]);

        $this->expectException(TierLimitExceededException::class);

        // Requesting 5 destinations exceeds limit of 3 for 'new' tier
        $this->rateLimiter->enforceLimits($user, 5);
    }

    public function test_rate_limiter_blocks_minute_velocity_burst(): void
    {
        $user = User::factory()->create([
            'trust_tier' => 'standard',
        ]);

        // Standard user: max per minute is 30. Sending 20 is fine.
        $this->rateLimiter->enforceLimits($user, 20);

        $this->expectException(TierLimitExceededException::class);

        // Sending 15 more brings total to 35 > 30 (exceeds per-minute limit)
        $this->rateLimiter->enforceLimits($user, 15);
    }

    public function test_suspicious_behavior_auto_restricts_account_when_threshold_exceeded(): void
    {
        $user = User::factory()->create([
            'trust_tier' => 'standard',
            'risk_score' => 50,
        ]);

        $destinations = ['9121111111', '9122222222', '9123333333'];
        $repeatedBody = 'متن پیامک تکراری جهت تست سامانه رفتار مشکوک';

        // Simulate sending exact same body 8+ times to trip repetitive spam
        for ($i = 0; $i < 3; $i++) {
            $this->behaviorEngine->evaluateSend($user, $destinations, $repeatedBody);
        }

        $user->refresh();
        $this->assertGreaterThanOrEqual(70, $user->risk_score);
        $this->assertEquals('restricted', $user->trust_tier);

        $incident = SecurityIncident::where('user_id', $user->id)
            ->where('type', 'suspicious_behavior_auto_restricted')
            ->first();

        $this->assertNotNull($incident);
        $this->assertEquals('critical', $incident->severity);
        $this->assertEquals('restricted', $incident->action_taken);
    }

    public function test_admin_can_resolve_incident_and_update_tier(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create([
            'trust_tier' => 'restricted',
            'risk_score' => 85,
        ]);

        $incident = SecurityIncident::create([
            'user_id' => $user->id,
            'type' => 'rate_limit_tripped',
            'severity' => 'high',
            'action_taken' => 'restricted',
        ]);

        // 1. Resolve incident
        $response = $this->actingAs($admin)
            ->postJson(route('admin.security.incidents.resolve', $incident->id));

        $response->assertOk();
        $incident->refresh();
        $this->assertTrue($incident->isResolved());
        $this->assertEquals($admin->id, $incident->resolved_by);

        // 2. Restore user tier
        $tierResponse = $this->actingAs($admin)
            ->patchJson(route('admin.security.users.tier', $user->id), [
                'trust_tier' => 'standard',
                'reset_risk_score' => true,
            ]);

        $tierResponse->assertOk();
        $user->refresh();
        $this->assertEquals('standard', $user->trust_tier);
        $this->assertEquals(0, $user->risk_score);
    }
}
