@extends('errors.layout')

@section('code', '429')
@section('title', 'تعداد درخواست‌ها بیش از حد مجاز')
@section('message', 'تعداد درخواست‌های ارسالی شما از سقف نرخ مجاز فراتر رفته است. لطفاً کمی صبر کرده و سپس اقدام فرمایید.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 2v4"/>
    <path d="M12 18v4"/>
    <path d="m4.93 4.93 2.83 2.83"/>
    <path d="m16.24 16.24 2.83 2.83"/>
    <path d="M2 12h4"/>
    <path d="M18 12h4"/>
    <path d="m4.93 19.07 2.83-2.83"/>
    <path d="m16.24 7.76 2.83-2.83"/>
</svg>
@endsection

