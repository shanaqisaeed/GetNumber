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