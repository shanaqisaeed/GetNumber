@extends('errors.layout')

@section('code', '408')
@section('title', __('views.errors.408.title'))
@section('message', __('views.errors.408.message'))

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <polyline points="12 6 12 12 16 14"/>
</svg>
@endsection

