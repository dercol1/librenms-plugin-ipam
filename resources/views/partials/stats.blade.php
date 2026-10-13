{{--
    The row of headline numbers at the top of a page.

    $stats is a list of ['label' => ..., 'value' => ..., 'tone' => 'default|primary|success|warning|danger|info'].
    The tone becomes a bootstrap label, so the same numbers read the same way they
    do on a device page: red for a problem, green for fine, grey for a fact.

    A tile with no value is skipped rather than shown as zero: "0 collisions"
    and "nothing has been checked yet" are different statements, and showing the
    first when the second is true is how a report loses trust.
--}}
@php
    $stats = array_filter($stats ?? [], fn (array $stat): bool => ($stat['value'] ?? null) !== null);
@endphp

@if ($stats !== [])
    <div class="row tw:mb-4">
        @foreach ($stats as $stat)
            <div class="col-sm-6 col-md-{{ $stat['width'] ?? 3 }} tw:mb-3">
                <div class="panel panel-default tw:h-full">
                    <div class="panel-body tw:flex tw:items-center tw:justify-between tw:gap-3">
                        <div class="tw:min-w-0">
                            <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500 tw:dark:text-gray-400">
                                {{ $stat['label'] }}
                            </div>
                            <div class="tw:text-2xl tw:font-semibold tw:text-gray-900 tw:dark:text-gray-100">
                                {{ number_format((int) $stat['value']) }}
                            </div>
                            @isset($stat['hint'])
                                <div class="tw:text-xs tw:text-gray-500 tw:dark:text-gray-400">{{ $stat['hint'] }}</div>
                            @endisset
                        </div>
                        @isset($stat['tone'])
                            <span class="label label-{{ $stat['tone'] }}">{{ $stat['tone_text'] ?? '' }}</span>
                        @endisset
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif