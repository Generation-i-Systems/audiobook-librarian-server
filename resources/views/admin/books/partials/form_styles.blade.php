        <style>
            .cursor-pointer {
                cursor: pointer;
            }

            .cursor-pointer:hover {
                background-color: #f8f9fa;
            }

            .book-form-card {
                background: white;
                border-radius: 8px;
                padding: 1.5rem;
                margin-bottom: 1.5rem;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }


            .book-form-section-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: #212529;
                margin-bottom: 0;
                padding-bottom: 0.5rem;
                border-bottom: 2px solid #e9ecef;
                cursor: pointer;
                user-select: none;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .book-form-section-title:hover {
                background-color: #f8f9fa;
            }

            .book-form-section-title .toggle-icon {
                transition: transform 0.2s;
            }

            .book-form-section-title.collapsed .toggle-icon {
                transform: rotate(-90deg);
            }

            .card-summary {
                color: #6c757d;
                font-size: 0.9rem;
                font-weight: normal;
                padding: 0.5rem 0;
                margin-bottom: 0.25rem;
            }

            .form-control-height-32 {
                height: 32px;
            }

            .form-control-flex-1 {
                flex: 1;
            }

            .btn-size-32 {
                width: 32px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .btn-size-32-danger {
                width: 32px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .btn-size-40 {
                width: 40px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .btn-size-40-danger {
                width: 40px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .btn-size-40-primary {
                width: 40px;
                height: 28px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .gap-2px {
                gap: 2px;
            }

            .ms-gap-2px {
                margin-left: 0.5rem;
            }

            .width-80 {
                width: 80px;
            }

            .flex-shrink-0 {
                flex-shrink: 0;
            }

            .cover-preview-size {
                height: 120px;
                border: 2px solid #dee2e6;
                border-radius: 4px;
            }

            .cover-preview-placeholder {
                opacity: 0.5;
            }

            .cover-preview-badge {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: -8px;
            }

            .series-placeholder {
                width: 32px;
                height: 32px;
                margin-left: 0.5rem;
                flex-shrink: 0;
            }

            .directory-files-list {
                max-height: 220px;
                overflow-y: auto;
                border: 1px solid #ccc;
                border-radius: 4px;
                background: #fafbfc;
                padding: 8px;
            }

            .cover-option-img {
                max-width: 100px;
                max-height: 140px;
                border: 1px solid #ccc;
                border-radius: 4px;
            }

            .directory-not-found-btn {
                display: none;
                right: 5px;
                top: 50%;
                transform: translateY(-50%);
                padding: 0;
                width: 25px;
                height: 25px;
            }

            .margin-top-zero {
                margin-top: 0;
            }

            .max-width-1400 {
                max-width: 1400px;
            }

            .margin-bottom-30 {
                margin-bottom: 30px;
            }

            .margin-bottom-10 {
                margin-bottom: 10px;
            }

            .margin-left-half-rem {
                margin-left: 0.5rem;
            }

            .cursor-pointer-style {
                cursor: pointer;
            }

            .font-size-12 {
                font-size: 12px;
            }

            .padding-right-35 {
                padding-right: 35px;
            }

            .display-none-style {
                display: none;
            }

            .right-5px {
                right: 5px;
            }

            .top-50-percent {
                top: 50%;
            }

            .transform-translate-y {
                transform: translateY(-50%);
            }

            .padding-none {
                padding: 0;
            }

            .width-25-height-25 {
                width: 25px;
                height: 25px;
            }

            .white-space-nowrap {
                white-space: nowrap;
            }

            .min-width-300 {
                min-width: 300px;
            }

            .gap-half-rem {
                gap: 0.5rem;
            }

            .padding-0-8px {
                padding: 0 8px;
            }

            .display-flex-center {
                display: flex;
            }

            .align-items-center {
                align-items: center;
            }

            .justify-content-center {
                justify-content: center;
            }

            .position-absolute {
                position: absolute;
            }

            .width-40-height-32 {
                width: 40px;
                height: 32px;
            }

            .height-28 {
                height: 28px;
            }

            .width-25-height-25 {
                width: 25px;
                height: 25px;
            }

            .display-none {
                display: none;
            }

            .width-auto {
                width: auto;
            }

            .min-width-40 {
                min-width: 40px;
            }

            .padding-right-35 {
                padding-right: 35px;
            }

            .width-40-height-32 {
                width: 40px;
                height: 32px;
            }

            .height-28 {
                height: 28px;
            }

            .width-25-height-25 {
                width: 25px;
                height: 25px;
            }

            .display-none {
                display: none;
            }

            .width-auto {
                width: auto;
            }

            .min-width-40 {
                min-width: 40px;
            }

            .margin-top-zero {
                margin-top: 0;
            }

            .margin-bottom-30 {
                margin-bottom: 30px;
            }

            .margin-bottom-10 {
                margin-bottom: 10px;
            }

            .display-inline {
                display: inline;
            }

            .cursor-pointer-style {
                cursor: pointer;
            }

            .position-relative-style {
                position: relative;
            }

            .cover-preview-container {
                height: 120px;
                border: 2px solid #dee2e6;
                border-radius: 4px;
            }

            .cover-preview-circle {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: -8px;
            }

            .font-size-12 {
                font-size: 12px;
            }

            .form-check-center {
                height: 32px;
            }

            .directory-path-input {
                padding-right: 35px;
            }

            .directory-not-found-btn {
                display: none;
                right: 5px;
                top: 50%;
                transform: translateY(-50%);
                padding: 0;
                width: 25px;
                height: 25px;
            }

            .button-32-32-0-flex-center {
                width: 32px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .button-40-28-0-flex-center {
                width: 40px;
                height: 28px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .button-40-32-0-flex-center {
                width: 40px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .button-32-32-0-display-none-flex-center {
                width: 32px;
                height: 32px;
                padding: 0;
                display: none;
                align-items: center;
                justify-content: center;
                white-space: nowrap;
            }

            .min-width-300-200 {
                min-width: 300px;
            }

            .flex-1 {
                flex: 1;
            }

            .form-column-2 {
                flex: 0.5rem;
            }

            .border-rounded {
                max-height: 400px;
                overflow-y: auto;
            }

            .font-size-12-word-break {
                font-size: 12px;
                word-break: break-all;
            }

            .display-none-style {
                display: none;
            }

            .min-height-50 {
                min-height: 50px;
            }

            .max-height-220-overflow-auto {
                max-height: 220px;
                overflow-y: auto;
            }

            .border-1px-solid {
                border: 1px solid;
            }

            .background-color {
                background: #f8f9fa;
            }

            .border-radius-4px {
                border-radius: 4px;
            }

            .padding-10 {
                padding: 10px;
            }

            .max-height-400 {
                max-height: 400px;
            }

            .border-1px-solid {
                border: 1px solid #dee2e6;
            }

            .font-family-monospace {
                font-family: monospace;
            }

            .font-size-0-98em {
                font-size: 0.98em;
            }

            .display-none {
                display: none;
            }

            .table-responsive {
                display: none;
            }

            .font-size-14 {
                font-size: 14px;
            }

            .width-100 {
                width: 100%;
            }

            .display-inline-block {
                display: inline-block;
            }

            .font-weight-bold {
                font-weight: bold;
            }

            .margin-top-10 {
                margin-top: 10px;
            }

            #autofillModal .modal-body {
                max-height: 70vh;
                overflow-y: auto;
            }
        </style>
