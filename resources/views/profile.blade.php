@extends('layouts.app')
@section('title', trim($profile->first_name.' '.$profile->last_name).' · gp-cami dashboard')

@section('content')
    <p style="margin-bottom:16px;"><a href="{{ route('dashboard') }}">← Back to search</a></p>
    <div class="results-detail">
        @include('partials.profile-detail', ['profile' => $profile])
    </div>
@endsection
