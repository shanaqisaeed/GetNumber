@extends('errors.layout')

@section('code', '405')
@section('title', 'متد درخواست غیرمجاز است')
@section('message', 'روش فراخوانی این آدرس در پروتکل HTTP توسط سرور برای این مسیر پشتیبانی نمی‌شود.')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
</svg>
@endsection

