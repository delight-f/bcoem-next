@props([
    'name',
    'id' => null,
    'value' => '',
    'rows' => 12,
    'help' => null,
    'placeholder' => '',
])

@php($fieldId = $id ?? $name)

{{--
    Free-text area with a formatting toolbar.

    These fields are stored as Markdown and rendered on the public side by
    ContestRules::renderText() (Str::markdown). A plain textarea gave the
    organizer no way to produce that formatting — the help text could only
    ask them to leave blank lines between paragraphs. The toolbar inserts
    the Markdown the renderer already understands, so storage and the public
    output are unchanged: this is an editor for the existing format, not a
    new one. Formatting stays inert HTML-wise, since renderText() strips raw
    HTML in its Markdown branch.
--}}
<div class="bcoem-md-editor">
    <div class="btn-toolbar mb-1" role="toolbar" aria-label="Text formatting">
        <div class="btn-group btn-group-sm me-1" role="group">
            <button type="button" class="btn btn-outline-secondary" data-md-action="bold" data-md-target="{{ $fieldId }}" title="Bold"><span class="fa fa-bold"></span><span class="visually-hidden">Bold</span></button>
            <button type="button" class="btn btn-outline-secondary" data-md-action="italic" data-md-target="{{ $fieldId }}" title="Italic"><span class="fa fa-italic"></span><span class="visually-hidden">Italic</span></button>
        </div>
        <div class="btn-group btn-group-sm me-1" role="group">
            <button type="button" class="btn btn-outline-secondary" data-md-action="heading" data-md-target="{{ $fieldId }}" title="Heading">H</button>
            <button type="button" class="btn btn-outline-secondary" data-md-action="quote" data-md-target="{{ $fieldId }}" title="Quote"><span class="fa fa-quote-left"></span><span class="visually-hidden">Quote</span></button>
        </div>
        <div class="btn-group btn-group-sm me-1" role="group">
            <button type="button" class="btn btn-outline-secondary" data-md-action="ul" data-md-target="{{ $fieldId }}" title="Bulleted list"><span class="fa fa-list-ul"></span><span class="visually-hidden">Bulleted list</span></button>
            <button type="button" class="btn btn-outline-secondary" data-md-action="ol" data-md-target="{{ $fieldId }}" title="Numbered list"><span class="fa fa-list-ol"></span><span class="visually-hidden">Numbered list</span></button>
        </div>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-md-action="link" data-md-target="{{ $fieldId }}" title="Link"><span class="fa fa-link"></span><span class="visually-hidden">Link</span></button>
        </div>
    </div>
    <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="{{ $rows }}"
              class="form-control" placeholder="{{ $placeholder }}">{{ $value }}</textarea>
    @if ($help)
        <span class="form-text">{{ $help }}</span>
    @endif
</div>

@once
    <script>
        // Delegated so every editor on the page (and any added later) works
        // from one listener. Insertions are plain Markdown that
        // ContestRules::renderText() already renders.
        (function () {
            'use strict';

            function stripMarker(line, marker) {
                var re = new RegExp('^' + marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
                return line.replace(re, '');
            }

            function surround(ta, before, after, fallback) {
                var start = ta.selectionStart;
                var end = ta.selectionEnd;
                var selected = ta.value.slice(start, end) || fallback;
                ta.setRangeText(before + selected + after, start, end, 'select');
                ta.focus();
            }

            function prefixLines(ta, marker, numbered) {
                var start = ta.selectionStart;
                var end = ta.selectionEnd;

                // No selection: format the line the caret sits on.
                if (start === end) {
                    start = ta.value.lastIndexOf('\n', start - 1) + 1;
                    var nl = ta.value.indexOf('\n', end);
                    end = nl === -1 ? ta.value.length : nl;
                }

                var lines = ta.value.slice(start, end).split('\n');
                var out = lines.map(function (line, i) {
                    // Don't double up a marker the line already carries.
                    var body = stripMarker(line, numbered ? /^\d+\. /.source : marker);
                    return (numbered ? (i + 1) + '. ' : marker) + body;
                }).join('\n');

                ta.setRangeText(out, start, end, 'select');
                ta.focus();
            }

            document.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-md-action]');
                if (!btn) {
                    return;
                }

                var ta = document.getElementById(btn.getAttribute('data-md-target'));
                if (!ta) {
                    return;
                }

                event.preventDefault();

                switch (btn.getAttribute('data-md-action')) {
                    case 'bold':
                        surround(ta, '**', '**', 'bold text');
                        break;
                    case 'italic':
                        surround(ta, '*', '*', 'italic text');
                        break;
                    case 'heading':
                        prefixLines(ta, '### ');
                        break;
                    case 'quote':
                        prefixLines(ta, '> ');
                        break;
                    case 'ul':
                        prefixLines(ta, '- ');
                        break;
                    case 'ol':
                        prefixLines(ta, '', true);
                        break;
                    case 'link':
                        surround(ta, '[', '](https://)', 'link text');
                        break;
                }
            });
        })();
    </script>
@endonce
