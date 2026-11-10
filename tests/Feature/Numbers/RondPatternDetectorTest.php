<?php

namespace Tests\Feature\Numbers;

use App\Services\Numbers\RondPatternDetectorService;
use Tests\TestCase;

class RondPatternDetectorTest extends TestCase
{
    private RondPatternDetectorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RondPatternDetectorService::class);
    }

    public function test_all_identical_digits_is_golden_rond(): void
    {
        $result = $this->service->detect('777777777');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $result['tier']);
        $this->assertTrue($result['is_rond']);

        $result4 = $this->service->detect('8888');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $result4['tier']);
    }

    public function test_consecutive_ascending_or_descending_is_golden(): void
    {
        $asc = $this->service->detect('123456');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $asc['tier']);

        $desc = $this->service->detect('987654');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $desc['tier']);
    }

    public function test_triplet_pairs_and_repeating_blocks_are_golden(): void
    {
        // Repeating 2-digit pairs like 121212
        $repeating2 = $this->service->detect('121212');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $repeating2['tier']);

        // 3 pairs like 112233
        $pairs = $this->service->detect('112233');
        $this->assertEquals(RondPatternDetectorService::TIER_GOLDEN, $pairs['tier']);
    }

    public function test_palindrome_is_detected(): void
    {
        $palindrome = $this->service->detect('123321');
        $this->assertTrue($palindrome['is_rond']);
        $this->assertContains($palindrome['tier'], [
            RondPatternDetectorService::TIER_GOLDEN,
            RondPatternDetectorService::TIER_SEMI,
        ]);
    }

    public function test_trailing_zeros_is_detected(): void
    {
        $zeros = $this->service->detect('912000000');
        $this->assertTrue($zeros['is_rond']);

        $zeros4 = $this->service->detect('5000');
        $this->assertTrue($zeros4['is_rond']);
    }

    public function test_random_messy_number_is_regular(): void
    {
        $random = $this->service->detect('48291753');
        $this->assertEquals(RondPatternDetectorService::TIER_REGULAR, $random['tier']);
        $this->assertFalse($random['is_rond']);
    }
}

