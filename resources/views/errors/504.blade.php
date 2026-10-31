@extends('errors.layout')

@section('code', '504')
@section('title', 'مهلت دروازه ارتباطی به پایان رسید')
@section('message', 'سرور در زمان مقرر پاسخی از دروازه‌های ارتباطی یا زیرساخت بالادستی دریافت ننمود.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <polyline points="12 6 12 12 16 14"/>
</svg>
@endsection

