@extends('layouts.app')

@section('content')
<style>
/* Per-book header colours (cycles for 2+ books) */
.book-col-0 { background: #dbeafe; color: #1e3a5f; } /* blue  */
.book-col-1 { background: #d1fae5; color: #064e3b; } /* green */
.book-col-2 { background: #fef9c3; color: #713f12; } /* yellow */
.book-col-3 { background: #fce7f3; color: #831843; } /* pink  */

/* Row striping — applied manually so section headers don't break it */
tr.row-even > td { background-color: #f8f9fa; }
tr.row-odd  > td { background-color: #ffffff; }

/* Override striping for section-header rows */
tr.section-header > td { background-color: #e9ecef !important; }

/* Keep selected (primary) highlight visible over striping */
td.table-primary { background-color: #cfe2ff !important; }

/* Book identity header row — neutral dark, separate from column colours */
th.book-identity { background: #343a40; color: #f8f9fa; }

/* Split directory input — make it visually distinct on coloured background */
.split-dir-input {
    background: #fff;
    border: 2px solid #495057 !important;
    box-shadow: 0 0 0 1px rgba(0,0,0,.15);
    font-weight: 600;
}
.split-dir-input:focus {
    border-color: #0d6efd !important;
    box-shadow: 0 0 0 3px rgba(13,110,253,.25) !important;
}

/* Inline edit button — only visible when hovering the specific cell */
.btn-edit-field { opacity: 0; transition: opacity .15s; }
td:hover > .cell-value-wrap .btn-edit-field,
td:hover > div .btn-edit-field { opacity: 1; }

/* Inline edit input inside a cell */
.inline-edit-input { font-size: inherit; padding: 1px 4px; }
</style>

<div class="container-fluid">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-3 gap-3">
        <div>
            <h1 class="h3 mb-1">Resolve Duplicate Directory</h1>
            <p class="text-muted mb-0">
                <strong>Shared path:</strong> <code>{{ $issue['directoryPath'] ?? '—' }}</code>
            </p>
        </div>
        <a href="{{ route('admin.library-repair.index') }}" class="btn btn-outline-secondary btn-sm">← Back</a>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $staticFields    = ['duration', 'audio_file_count', 'created_at', 'updated_at'];
        $relationFields  = ['authors', 'narrators', 'genres'];
        $mergeableFields = array_keys(array_filter(
            $compareFields,
            fn($label, $field) => !in_array($field, $staticFields, true),
            ARRAY_FILTER_USE_BOTH
        ));
        $firstBookId     = $books->first()?->id;
        $allFiles        = collect($fileGroups)->flatten(1)->all();
        $hasFiles        = count($allFiles) > 0;
        $audioExts       = ['mp3', 'm4b', 'm4a', 'flac', 'ogg', 'opus', 'aac', 'wav'];
        $imageExts       = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $directoryPath   = trim($issue['directoryPath'] ?? '', '/');
        $parentDir       = dirname($directoryPath);
        $baseName        = basename($directoryPath);
        $bookCount       = $books->count();
        $labelPct        = 14;
        $bookPct         = (int) floor((100 - $labelPct) / max(1, $bookCount));
        $coverValues     = $books->map(fn($b) => $b->cover_image ?? '')->all();
        $coversdiffer    = count(array_unique($coverValues)) > 1;
        $bookRelationData = $books->mapWithKeys(function ($book): array {
            return [
                $book->id => [
                    'authors' => $book->authors->pluck('name')->values()->all(),
                    'narrators' => $book->narrators->pluck('name')->values()->all(),
                    'genres' => $book->genres->pluck('name')->values()->all(),
                ],
            ];
        })->all();
        // colour classes cycling per book index
        $headerClasses   = ['book-col-0','book-col-1','book-col-2','book-col-3'];
    @endphp

    {{-- Mode toggle --}}
    <div class="mb-3 d-flex gap-2">
        <button class="btn btn-danger btn-sm" id="btn-mode-merge" onclick="setMode('merge')">
            Merge
        </button>
        <button class="btn btn-outline-warning btn-sm" id="btn-mode-split" onclick="setMode('split')">
            Split
        </button>
    </div>

    {{-- ══════════════════════════════════════════════════════
         MERGE FORM
    ══════════════════════════════════════════════════════ --}}
    <div id="merge-panel">
        <form method="POST" action="{{ route('admin.library-repair.resolve-duplicate', $issue['id']) }}">
            @csrf
            <input type="hidden" name="keep_book_id" id="keep_book_id" value="{{ $firstBookId }}">
            @foreach($mergeableFields as $field)
                <input type="hidden" name="field_sources[{{ $field }}]" id="field_source_{{ $field }}" value="{{ $firstBookId }}">
            @endforeach
            <input type="hidden" name="field_sources[cover_image]" id="field_source_cover_image" value="{{ $firstBookId }}">

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0" id="compare-table" style="table-layout:fixed;width:100%;">
                    <colgroup>
                        <col style="width:{{ $labelPct }}%;">
                        @foreach($books as $book)<col style="width:{{ $bookPct }}%;">@endforeach
                    </colgroup>
                    <thead class="sticky-top">
                        {{-- Row 1: book identity (neutral dark, full width) --}}
                        <tr>
                            <th class="book-identity"></th>
                            @foreach($books as $bi => $book)
                                <th class="book-identity text-center">
                                    <div class="fw-semibold">
                                        Book #{{ $book->id }}
                                        @if($book->trashed())<span class="badge text-bg-danger ms-1">Trashed</span>@endif
                                        <a href="{{ route('admin.books.edit', $book->id) }}" target="_blank"
                                           class="ms-2 small link-light link-offset-1" onclick="event.stopPropagation()">Edit &rarr;</a>
                                    </div>
                                    <div class="small text-truncate opacity-75">{{ $book->title ?: '(no title)' }}</div>
                                </th>
                            @endforeach
                        </tr>
                        {{-- Row 2: select-book action (coloured) --}}
                        <tr>
                            <th class="table-light small text-muted fw-semibold text-uppercase align-middle" style="font-size:.7rem;">Select all</th>
                            @foreach($books as $bi => $book)
                                <th class="{{ $headerClasses[$bi % 4] }} text-center book-col-header py-2"
                                    data-book-id="{{ $book->id }}"
                                    style="cursor:pointer;"
                                    onclick="selectBook({{ $book->id }})"
                                    title="Click to use all fields from this book">
                                    <span class="small fw-semibold">↑ Use all from this book</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Cover --}}
                        <tr class="row-odd">
                            <td class="fw-semibold text-muted small text-uppercase table-light">Cover</td>
                            @foreach($books as $index => $book)
                                <td id="cell_cover_image_{{ $book->id }}"
                                    class="{{ $coversdiffer ? 'book-cell' : 'table-secondary' }}"
                                    @if($coversdiffer) style="cursor:pointer;" data-book-id="{{ $book->id }}" data-field="cover_image" onclick="selectCover({{ $book->id }})" @endif>
                                    @php $url = $coverUrls[$index] ?? asset('images/placeholder.png'); @endphp
                                    <img src="{{ $url }}" alt="Cover" style="max-height:120px;max-width:100%;display:block;margin:0 auto;">
                                </td>
                            @endforeach
                        </tr>

                        {{-- Metadata fields --}}
                        @foreach($compareFields as $field => $label)
                            @php
                                $isStatic = in_array($field, $staticFields, true);
                                $differs  = $hasDiff[$field] ?? false;
                                $rowClass = $loop->even ? 'row-even' : 'row-odd';
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="fw-semibold text-muted small text-uppercase table-light">
                                    {{ $label }}
                                    @if($field === 'duration' || $field === 'audio_file_count')
                                        <div class="fw-normal" style="font-size:.7em;">(follows files)</div>
                                    @endif
                                </td>
                                @foreach($books as $index => $book)
                                    @php $val = $fieldValues[$field][$index] ?? ''; @endphp
                                    <td @if(!$isStatic && $differs)
                                            class="book-cell" style="cursor:pointer;"
                                            data-book-id="{{ $book->id }}" data-field="{{ $field }}"
                                            id="cell_{{ $field }}_{{ $book->id }}"
                                            onclick="selectField('{{ $field }}', {{ $book->id }})"
                                        @else
                                            class="table-secondary text-muted"
                                        @endif>
                                        <div class="d-flex align-items-start gap-1 cell-value-wrap">
                                            <span class="cell-display flex-grow-1" data-book-id="{{ $book->id }}" data-field="{{ $field }}">
                                                @if($field === 'description' && $val !== '')
                                                    <span class="small" style="max-height:80px;overflow-y:auto;display:block;">{{ Str::limit($val, 200) }}</span>
                                                @elseif($val !== '')
                                                    {{ $val }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </span>
                                            @if(!$isStatic)
                                                @if(in_array($field, $relationFields))
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 btn-edit-field"
                                                        onclick="event.stopPropagation(); openRelationsModal({{ $book->id }}, {{ json_encode($field) }}, {{ json_encode($label) }})"
                                                        title="Edit {{ $label }}">✎</button>
                                                @else
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 btn-edit-field"
                                                        onclick="event.stopPropagation(); startInlineEdit(this, {{ $book->id }}, {{ json_encode($field) }}, {{ json_encode((string)$val) }})"
                                                        title="Edit {{ $label }}">✎</button>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        {{-- Files section header --}}
                        @if($hasFiles)
                            <tr class="section-header">
                                <td colspan="{{ $bookCount + 1 }}" class="fw-semibold small text-uppercase py-1">
                                    Files in shared directory
                                    <span class="text-muted fw-normal">(checked = keep, unchecked = move to trash)</span>
                                </td>
                            </tr>
                            @php $fileRowIndex = 0; @endphp
                            @foreach(['audio' => 'Audio', 'image' => 'Images', 'other' => 'Other'] as $groupKey => $groupLabel)
                                @php
                                    $files = $fileGroups[$groupKey] ?? [];
                                    $mergeAudioExts = [];
                                    if ($groupKey === 'audio') {
                                        $mergeAudioExts = array_values(array_unique(
                                            array_map(fn($f) => strtolower($f['extension'] ?? ''), $files)
                                        ));
                                    }
                                @endphp
                                @if(count($files) > 0)
                                    <tr class="section-header">
                                        <td class="small text-muted fw-semibold ps-3">{{ $groupLabel }}</td>
                                        <td colspan="{{ $bookCount }}" class="py-1">
                                            <label class="small text-muted me-3" style="cursor:pointer;">
                                                <input class="form-check-input group-select-all me-1" type="checkbox"
                                                    data-group="{{ $groupKey }}" id="select_all_{{ $groupKey }}" checked>
                                                select all / none
                                            </label>
                                            @foreach($mergeAudioExts as $maext)
                                                <label class="small text-muted me-3" style="cursor:pointer;">
                                                    <input class="form-check-input ext-select-all me-1" type="checkbox"
                                                        data-ext="{{ $maext }}" id="select_all_ext_{{ $maext }}" checked>
                                                    .{{ $maext }}
                                                </label>
                                            @endforeach
                                        </td>
                                    </tr>
                                    @foreach($files as $file)
                                        @php
                                            $ext     = strtolower($file['extension'] ?? '');
                                            $isAudio = in_array($ext, $audioExts);
                                            $isImage = in_array($ext, $imageExts);
                                            $frel    = $directoryPath . '/' . $file['name'];
                                            $rowCls  = ($fileRowIndex % 2 === 0) ? 'row-even' : 'row-odd';
                                            $fileRowIndex++;
                                        @endphp
                                        <tr class="{{ $rowCls }}">
                                            <td colspan="{{ $bookCount + 1 }}" class="ps-3">
                                                <div class="d-flex align-items-center gap-2">
                                                    <input class="form-check-input file-checkbox flex-shrink-0" type="checkbox"
                                                        name="keep_files[]" value="{{ $file['name'] }}"
                                                        data-group="{{ $groupKey }}" data-ext="{{ $ext }}" checked>
                                                    <span class="badge text-bg-light border flex-shrink-0">{{ $ext }}</span>
                                                    <span class="text-break me-1">{{ $file['name'] }}
                                                        <span class="text-muted ms-1">{{ number_format(($file['size'] ?? 0) / 1048576, 1) }} MB</span>
                                                    </span>
                                                    @if($isAudio)
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1 flex-shrink-0"
                                                            onclick="openAudioPlayer({{ json_encode($file['name']) }}, {{ json_encode(route('cover.proxy', ['path' => $frel])) }})">▶</button>
                                                    @endif
                                                    @if($isImage)
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0"
                                                            onclick="openImageViewer({{ json_encode($file['name']) }}, {{ json_encode(route('cover.proxy', ['path' => $frel])) }})">🖼</button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        @endif

                        {{-- Submit row --}}
                        <tr class="table-light">
                            <td></td>
                            @foreach($books as $book)
                                <td class="py-2" id="submit_cell_{{ $book->id }}">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-danger btn-sm" id="submit_{{ $book->id }}" style="display:none;">
                                            Merge
                                        </button>
                                        <span class="text-muted small" id="summary_{{ $book->id }}" style="display:none;"></span>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </form>
    </div>

    {{-- ══════════════════════════════════════════════════════
         SPLIT FORM — single unified table
    ══════════════════════════════════════════════════════ --}}
    <div id="split-panel" style="display:none;">
        <form method="POST" action="{{ route('admin.library-repair.split-duplicate', $issue['id']) }}" id="split-form">
            @csrf
            <div id="split-hidden-inputs"></div>

            @php
                // Split table has bookCount + 1 data cols: one per book + "Copy to both"
                $splitDataCols = $bookCount + 1;
                $copyPct       = 10;
                $splitBookPct  = (int) floor((100 - $labelPct - $copyPct) / max(1, $bookCount));
            @endphp
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0" style="table-layout:fixed;width:100%;">
                    <colgroup>
                        <col style="width:{{ $labelPct }}%;">
                        @foreach($books as $book)<col style="width:{{ $splitBookPct }}%;">@endforeach
                        <col style="width:{{ $copyPct }}%;">
                    </colgroup>

                    <thead class="sticky-top">
                        {{-- Row 1: book identity + "Copy to both" (neutral dark, full width) --}}
                        <tr>
                            <th class="book-identity"></th>
                            @foreach($books as $bi => $book)
                                <th class="book-identity text-center">
                                    <div class="fw-semibold">
                                        Book #{{ $book->id }}
                                        @if($book->trashed())<span class="badge text-bg-danger ms-1">Trashed</span>@endif
                                        <a href="{{ route('admin.books.edit', $book->id) }}" target="_blank"
                                           class="ms-2 small link-light link-offset-1">Edit &rarr;</a>
                                    </div>
                                    <div class="small text-truncate opacity-75">{{ $book->title ?: '(no title)' }}</div>
                                </th>
                            @endforeach
                            <th class="book-identity text-center align-middle small fw-semibold">Copy<br>to both</th>
                        </tr>
                        {{-- Row 2: directory inputs (plain light, not coloured) --}}
                        <tr>
                            <th class="table-light small text-muted fw-semibold text-uppercase align-middle" style="font-size:.7rem;">Directory</th>
                            @foreach($books as $bi => $book)
                                @php
                                    $suffix     = $bi === 0 ? '' : ' (' . ($bi + 1) . ')';
                                    $defaultDir = ($parentDir !== '.' ? $parentDir . '/' : '') . $baseName . $suffix;
                                @endphp
                                <th class="table-light align-middle">
                                    <input type="text"
                                        class="form-control form-control-sm split-dir-input"
                                        name="splits[{{ $bi }}][dir]"
                                        value="{{ $defaultDir }}"
                                        placeholder="Relative directory path"
                                        style="font-size:.75rem;">
                                    <input type="hidden" name="splits[{{ $bi }}][book_id]" value="{{ $book->id }}">
                                </th>
                            @endforeach
                            <th class="table-light"></th>
                        </tr>
                    </thead>

                    <tbody>
                        {{-- Cover — spans all data cols --}}
                        <tr class="row-odd">
                            <td class="fw-semibold text-muted small text-uppercase table-light">Cover</td>
                            @foreach($books as $index => $book)
                                <td class="table-secondary text-center">
                                    <img src="{{ $coverUrls[$index] ?? asset('images/placeholder.png') }}"
                                         alt="Cover" style="max-height:100px;max-width:100%;display:block;margin:0 auto;">
                                </td>
                            @endforeach
                            <td class="table-secondary"></td>
                        </tr>

                        {{-- Metadata fields (read-only reference) --}}
                        @foreach($compareFields as $field => $label)
                            @php
                                $differs  = $hasDiff[$field] ?? false;
                                $rowClass = $loop->even ? 'row-even' : 'row-odd';
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="fw-semibold text-muted small text-uppercase table-light">
                                    {{ $label }}
                                    @if($field === 'duration' || $field === 'audio_file_count')
                                        <div class="fw-normal" style="font-size:.7em;">(follows files)</div>
                                    @endif
                                </td>
                                @foreach($books as $index => $book)
                                    @php
                                        $val           = $fieldValues[$field][$index] ?? '';
                                        $isStaticSplit = in_array($field, $staticFields, true);
                                    @endphp
                                    <td class="{{ $differs ? '' : 'table-secondary text-muted' }}">
                                        <div class="d-flex align-items-start gap-1 cell-value-wrap">
                                            <span class="cell-display flex-grow-1" data-book-id="{{ $book->id }}" data-field="{{ $field }}">
                                                @if($field === 'description' && $val !== '')
                                                    <span class="small" style="max-height:60px;overflow-y:auto;display:block;">{{ Str::limit($val, 150) }}</span>
                                                @elseif($val !== '')
                                                    {{ $val }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </span>
                                            @if(!$isStaticSplit)
                                                @if(in_array($field, $relationFields))
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 btn-edit-field"
                                                        onclick="openRelationsModal({{ $book->id }}, {{ json_encode($field) }}, {{ json_encode($label) }})"
                                                        title="Edit {{ $label }}">✎</button>
                                                @else
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 btn-edit-field"
                                                        onclick="startInlineEdit(this, {{ $book->id }}, {{ json_encode($field) }}, {{ json_encode((string)$val) }})"
                                                        title="Edit {{ $label }}">✎</button>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                                <td class="table-secondary"></td>{{-- copy col unused for metadata --}}
                            </tr>
                        @endforeach

                        {{-- File assignment section --}}
                        @if($hasFiles)
                            {{-- File sub-header row with per-book colour --}}
                            <tr class="section-header">
                                <td class="fw-semibold small text-uppercase py-1 text-muted">Files</td>
                                @foreach($books as $bi => $book)
                                    <td class="{{ $headerClasses[$bi % 4] }} text-center fw-semibold small py-1">
                                        Book #{{ $book->id }} only
                                    </td>
                                @endforeach
                                <td class="table-light text-center fw-semibold small py-1 text-muted">Copy</td>
                            </tr>

                            @php $fileRowIndex = 0; @endphp
                            @foreach(['audio' => 'Audio', 'image' => 'Images', 'other' => 'Other'] as $groupKey => $groupLabel)
                                @php $gfiles = $fileGroups[$groupKey] ?? []; @endphp
                                @if(count($gfiles) > 0)
                                    @php
                                        $audioExtTypes = [];
                                        if ($groupKey === 'audio') {
                                            $audioExtTypes = array_values(array_unique(
                                                array_map(fn($f) => strtolower($f['extension'] ?? ''), $gfiles)
                                            ));
                                        }
                                    @endphp
                                    <tr class="section-header">
                                        <td class="small text-muted fw-semibold ps-3">{{ $groupLabel }}</td>
                                        @if($groupKey === 'audio' && count($audioExtTypes) > 0)
                                            @foreach($books as $bi => $book)
                                                <td class="{{ $headerClasses[$bi % 4] }} small py-1 text-center">
                                                    @foreach($audioExtTypes as $aext)
                                                        <button type="button"
                                                            class="btn btn-outline-secondary btn-sm py-0 px-2 me-1"
                                                            style="font-size:.7rem;"
                                                            onclick="selectAllExt({{ json_encode($aext) }}, 'book_{{ $bi }}')">all .{{ $aext }} here</button>
                                                    @endforeach
                                                </td>
                                            @endforeach
                                            <td class="table-light small py-1 text-center">
                                                @foreach($audioExtTypes as $aext)
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-2 me-1"
                                                        style="font-size:.7rem;"
                                                        onclick="selectAllExt({{ json_encode($aext) }}, 'copy_all')">all .{{ $aext }}</button>
                                                @endforeach
                                            </td>
                                        @else
                                            <td colspan="{{ $splitDataCols }}"></td>
                                        @endif
                                    </tr>
                                    @foreach($gfiles as $file)
                                        @php
                                            $fname           = $file['name'];
                                            $ext             = strtolower($file['extension'] ?? '');
                                            $isAudio         = in_array($ext, $audioExts);
                                            $isImage         = in_array($ext, $imageExts);
                                            $frel            = $directoryPath . '/' . $fname;
                                            $isLibrarianJson = $fname === 'librarian.json';
                                            $rowCls          = ($fileRowIndex % 2 === 0) ? 'row-even' : 'row-odd';
                                            $fileRowIndex++;
                                        @endphp
                                        @if($isLibrarianJson)
                                        <tr class="{{ $rowCls }}" style="opacity:.45;">
                                            <td class="small ps-3 text-muted fst-italic" colspan="{{ $splitDataCols + 1 }}">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge text-bg-secondary border">json</span>
                                                    <span>librarian.json</span>
                                                    <span class="text-muted small">&mdash; deleted from source; regenerated automatically for each book</span>
                                                </div>
                                            </td>
                                        </tr>
                                        @else
                                        <tr class="{{ $rowCls }}">
                                            <td class="small ps-3">
                                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                                    <span class="badge text-bg-light border">{{ $ext }}</span>
                                                    <span class="text-break">{{ $fname }}</span>
                                                    <span class="text-muted text-nowrap">{{ number_format(($file['size'] ?? 0) / 1048576, 1) }} MB</span>
                                                    @if($isAudio)
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1"
                                                            onclick="openAudioPlayer({{ json_encode($fname) }}, {{ json_encode(route('cover.proxy', ['path' => $frel])) }})">▶</button>
                                                    @endif
                                                    @if($isImage)
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                                            onclick="openImageViewer({{ json_encode($fname) }}, {{ json_encode(route('cover.proxy', ['path' => $frel])) }})">🖼</button>
                                                    @endif
                                                </div>
                                            </td>
                                            @foreach($books as $bi => $book)
                                                <td class="text-center align-middle">
                                                    <input type="radio" class="form-check-input split-file-radio"
                                                        name="file_assign[{{ $fname }}]"
                                                        value="book_{{ $bi }}"
                                                        data-filename="{{ $fname }}"
                                                        data-ext="{{ $ext }}"
                                                        {{ $bi === 0 ? 'checked' : '' }}>
                                                </td>
                                            @endforeach
                                            <td class="text-center align-middle">
                                                <input type="radio" class="form-check-input split-file-radio"
                                                    name="file_assign[{{ $fname }}]"
                                                    value="copy_all"
                                                    data-filename="{{ $fname }}"
                                                    data-ext="{{ $ext }}">
                                            </td>
                                        </tr>
                                        @endif
                                    @endforeach
                                @endif
                            @endforeach
                        @else
                            <tr><td colspan="{{ $splitDataCols + 1 }}" class="text-muted small py-2 ps-3">No files found in the shared directory.</td></tr>
                        @endif

                        {{-- Submit row --}}
                        <tr class="table-light">
                            <td></td>
                            <td colspan="{{ $splitDataCols }}" class="py-2">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <button type="submit" class="btn btn-warning btn-sm">✂️ Split &amp; Resolve</button>
                                    <span class="text-muted small">Files are moved/copied and each book gets its own directory.</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </form>
    </div>

</div>{{-- /container-fluid --}}

{{-- Relations edit modal (authors / narrators / genres) --}}
<div class="modal fade" id="relationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="relationsModalTitle"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <div id="relations-rows"></div>
                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="relations-add-btn">+ Add</button>
                <div id="relations-error" class="text-danger small mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="relations-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

{{-- Audio player modal --}}
<div class="modal fade" id="audioModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="audioModalTitle"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-3">
                <audio id="audioPlayer" controls style="width:100%;" preload="metadata">Your browser does not support audio.</audio>
            </div>
        </div>
    </div>
</div>

{{-- Image viewer modal --}}
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="imageModalTitle"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="imageModalImg" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<script>
// ── Mode toggle ───────────────────────────────────────────
function setMode(mode) {
    const isMerge = mode === 'merge';
    document.getElementById('merge-panel').style.display = isMerge ? '' : 'none';
    document.getElementById('split-panel').style.display = isMerge ? 'none' : '';
    document.getElementById('btn-mode-merge').className  = isMerge ? 'btn btn-danger btn-sm' : 'btn btn-outline-danger btn-sm';
    document.getElementById('btn-mode-split').className  = isMerge ? 'btn btn-outline-warning btn-sm' : 'btn btn-warning btn-sm';
}

// ── Merge form JS ─────────────────────────────────────────
(function () {
    const mergeableFields = @json($mergeableFields);
    const bookIds         = @json($books->pluck('id')->all());
    const coversdiffer    = {{ $coversdiffer ? 'true' : 'false' }};
    const selectedSource  = {};
    let keeperId = bookIds[0];

    mergeableFields.forEach(f => { selectedSource[f] = keeperId; });
    selectedSource['cover_image'] = keeperId;

    function updateHiddenInputs() {
        document.getElementById('keep_book_id').value = keeperId;
        mergeableFields.forEach(f => {
            const el = document.getElementById('field_source_' + f);
            if (el) el.value = selectedSource[f];
        });
    }

    function updateHighlights() {
        bookIds.forEach(id => {
            const h = document.querySelector('.book-col-header[data-book-id="' + id + '"]');
            if (h) h.classList.toggle('table-primary', id === keeperId);
        });

        const counts = {};
        bookIds.forEach(id => { counts[id] = 0; });
        mergeableFields.forEach(f => { if (counts[selectedSource[f]] !== undefined) counts[selectedSource[f]]++; });

        bookIds.forEach(id => {
            const btn     = document.getElementById('submit_' + id);
            const summary = document.getElementById('summary_' + id);
            const isKeep  = id === keeperId;
            if (btn) btn.style.display = isKeep ? '' : 'none';
            if (summary) {
                summary.style.display = isKeep ? '' : 'none';
                if (isKeep) {
                    const parts = bookIds
                        .filter(bid => bid !== keeperId && counts[bid] > 0)
                        .map(bid => counts[bid] + ' field' + (counts[bid] === 1 ? '' : 's') + ' from Book #' + bid);
                    summary.textContent = parts.length ? 'Merging in: ' + parts.join(', ') : 'No fields merged from other book';
                }
            }
        });

        document.querySelectorAll('.book-cell').forEach(cell => {
            cell.classList.toggle('table-primary', selectedSource[cell.dataset.field] === parseInt(cell.dataset.bookId, 10));
        });

        if (coversdiffer) {
            bookIds.forEach(id => {
                const cell = document.getElementById('cell_cover_image_' + id);
                if (cell) cell.classList.toggle('table-primary', selectedSource['cover_image'] === id);
            });
        }
    }

    window.selectBook = function (bookId) {
        keeperId = bookId;
        mergeableFields.forEach(f => { selectedSource[f] = keeperId; });
        selectedSource['cover_image'] = keeperId;
        document.getElementById('field_source_cover_image').value = keeperId;
        updateHiddenInputs(); updateHighlights();
    };
    window.selectCover = function (bookId) {
        selectedSource['cover_image'] = bookId;
        document.getElementById('field_source_cover_image').value = bookId;
        updateHighlights();
    };
    window.selectField = function (field, bookId) {
        selectedSource[field] = bookId; updateHiddenInputs(); updateHighlights();
    };

    document.querySelectorAll('.group-select-all').forEach(master => {
        master.addEventListener('change', () => {
            document.querySelectorAll('.file-checkbox[data-group="' + master.dataset.group + '"]')
                .forEach(cb => { cb.checked = master.checked; });
        });
    });
    document.querySelectorAll('.file-checkbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const all    = document.querySelectorAll('.file-checkbox[data-group="' + cb.dataset.group + '"]');
            const master = document.getElementById('select_all_' + cb.dataset.group);
            if (master) master.checked = Array.from(all).every(c => c.checked);
        });
    });

    updateHiddenInputs(); updateHighlights();
})();

// ── Split form JS ─────────────────────────────────────────
(function () {
    const splitForm = document.getElementById('split-form');
    if (!splitForm) return;
    const bookCount = @json($books->count());

    splitForm.addEventListener('submit', () => {
        const container = document.getElementById('split-hidden-inputs');
        container.innerHTML = '';

        const assignments = {};
        splitForm.querySelectorAll('.split-file-radio:checked').forEach(r => { assignments[r.dataset.filename] = r.value; });

        const moveFiles = {}, copyFiles = {};
        for (let i = 0; i < bookCount; i++) { moveFiles[i] = []; copyFiles[i] = []; }

        Object.entries(assignments).forEach(([filename, value]) => {
            if (value === 'copy_all') {
                for (let i = 0; i < bookCount; i++) copyFiles[i].push(filename);
            } else if (value.startsWith('book_')) {
                const idx = parseInt(value.replace('book_', ''), 10);
                if (!isNaN(idx) && moveFiles[idx]) moveFiles[idx].push(filename);
            }
        });

        for (let i = 0; i < bookCount; i++) {
            moveFiles[i].forEach(f => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'splits[' + i + '][move_files][]'; inp.value = f;
                container.appendChild(inp);
            });
            copyFiles[i].forEach(f => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'splits[' + i + '][copy_files][]'; inp.value = f;
                container.appendChild(inp);
            });
        }
    });
})();

// ── Merge select-all-by-extension ─────────────────────────
document.querySelectorAll('.ext-select-all').forEach(master => {
    master.addEventListener('change', () => {
        document.querySelectorAll('.file-checkbox[data-ext="' + master.dataset.ext + '"]')
            .forEach(cb => {
                cb.checked = master.checked;
                cb.dispatchEvent(new Event('change'));
            });
    });
});

// ── Split select-all-by-extension ─────────────────────────
window.selectAllExt = function (ext, value) {
    document.querySelectorAll('.split-file-radio[data-ext="' + ext + '"][value="' + value + '"]')
        .forEach(r => { r.checked = true; });
};

// ── Inline field edit ─────────────────────────────────────
window.startInlineEdit = function (editBtn, bookId, field, currentValue) {
    const wrap    = editBtn.closest('.cell-value-wrap');
    const display = wrap.querySelector('.cell-display');
    if (wrap.querySelector('.inline-edit-input')) return; // already editing

    const isLong = field === 'description';
    const input  = isLong ? document.createElement('textarea') : document.createElement('input');
    input.className = 'form-control form-control-sm inline-edit-input flex-grow-1';
    input.value     = currentValue;
    if (isLong) input.rows = 3;

    display.style.display = 'none';
    editBtn.style.display = 'none';
    wrap.insertBefore(input, editBtn);
    input.focus();

    function commit() {
        const newVal = input.value.trim();
        display.textContent = newVal || '—';
        // Upsert hidden input in the nearest <form>
        const form = wrap.closest('form');
        if (form) {
            const hiddenName = 'book_edits[' + bookId + '][' + field + ']';
            let hidden = form.querySelector('input[name="' + hiddenName + '"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = hiddenName;
                form.appendChild(hidden);
            }
            hidden.value = newVal;
        }
        input.remove();
        display.style.display = '';
        editBtn.style.display = '';
    }

    input.addEventListener('blur', commit);
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            input.removeEventListener('blur', commit);
            input.remove();
            display.style.display = '';
            editBtn.style.display = '';
        }
        if (e.key === 'Enter' && !isLong) { e.preventDefault(); input.blur(); }
    });
};

// ── Audio player popup ────────────────────────────────────
window.openAudioPlayer = function (filename, url) {
    const player = document.getElementById('audioPlayer');
    document.getElementById('audioModalTitle').textContent = filename;
    player.pause(); player.src = url; player.load();
    const modal = new bootstrap.Modal(document.getElementById('audioModal'));
    modal.show();
    document.getElementById('audioModal').addEventListener('hidden.bs.modal', () => {
        player.pause(); player.src = '';
    }, { once: true });
};

// ── Image viewer popup ────────────────────────────────────
window.openImageViewer = function (filename, url) {
    document.getElementById('imageModalTitle').textContent = filename;
    document.getElementById('imageModalImg').src = url;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
};

// ── Relations edit modal ──────────────────────────────────
const BOOK_RELATION_DATA = @json($bookRelationData);
const GENRE_LIST    = @json($genreList);
const AC_URLS       = @json($autocompleteUrls);
const CSRF_TOKEN    = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

window.openRelationsModal = function (bookId, field, label) {
    const modal    = new bootstrap.Modal(document.getElementById('relationsModal'));
    const rowsEl   = document.getElementById('relations-rows');
    const errEl    = document.getElementById('relations-error');
    const addBtn   = document.getElementById('relations-add-btn');
    const saveBtn  = document.getElementById('relations-save-btn');

    document.getElementById('relationsModalTitle').textContent = 'Edit ' + label + ' — Book #' + bookId;
    rowsEl.innerHTML  = '';
    errEl.style.display = 'none';
    saveBtn.disabled  = false;

    const isGenres = field === 'genres';
    const current  = (BOOK_RELATION_DATA[bookId] ?? {})[field] ?? [];

    function makeRow(value) {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-2 mb-2';

        if (isGenres) {
            const sel = document.createElement('select');
            sel.className = 'form-select form-select-sm flex-grow-1 relations-value';
            const blank = document.createElement('option');
            blank.value = ''; blank.textContent = '— select —';
            sel.appendChild(blank);
            GENRE_LIST.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g; opt.textContent = g;
                if (g === value) opt.selected = true;
                sel.appendChild(opt);
            });
            row.appendChild(sel);
        } else {
            const inp = document.createElement('input');
            inp.type      = 'text';
            inp.className = 'form-control form-control-sm flex-grow-1 relations-value';
            inp.value     = value;
            inp.setAttribute('autocomplete', 'off');
            row.appendChild(inp);
            // jQuery UI autocomplete
            const acUrl = AC_URLS[field];
            if (acUrl && typeof $.fn.autocomplete === 'function') {
                $(inp).autocomplete({
                    minLength: 2,
                    source(req, cb) {
                        $.getJSON(acUrl, { term: req.term }, data => {
                            cb((data || []).map(d => ({ label: d.name ?? d, value: d.name ?? d })));
                        });
                    },
                });
            }
        }

        const rmBtn = document.createElement('button');
        rmBtn.type      = 'button';
        rmBtn.className = 'btn btn-outline-danger btn-sm py-0 px-2 flex-shrink-0';
        rmBtn.textContent = '×';
        rmBtn.addEventListener('click', () => row.remove());
        row.appendChild(rmBtn);
        rowsEl.appendChild(row);
    }

    (current.length ? current : ['']).forEach(makeRow);

    // Replace add/save handlers each open
    const newAdd = addBtn.cloneNode(true);
    addBtn.parentNode.replaceChild(newAdd, addBtn);
    newAdd.addEventListener('click', () => makeRow(''));

    const newSave = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newSave, saveBtn);
    newSave.addEventListener('click', async () => {
        const values = Array.from(rowsEl.querySelectorAll('.relations-value'))
            .map(el => el.value.trim())
            .filter(v => v !== '');

        newSave.disabled = true;
        errEl.style.display = 'none';
        try {
            const resp = await fetch('/admin/library-repair/books/' + bookId + '/field', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ field, values }),
            });
            if (!resp.ok) {
                const data = await resp.json().catch(() => ({}));
                errEl.textContent = data.error ?? 'Save failed.';
                errEl.style.display = '';
                newSave.disabled = false;
            } else {
                // Update local data so re-opening shows fresh values
                if (BOOK_RELATION_DATA[bookId]) BOOK_RELATION_DATA[bookId][field] = values;
                // Update display cell(s)
                document.querySelectorAll('.cell-display[data-book-id="' + bookId + '"][data-field="' + field + '"]')
                    .forEach(el => { el.textContent = values.join(', ') || '—'; });
                bootstrap.Modal.getInstance(document.getElementById('relationsModal')).hide();
            }
        } catch (e) {
            errEl.textContent = 'Network error.';
            errEl.style.display = '';
            newSave.disabled = false;
        }
    });

    modal.show();
};
</script>
@endsection
