@extends('errors.layout')

@section('code', '403')
@section('title', 'دسترسی غیرمجاز')
@section('message', 'شما مجوزها یا سطح دسترسی لازم برای مشاهده این بخش یا انجام این اقدام را دارا نمی‌باشید.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    <line x1="12" y1="8" x2="12" y2="12"/>
    <line x1="12" y1="16" x2="12.01" y2="16"/>
</svg>
@endsection

