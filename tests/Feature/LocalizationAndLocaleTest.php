<?php

namespace Tests\Feature;

use App\Enums\ApiTokenStatus;
use App\Enums\CryptoNetwork;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\CircuitBreakerOpenException;
use App\Exceptions\ContentSecurityViolationException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidNumberFormatException;
use App\Exceptions\InvalidNumberRequestException;
use App\Exceptions\InvalidWalletTransactionException;
use App\Exceptions\NumberAllocationException;
use App\Exceptions\NumberAlreadyAllocatedException;
use App\Exceptions\NumberRequestAlreadyReviewedException;
use App\Exceptions\TierLimitExceededException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Exceptions\UserQuotaExceededException;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationAndLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_persian_and_rtl(): void
    {
        $this->assertEquals('fa', SystemSetting::getDefaultLocale());
        $this->assertEquals('rtl', SystemSetting::getLocaleDirection('fa'));
        $this->assertEquals('ltr', SystemSetting::getLocaleDirection('en'));

        $response = $this->get('/');
        $response->assertStatus(200);
        $this->assertEquals('fa', app()->getLocale());
    }

    public function test_user_can_switch_locale_to_english_and_back_to_persian(): void
    {
        // 1. Switch to English
        $response = $this->post(route('locale.update', ['locale' => 'en']));
        $response->assertSessionHas('locale', 'en');
        $response->assertCookie('app_locale', 'en');

        // Verify with session in next request
        $followUp = $this->withSession(['locale' => 'en'])->get('/');
        $followUp->assertStatus(200);
        $this->assertEquals('en', app()->getLocale());

        // 2. Switch back to Persian
        $responseFa = $this->post(route('locale.update', ['locale' => 'fa']));
        $responseFa->assertSessionHas('locale', 'fa');
        $responseFa->assertCookie('app_locale', 'fa');

        $followUpFa = $this->withSession(['locale' => 'fa'])->get('/');
        $followUpFa->assertStatus(200);
        $this->assertEquals('fa', app()->getLocale());
    }

    public function test_invalid_locale_switch_is_safely_ignored(): void
    {
        $response = $this->post(route('locale.update', ['locale' => 'fr']));
        $response->assertSessionMissing('locale');
    }

    public function test_admin_can_update_default_locale_setting(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.settings.general.update'), [
            'app_name' => 'سامانه تست',
            'default_locale' => 'en',
            'max_lines_per_user' => 10,
        ]);

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'general']));
        $this->assertEquals('en', SystemSetting::get('default_locale'));
        $this->assertEquals('en', SystemSetting::getDefaultLocale());
    }

    public function test_all_enums_support_persian_and_english_labels(): void
    {
        // Persian
        app()->setLocale('fa');
        $this->assertEquals('فعال', UserStatus::Active->label());
        $this->assertEquals('راهبر ارشد', UserRole::Admin->label());
        $this->assertEquals('تخصیص‌یافته', PhoneNumberStatus::Allocated->label());
        $this->assertEquals('اعتباری', PhoneNumberType::UserStandard->label());
        $this->assertEquals('دریافتی', MessageDirection::Inbound->label());
        $this->assertEquals('دریافت‌شده', MessageProcessingStatus::Received->label());
        $this->assertEquals('تأیید و تخصیص یافته', NumberRequestStatus::Approved->label());
        $this->assertEquals('تأیید و شارژشده', PaymentStatus::Completed->label());
        $this->assertEquals('شارژ حساب (واریز)', WalletTransactionType::Deposit->label());
        $this->assertEquals('فعال و معتبر', ApiTokenStatus::Active->label());
        $this->assertEquals('پشتیبانی فنی خطوط', TicketDepartment::Technical->label());
        $this->assertEquals('فوری', TicketPriority::Urgent->label());
        $this->assertEquals('در حال بررسی', TicketStatus::InProgress->label());
        $this->assertEquals('شبکه ترون (TRC-20)', CryptoNetwork::Trc20->label());

        // English
        app()->setLocale('en');
        $this->assertEquals('Active', UserStatus::Active->label());
        $this->assertEquals('Super Administrator', UserRole::Admin->label());
        $this->assertEquals('Allocated', PhoneNumberStatus::Allocated->label());
        $this->assertEquals('Standard', PhoneNumberType::UserStandard->label());
        $this->assertEquals('Inbound', MessageDirection::Inbound->label());
        $this->assertEquals('Received', MessageProcessingStatus::Received->label());
        $this->assertEquals('Approved & Allocated', NumberRequestStatus::Approved->label());
        $this->assertEquals('Approved & Credited', PaymentStatus::Completed->label());
        $this->assertEquals('Account Deposit', WalletTransactionType::Deposit->label());
        $this->assertEquals('Active & Valid', ApiTokenStatus::Active->label());
        $this->assertEquals('Technical Support', TicketDepartment::Technical->label());
        $this->assertEquals('Urgent', TicketPriority::Urgent->label());
        $this->assertEquals('In Progress', TicketStatus::InProgress->label());
        $this->assertEquals('Tron Network (TRC-20)', CryptoNetwork::Trc20->label());
    }

    public function test_all_exceptions_support_persian_and_english_messages(): void
    {
        // Persian
        app()->setLocale('fa');
        $this->assertStringContainsString('مدار باز', (new CircuitBreakerOpenException('Nobitex'))->getMessage());
        $this->assertStringContainsString('فیشینگ', (new ContentSecurityViolationException())->getMessage());
        $this->assertStringContainsString('موجودی کیف پول', (new InsufficientWalletBalanceException(100, 50))->getMessage());
        $this->assertStringContainsString('قالب شماره', (new InvalidNumberFormatException())->getMessage());
        $this->assertStringContainsString('ضوابط مجاز', (new InvalidNumberRequestException())->getMessage());
        $this->assertStringContainsString('تراکنش مالی نامعتبر', (new InvalidWalletTransactionException())->getMessage());
        $this->assertStringContainsString('خطا در تخصیص', (new NumberAllocationException())->getMessage());
        $this->assertStringContainsString('تخصیص مجدد', (new NumberAlreadyAllocatedException('9121234567'))->getMessage());
        $this->assertStringContainsString('تعیین تکلیف', (new NumberRequestAlreadyReviewedException())->getMessage());
        $this->assertStringContainsString('سطح کاربری', (new TierLimitExceededException())->getMessage());
        $this->assertStringContainsString('سند حسابداری نامتوازن', (new UnbalancedJournalEntryException('100', '90', 'JE-101'))->getMessage());
        $this->assertStringContainsString('سقف مجاز', (new UserQuotaExceededException(10))->getMessage());

        // English
        app()->setLocale('en');
        $this->assertStringContainsString('circuit breaker open', (new CircuitBreakerOpenException('Nobitex'))->getMessage());
        $this->assertStringContainsString('phishing', (new ContentSecurityViolationException())->getMessage());
        $this->assertStringContainsString('insufficient to complete', (new InsufficientWalletBalanceException(100, 50))->getMessage());
        $this->assertStringContainsString('phone number format is invalid', (new InvalidNumberFormatException())->getMessage());
        $this->assertStringContainsString('meet system criteria', (new InvalidNumberRequestException())->getMessage());
        $this->assertStringContainsString('Invalid wallet transaction', (new InvalidWalletTransactionException())->getMessage());
        $this->assertStringContainsString('allocation failed', (new NumberAllocationException())->getMessage());
        $this->assertStringContainsString('cannot be allocated again', (new NumberAlreadyAllocatedException('9121234567'))->getMessage());
        $this->assertStringContainsString('already been reviewed', (new NumberRequestAlreadyReviewedException())->getMessage());
        $this->assertStringContainsString('tier has been exceeded', (new TierLimitExceededException())->getMessage());
        $this->assertStringContainsString('Unbalanced journal entry', (new UnbalancedJournalEntryException('100', '90', 'JE-101'))->getMessage());
        $this->assertStringContainsString('quota for this account', (new UserQuotaExceededException(10))->getMessage());
    }
}
