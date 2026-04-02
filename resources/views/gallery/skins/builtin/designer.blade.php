@extends('layouts.app')

@section('content')
<style>
    html, body { height: 100vh; overflow: hidden; }
    #app { height: 100vh; display: flex; flex-direction: column; }
    main { flex: 1; display: flex; flex-direction: column; padding: 0 !important; overflow: hidden; }
    main > .container { display: none; }
    #designer-app { flex: 1; display: flex; flex-direction: column; height: 100%; overflow: hidden; }
</style>

<div id="designer-app"
     data-skin="{{ json_encode(array_merge($skin, ['id' => $skin['slug']])) }}"
     data-assets="{{ json_encode($assets) }}"
     data-manifest-url="{{ route('gallery.skins.builtin.updateManifest', $skin['slug']) }}"
     data-asset-url="{{ route('gallery.skins.builtin.uploadAsset', $skin['slug']) }}"
     data-asset-base-url="{{ route('gallery.skins.builtin.asset', ['slug' => $skin['slug'], 'path' => '']) }}">

    <!-- Toolbar -->
    <div class="designer-toolbar">
        <div class="d-flex align-items-center gap-3">
            <h1 class="h6 mb-0 text-light fw-bold">
                {{ $skin['name'] }}
                <span class="badge bg-warning text-dark ms-1" style="font-size: 0.65rem;">Built-in</span>
            </h1>
            <div class="orientation-controls">
                <button data-orientation="portrait" class="orientation-btn active" title="Portrait Mode">Portrait</button>
                <button data-orientation="landscape" class="orientation-btn" title="Landscape Mode">Landscape</button>
                <button data-orientation="drivePortrait" class="orientation-btn" title="Drive Mode Portrait"><i class="fas fa-car"></i> P</button>
                <button data-orientation="driveLandscape" class="orientation-btn" title="Drive Mode Landscape"><i class="fas fa-car"></i> L</button>
            </div>
        </div>
        <div class="toolbar-actions">
            <button id="add-element-btn" class="btn-toolbar-action btn-action-green">
                <i class="fas fa-plus"></i> Add Element
            </button>
            <button id="json-toggle-btn" class="btn-toolbar-action btn-action-dark">
                <i class="fas fa-code"></i> JSON
            </button>
            <div class="btn-group" style="margin-left: 10px;">
                <button onclick="window.zoomOut()" class="btn-toolbar-action btn-action-dark" title="Zoom Out">
                    <i class="fas fa-search-minus"></i>
                </button>
                <button onclick="window.zoomReset()" class="btn-toolbar-action btn-action-dark" title="Reset Zoom">
                    <span id="zoom-level" style="min-width: 40px; display: inline-block;">100%</span>
                </button>
                <button onclick="window.zoomIn()" class="btn-toolbar-action btn-action-dark" title="Zoom In">
                    <i class="fas fa-search-plus"></i>
                </button>
            </div>
            <a href="{{ route('gallery.skins.builtin.download', $skin['slug']) }}" class="btn-toolbar-action btn-action-blue">
                <i class="fas fa-download"></i> ZIP
            </a>
            <button id="save-skin-btn" class="btn-toolbar-action btn-action-blue fw-bold">
                <i class="fas fa-save"></i> Save to Disk
            </button>
        </div>
    </div>

    <!-- Admin warning banner -->
    <div style="background: #856404; color: #fff3cd; padding: 4px 16px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Admin mode:</strong> Changes save directly to the client source repository at <code style="background: rgba(0,0,0,0.2); padding: 0 4px; border-radius: 3px;">{{ $skin['dir'] }}</code>
    </div>

    <!-- Main Editor Area -->
    <div class="editor-main">
        <div class="sidebar-left">
            <div class="sidebar-header">Elements</div>
            <div id="element-tree" class="tree-container"></div>
            <div id="sample-data-wrapper" class="sample-data-section collapsed">
                <div class="sample-data-header" id="sample-data-header">
                    Sample Data <i class="fas fa-chevron-up"></i>
                </div>
                <div id="sample-data-editor" class="sample-data-content"></div>
            </div>
        </div>

        <div class="center-column" style="flex: 1; display: flex; flex-direction: column; position: relative; overflow: hidden;">
            <div id="preview-area" class="canvas-area" style="flex: 1; position: relative;">
                <div id="preview-container"></div>
            </div>
            <div id="json-editor-panel" class="json-panel hidden connected-panel">
                <div id="json-resize-handle" class="json-resize-handle"></div>
                <div class="json-header">
                    <span>manifest.json</span>
                    <div class="json-actions">
                        <button id="json-float-btn" class="json-action-btn" title="Toggle Float/Dock"><i class="fas fa-window-restore"></i></button>
                        <button id="json-close-btn" class="json-action-btn">&times;</button>
                    </div>
                </div>
                <textarea id="json-editor-textarea"></textarea>
            </div>
        </div>

        <div class="sidebar-right">
            <div class="sidebar-header">Properties</div>
            <div id="property-editor" class="property-container">
                <div class="text-center text-secondary small mt-5">Select an element to edit properties</div>
            </div>
        </div>
    </div>
</div>

<!-- Add Element Modal -->
<div id="add-element-modal" class="modal-overlay hidden">
    <div class="modal-content-custom">
        <div class="modal-header-custom">
            <h3>Add Element</h3>
            <button id="modal-close-btn" class="btn-close-panel" style="font-size: 24px;">&times;</button>
        </div>
        <div class="modal-body-custom">
            <button class="element-type-btn" data-type="text"><span class="element-type-icon">T</span> <span>Text Label</span></button>
            <button class="element-type-btn" data-type="button"><span class="element-type-icon">👆</span> <span>Button</span></button>
            <button class="element-type-btn" data-type="image"><span class="element-type-icon">🖼️</span> <span>Image</span></button>
            <button class="element-type-btn" data-type="cover-image"><span class="element-type-icon">📕</span> <span>Cover Art</span></button>
            <button class="element-type-btn" data-type="progress-bar"><span class="element-type-icon">📊</span> <span>Progress Bar</span></button>
            <button class="element-type-btn" data-type="container"><span class="element-type-icon">📦</span> <span>Container</span></button>
        </div>
    </div>
</div>

@endsection

@push('styles')
<link href="{{ asset('css/skin-designer.css?v=' . time()) }}" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/lint/lint.min.css">
@endpush

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsonlint/1.6.0/jsonlint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/lint/lint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/lint/json-lint.min.js"></script>
<script type="module" src="{{ asset('js/skin-designer.js?v=' . time()) }}"></script>
@endpush
