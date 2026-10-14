{{--
    What the last action answered, and what validation refused.

    The plugin's controllers had been sending flash messages for nineteen
    different outcomes across five files, and no page rendered a single one of
    them: an operator who set a value and got "the form was refused" saw a
    page exactly like a page that had worked. A validation failure in
    particular redirects back with an errors bag and nothing else, which is
    how "saved nothing and said nothing" looked.

    Rendered from the session when the request comes after a redirect, and
    from the errors bag the framework fills when validation refused. Both are
    checked defensively because this partial also renders from contexts with
    no session (a controller-rendered view in a test, the plugin page hook).
--}}
@php
    $success = session('success');
    $error = session('error');
    $failed = isset($errors) && $errors->any();
@endphp

@if ($success)
    <div class="alert alert-success tw:flex tw:items-start tw:gap-2">
        <i class="fa fa-check-circle tw:mt-0.5" aria-hidden="true"></i>
        <div>{{ $success }}</div>
    </div>
@endif

@if ($error)
    <div class="alert alert-danger tw:flex tw:items-start tw:gap-2">
        <i class="fa fa-exclamation-circle tw:mt-0.5" aria-hidden="true"></i>
        <div>{{ $error }}</div>
    </div>
@endif

@if ($failed)
    <div class="alert alert-danger">
        <strong><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> {{ __('ipam::ipam.notices.validation_failed') }}</strong>
        <ul class="tw:mb-0 tw:mt-1">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif