{{--
    Shown on every page when the plugin tables are not there yet.

    A full page, like the ones it stands in for, since the controllers render it
    directly from their own routes. The panel itself lives in
    partials/missing-panel.blade.php so the plugin's Page hook can render the
    same warning inside plugins.settings, which supplies the layout itself.
--}}
@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.missing_tables.title'))

@section('content')
    @include('ipam::partials.missing-panel')
@endsection