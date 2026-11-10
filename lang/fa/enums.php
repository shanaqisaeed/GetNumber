<?php

return [
    'api_token_status' => [
        'active' => 'فعال و معتبر',
        'revoked' => 'لغو‌شده (باطل)',
    ],
    'crypto_network' => [
        'TRON' => 'شبکه ترون (TRON / TRX)',
        'TRC20' => 'شبکه ترون (TRC-20)',
        'ERC20' => 'شبکه اتریوم (ERC-20)',
        'BEP20' => 'شبکه بایننس اسمارت چین (BEP-20)',
    ],
    'message_direction' => [
        'inbound' => 'دریافتی',
        'outbound' => 'ارسالی',
    ],
    'message_processing_status' => [
        'received' => 'دریافت‌شده',
        'processed' => 'پردازش‌شده',
        'failed' => 'خطا در پردازش',
    ],
    'number_request_status' => [
        'pending' => 'در انتظار بررسی',
        'approved' => 'تأیید و تخصیص یافته',
        'rejected' => 'رد شده',
    ],
    'payment_status' => [
        'pending_transfer' => 'در انتظار انتقال ترون',
        'tx_submitted' => 'کد رهگیری ثبت‌شده',
        'verifying' => 'در حال استعلام از شبکه',
        'manual_review' => 'نیازمند بررسی دستی راهبر',
        'completed' => 'تأیید و شارژشده',
        'failed' => 'ناموفق / ردشده',
        'expired' => 'منقضی‌شده',
    ],
    'phone_number_status' => [
        'available' => 'آزاد برای تخصیص',
        'allocated' => 'تخصیص‌یافته',
        'reserved' => 'رزروشده',
        'suspended' => 'تعلیق‌شده',
        'inactive' => 'غیرفعال',
        'expired' => 'منقضی‌شده',
    ],
    'phone_number_type' => [
        'user_standard' => 'اعتباری',
        'user_service' => 'تجاری',
        'platform_service' => 'تجاری سامانه',
    ],
    'ticket_department' => [
        'technical' => 'پشتیبانی فنی خطوط',
        'numbers' => 'امور سرشماره‌های اختصاصی',
        'api' => 'وب‌سرویس و API',
        'general' => 'امور عمومی و مالی',
    ],
    'ticket_priority' => [
        'low' => 'کم',
        'medium' => 'عادی',
        'high' => 'بالا',
        'urgent' => 'فوری',
    ],
    'ticket_status' => [
        'open' => 'در انتظار پاسخ کارشناس',
        'in_progress' => 'در حال بررسی',
        'answered' => 'پاسخ داده‌شده',
        'closed' => 'بسته‌شده',
    ],
    'user_role' => [
        'admin' => 'راهبر ارشد',
        'agent' => 'کارشناس پشتیبانی',
        'user' => 'کاربر سازمانی',
    ],
    'user_status' => [
        'active' => 'فعال',
        'suspended' => 'تعلیق‌شده',
        'inactive' => 'غیرفعال',
    ],
    'wallet_transaction_type' => [
        'DEPOSIT' => 'شارژ حساب (واریز)',
        'NUMBER_PURCHASE' => 'خرید شماره مجازی',
        'HOLD' => 'مسدودی وجه (درخواست سرشماره سازمانی)',
        'HOLD_RELEASE' => 'آزادسازی وجه مسدودی (رد/لغو درخواست)',
        'ADMIN_CREDIT' => 'افزایش اعتبار توسط مدیر',
        'ADMIN_DEBIT' => 'کسر اعتبار توسط مدیر',
        'REFUND' => 'بازگشت وجه (استرداد)',
        'REVERSAL' => 'تراکنش اصلاحی (معکوس)',
    ],
];