@extends('errors.layout')

@section('code', '404')
@section('title', 'صفحه مورد نظر یافت نشد')
@section('message', 'آدرس واردشده در سامانه موجود نبوده، حذف شده و یا ممکن است به نشانی دیگری منتقل شده باشد.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <path d="m15 9-6 6"/>
    <path d="m9 9 6 6"/>
</svg>
@endsection

