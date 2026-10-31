@extends('errors.layout')

@section('code', '502')
@section('title', 'خطای دروازه ارتباطی')
@section('message', 'سرور در حین ارتباط با سرویس‌های بالادستی پاسخ نامعتبری دریافت کرده است.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <rect width="20" height="8" x="2" y="2" rx="2" ry="2"/>
    <rect width="20" height="8" x="2" y="14" rx="2" ry="2"/>
    <line x1="6" y1="6" x2="6.01" y2="6"/>
    <line x1="6" y1="18" x2="6.01" y2="18"/>
</svg>
@endsection

