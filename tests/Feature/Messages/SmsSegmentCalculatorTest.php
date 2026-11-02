<?php

namespace Tests\Feature\Messages;

use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SmsSegmentCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsSegmentCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected SmsSegmentCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SmsSegmentCalculatorService();
    }

    public function test_gsm7_single_part_calculation(): void
    {
        $text = "Hello world, this is a standard English GSM-7 test message!";
        $result = $this->calculator->calculate($text, 1);

        $this->assertEquals('GSM-7', $result['encoding']);
        $this->assertEquals(strlen($text), $result['character_count']);
        $this->assertEquals(1, $result['segment_count']);
        $this->assertEquals(160, $result['max_per_segment']);
        $this->assertEquals(160 - strlen($text), $result['chars_remaining_in_segment']);
        $this->assertEquals('0.0500', $result['estimated_cost']);
        $this->assertEquals('0.0500', $result['total_cost']);
    }

    public function test_gsm7_extension_characters_count_as_two_units(): void
    {
        // '{' and '}' and '€' are GSM extension chars (2 units each)
        $text = "Test {abc} €"; // 12 chars length, but 3 extension chars -> 12 + 3 = 15 units
        $result = $this->calculator->calculate($text, 1);

        $this->assertEquals('GSM-7', $result['encoding']);
        $this->assertEquals(12, $result['character_count']);
        $this->assertEquals(15, $result['units_used']);
        $this->assertEquals(1, $result['segment_count']);
    }

    public function test_gsm7_multi_part_segment_calculation(): void
    {
        // 170 characters of pure GSM-7 -> 2 segments (153 chars per segment)
        $text = str_repeat('A', 170);
        $result = $this->calculator->calculate($text, 2); // 2 recipients

        $this->assertEquals('GSM-7', $result['encoding']);
        $this->assertEquals(170, $result['character_count']);
        $this->assertEquals(2, $result['segment_count']);
        $this->assertEquals(153, $result['max_per_segment']);
        // 2 * 153 = 306 max for 2 segments -> 306 - 170 = 136 remaining
        $this->assertEquals(136, $result['chars_remaining_in_segment']);
        // Rate 0.05 * 2 segments = 0.1000 per recipient * 2 recipients = 0.2000 total
        $this->assertEquals('0.1000', $result['estimated_cost']);
        $this->assertEquals('0.2000', $result['total_cost']);
    }

    public function test_ucs2_detection_for_persian_text(): void
    {
        $persianText = "سلام، این یک پیامک آزمایشی فارسی است.";
        $result = $this->calculator->calculate($persianText, 1);

        $this->assertEquals('UCS-2', $result['encoding']);
        $this->assertEquals(mb_strlen($persianText), $result['character_count']);
        $this->assertEquals(1, $result['segment_count']);
        $this->assertEquals(70, $result['max_per_segment']);
        $this->assertEquals(70 - mb_strlen($persianText), $result['chars_remaining_in_segment']);
    }

    public function test_ucs2_multi_part_segment_calculation(): void
    {
        // 80 Persian characters -> 2 segments (67 chars per segment)
        $persianText = str_repeat('سلام ', 16); // 80 chars
        $result = $this->calculator->calculate($persianText, 3); // 3 recipients

        $this->assertEquals('UCS-2', $result['encoding']);
        $this->assertEquals(80, $result['character_count']);
        $this->assertEquals(2, $result['segment_count']);
        $this->assertEquals(67, $result['max_per_segment']);
        // 2 * 67 = 134 -> 134 - 80 = 54 remaining
        $this->assertEquals(54, $result['chars_remaining_in_segment']);
        // 2 segments * 0.05 = 0.1000 single * 3 recipients = 0.3000 total
        $this->assertEquals('0.1000', $result['estimated_cost']);
        $this->assertEquals('0.3000', $result['total_cost']);
    }

    public function test_estimate_cost_api_endpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('messages.estimate_cost'), [
            'body' => 'سلام، این پیام تست است.',
            'destinations_count' => 5,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'encoding' => 'UCS-2',
                'segment_count' => 1,
                'destinations_count' => 5,
                'currency' => 'COIN',
            ],
        ]);
    }
}

