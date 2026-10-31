@extends('errors.layout')

@section('code', '422')
@section('title', 'داده‌های ارسالی نامعتبر هستند')
@section('message', 'اطلاعات ارسال‌شده با استانداردهای اعتبارسنجی سرور مطابقت نداشته و قابل پردازش نمی‌باشد.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
    <polyline points="14 2 14 8 20 8"/>
    <line x1="9" y1="15" x2="15" y2="15"/>
</svg>
@endsection

