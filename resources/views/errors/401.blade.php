@extends('errors.layout')

@section('code', '401')
@section('title', 'نیاز به احراز هویت')
@section('message', 'برای دسترسی به این بخش یا منبع اطلاعاتی، لطفاً ابتدا وارد حساب کاربری خود شوید.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
</svg>
@endsection

@section('actions')
<a href="{{ route('login') }}" class="btn btn-secondary">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
        <polyline points="10 17 15 12 10 7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
    </svg>
    ورود به حساب کاربری
</a>
@endsection

