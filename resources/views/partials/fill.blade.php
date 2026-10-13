{{--
    Fills the collapsed edit forms with the shared option lists.

    A select carrying data-fill starts out empty except for its placeholder, so
    a page with fifty rows does not carry fifty copies of a four hundred entry
    vlan list. When a row is expanded, the matching <template> from
    partials/options is cloned into the select and data-selected marks the
    current value.

    Core ships jQuery and Bootstrap's collapse, and both events are used here:
    show.bs.collapse is the moment the row is about to become visible, which is
    the last point at which filling the select is still invisible to the user.
--}}
@push('scripts')
    <script>
        $(function () {
            $(document).on('show.bs.collapse', '.collapse', function () {
                $(this).find('select[data-fill]').each(function () {
                    var select = $(this);

                    if (select.data('filled')) {
                        return; // already has its options, the row was collapsed and reopened
                    }

                    var template = document.getElementById(select.data('fill'));

                    if (template) {
                        select.append(template.content.cloneNode(true));
                    }

                    var current = select.data('selected');

                    if (current !== undefined && current !== null) {
                        select.val(String(current));
                    }

                    select.data('filled', true);
                });
            });
        });
    </script>
@endpush