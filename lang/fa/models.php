<?php

return [
    'audit_log' => [
        'actions' => [
            'user_created' => 'ایجاد حساب کاربری',
            'user_updated' => 'ویرایش حساب کاربری',
            'user_status_changed' => 'تغییر وضعیت کاربر',
            'user_role_changed' => 'تغییر نقش کاربری',
            'number_created' => 'تولید/ثبت خط جدید',
            'number_assigned' => 'تخصیص خط به کاربر',
            'number_released' => 'آزادسازی خط',
            'number_status_changed' => 'تغییر وضعیت خط',
            'request_submitted' => 'ثبت درخواست سرشماره',
            'request_approved' => 'تأیید درخواست سرشماره',
            'request_rejected' => 'رد درخواست سرشماره',
            'token_created' => 'صدور کلید وب‌سرویس',
            'token_rotated' => 'تعویض کلید وب‌سرویس',
            'token_revoked' => 'ابطال کلید وب‌سرویس',
            'message_deleted' => 'حذف پیام از سامانه',
            'message_sent' => 'ارسال پیام جدید',
            'number_updated' => 'ویرایش خط',
            'number_deleted' => 'حذف خط',
            'ticket_created' => 'ثبت تیکت پشتیبانی جدید',
            'ticket_replied' => 'ثبت پاسخ در تیکت',
            'ticket_status_changed' => 'تغییر وضعیت تیکت',
            'ticket_assigned' => 'ارجاع تیکت به کارشناس',
            'wallet_charged_usage' => 'کسر هزینه مصرف سرویس از کیف پول',
            'order_approved' => 'تأیید سفارش سرویس',
            'order_rejected' => 'رد سفارش سرویس',
            'order_referred' => 'ارجاع سفارش سرویس جهت بررسی',
            'prefix_tariffs_updated' => 'بروزرسانی تعرفه‌های پیش‌شماره',
            'global_tariffs_updated' => 'بروزرسانی تعرفه‌های سراسری',
            'service_purchasability_toggled' => 'تغییر وضعیت قابلیت خرید سرویس',
            'service_activation_toggled' => 'تغییر وضعیت فعال‌سازی سرویس',
            'service_approval_mode_updated' => 'بروزرسانی سیاست تأیید سرویس',
            'id_pricing_updated' => 'بروزرسانی تعرفه‌های آیدی اختصاصی',
        ],
    ],
    'phone_number' => [
        'rond_tiers' => [
            'golden_rond' => 'رند طلایی',
            'semi_rond' => 'نیمه‌رند',
            'regular' => 'عادی',
        ],
        'inbound_rules' => [
            'not_allocated' => 'خط مقصد در وضعیت «:status» بوده و آماده دریافت پیامک نمی‌باشد.',
            'incoming_disabled' => 'این سرشماره برای عدم دریافت پیامک ورودی (صرفاً یک‌طرفه/اطلاع‌رسانی) پیکربندی شده است.',
            'platform_service_forbidden' => 'ارسال پیامک به این سرشماره اختصاصی سامانه توسط کاربران عادی مجاز نمی‌باشد.',
        ],
    ],
    'phone_prefix' => [
        'default_name' => 'پیش‌شماره سازمانی پیش‌فرض',
    ],
    'ticket_attachment' => [
        'units' => [
            'mb' => ':size مگابایت',
            'kb' => ':size کیلوبایت',
            'bytes' => ':size بایت',
        ],
    ],
    'user_identity' => [
        'providers' => [
            'username' => 'شناسه کاربری و گذرواژه',
            'email' => 'پست الکترونیک (ایمیل)',
            'ldap' => 'سامانه سازمانی LDAP / اکتیو دایرکتوری',
            'keycloak' => 'ورود یکپارچه Keycloak SSO',
        ],
    ],
];