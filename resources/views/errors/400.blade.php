@extends('errors.layout')

@section('code', '400')
@section('title', 'درخواست نامعتبر')
@section('message', 'ساختار یا پارامترهای درخواست ارسالی به سرور نامعتبر بوده و قابل پردازش نمی‌باشد.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <line x1="12" y1="8" x2="12" y2="12"/>
    <line x1="12" y1="16" x2="12.01" y2="16"/>
</svg>
@endsection

