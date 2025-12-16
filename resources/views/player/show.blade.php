@extends('layouts.app')

@section('content')
    <div class="container-fluid px-0">
        <div class="player-container">
            <!-- Book Info Header -->
            <div class="player-header bg-dark text-white p-4">
                <div class="row align-items-center">
                    <div class="col-md-2">
                        @php
                            $cover = isset($book['coverImage']) && $book['coverImage']
                                ? $book['coverImage']
                                : url('/images/placeholder.png');
                        @endphp
                        <img src="{{ $cover }}" alt="{{ $book['title'] }}" class="img-fluid rounded shadow">
                    </div>
                    <div class="col-md-10">
                        <h2 class="mb-2">{{ $book['title'] }}</h2>
                        <p class="mb-1">
                            <strong>Author:</strong>
                            @if(isset($book['authors']) && is_array($book['authors']) && !empty($book['authors']))
                                {{ implode(', ', $book['authors']) }}
                            @else
                                Unknown
                            @endif
                        </p>
                        @if(!empty($book['narrators']) && is_array($book['narrators']))
                            <p class="mb-1">
                                <strong>Narrator:</strong> {{ implode(', ', $book['narrators']) }}
                            </p>
                        @endif
                        @if(!empty($book['duration']))
                            <p class="mb-0">
                                <strong>Duration:</strong> {{ gmdate('H:i:s', $book['duration']) }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Audio Player -->
            <div class="player-controls bg-light p-4">
                <div class="row">
                    <div class="col-12">
                        <!-- Audio Element -->
                        <audio id="audioPlayer" preload="metadata" class="d-none">
                            @if(count($audioFiles) > 0)
                                <source
                                    src="{{ route('api.books.downloadFile', ['book' => $book['id'], 'file' => basename($audioFiles[0]['path'])]) }}"
                                    type="audio/mpeg">
                            @endif
                            Your browser does not support the audio element.
                        </audio>

                        <!-- Progress Bar -->
                        <div class="progress mb-3" style="height: 8px; cursor: pointer;" id="progressBar">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: 0%" id="progressFill">
                            </div>
                        </div>

                        <!-- Time Display -->
                        <div class="d-flex justify-content-between mb-3">
                            <span id="currentTime">0:00:00</span>
                            <span id="duration">0:00:00</span>
                        </div>

                        <!-- Playback Controls -->
                        <div class="d-flex justify-content-center align-items-center gap-3 mb-3">
                            <button class="btn btn-outline-secondary" id="rewind30" title="Rewind 30 seconds">
                                <i class="bi bi-skip-backward-fill"></i> 30s
                            </button>
                            <button class="btn btn-outline-secondary" id="rewind10" title="Rewind 10 seconds">
                                <i class="bi bi-arrow-counterclockwise"></i> 10s
                            </button>
                            <button class="btn btn-primary btn-lg" id="playPause" title="Play/Pause">
                                <i class="bi bi-play-fill" id="playIcon"></i>
                            </button>
                            <button class="btn btn-outline-secondary" id="forward10" title="Forward 10 seconds">
                                10s <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <button class="btn btn-outline-secondary" id="forward30" title="Forward 30 seconds">
                                30s <i class="bi bi-skip-forward-fill"></i>
                            </button>
                        </div>

                        <!-- Playback Speed and Volume -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="playbackRate" class="form-label">Playback Speed: <span
                                        id="rateDisplay">1.0x</span></label>
                                <input type="range" class="form-range" id="playbackRate" min="0.5" max="2.0" step="0.1"
                                    value="1.0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="volume" class="form-label">Volume: <span id="volumeDisplay">100%</span></label>
                                <input type="range" class="form-range" id="volume" min="0" max="100" value="100">
                            </div>
                        </div>

                        <!-- File Selector (if multiple files) -->
                        @if(count($audioFiles) > 1)
                            <div class="mb-3">
                                <label for="fileSelector" class="form-label">Audio File:</label>
                                <select class="form-select" id="fileSelector">
                                    @foreach($audioFiles as $index => $file)
                                        <option value="{{ $index }}"
                                            data-url="{{ route('api.books.downloadFile', ['book' => $book['id'], 'file' => basename($file['path'])]) }}">
                                            {{ $file['name'] }} ({{ number_format($file['size'] / 1048576, 2) }} MB)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Description -->
            @if(!empty($book['description']))
                <div class="player-description p-4">
                    <h5>Description</h5>
                    <p>{{ $book['description'] }}</p>
                </div>
            @endif
        </div>
    </div>

    <style>
        .player-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .player-header img {
            max-height: 200px;
            object-fit: cover;
        }

        .player-controls {
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        #progressBar {
            cursor: pointer;
        }

        #progressBar:hover {
            height: 12px !important;
            transition: height 0.2s;
        }

        .gap-3 {
            gap: 1rem !important;
        }

        .btn-lg {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-lg i {
            font-size: 1.5rem;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const audio = document.getElementById('audioPlayer');
            const playPauseBtn = document.getElementById('playPause');
            const playIcon = document.getElementById('playIcon');
            const progressBar = document.getElementById('progressBar');
            const progressFill = document.getElementById('progressFill');
            const currentTimeDisplay = document.getElementById('currentTime');
            const durationDisplay = document.getElementById('duration');
            const playbackRateSlider = document.getElementById('playbackRate');
            const rateDisplay = document.getElementById('rateDisplay');
            const volumeSlider = document.getElementById('volume');
            const volumeDisplay = document.getElementById('volumeDisplay');
            const rewind30Btn = document.getElementById('rewind30');
            const rewind10Btn = document.getElementById('rewind10');
            const forward10Btn = document.getElementById('forward10');
            const forward30Btn = document.getElementById('forward30');
            const fileSelector = document.getElementById('fileSelector');

            const bookId = '{{ $book["id"] }}';
            const userId = '{{ Auth::id() }}';

            // Load saved progress
            @if($progress)
                const savedProgress = {{ $progress['currentTime'] ?? 0 }};
                if (savedProgress > 0) {
                    audio.currentTime = savedProgress;
                }
            @endif

        // Load saved playback rate
        const savedRate = localStorage.getItem('playbackRate');
            if (savedRate) {
                audio.playbackRate = parseFloat(savedRate);
                playbackRateSlider.value = savedRate;
                rateDisplay.textContent = savedRate + 'x';
            }

            // Load saved volume
            const savedVolume = localStorage.getItem('volume');
            if (savedVolume) {
                audio.volume = parseInt(savedVolume) / 100;
                volumeSlider.value = savedVolume;
                volumeDisplay.textContent = savedVolume + '%';
            }

            // Play/Pause
            playPauseBtn.addEventListener('click', function () {
                if (audio.paused) {
                    audio.play();
                    playIcon.className = 'bi bi-pause-fill';
                } else {
                    audio.pause();
                    playIcon.className = 'bi bi-play-fill';
                }
            });

            // Update progress bar
            audio.addEventListener('timeupdate', function () {
                const progress = (audio.currentTime / audio.duration) * 100;
                progressFill.style.width = progress + '%';
                currentTimeDisplay.textContent = formatTime(audio.currentTime);

                // Save progress every 10 seconds
                if (userId && Math.floor(audio.currentTime) % 10 === 0) {
                    saveProgress();
                }
            });

            // Update duration display
            audio.addEventListener('loadedmetadata', function () {
                durationDisplay.textContent = formatTime(audio.duration);
            });

            // Seek on progress bar click
            progressBar.addEventListener('click', function (e) {
                const rect = progressBar.getBoundingClientRect();
                const percent = (e.clientX - rect.left) / rect.width;
                audio.currentTime = percent * audio.duration;
            });

            // Playback rate
            playbackRateSlider.addEventListener('input', function () {
                audio.playbackRate = this.value;
                rateDisplay.textContent = this.value + 'x';
                localStorage.setItem('playbackRate', this.value);
            });

            // Volume
            volumeSlider.addEventListener('input', function () {
                audio.volume = this.value / 100;
                volumeDisplay.textContent = this.value + '%';
                localStorage.setItem('volume', this.value);
            });

            // Skip buttons
            rewind30Btn.addEventListener('click', () => audio.currentTime = Math.max(0, audio.currentTime - 30));
            rewind10Btn.addEventListener('click', () => audio.currentTime = Math.max(0, audio.currentTime - 10));
            forward10Btn.addEventListener('click', () => audio.currentTime = Math.min(audio.duration, audio.currentTime + 10));
            forward30Btn.addEventListener('click', () => audio.currentTime = Math.min(audio.duration, audio.currentTime + 30));

            // File selector
            if (fileSelector) {
                fileSelector.addEventListener('change', function () {
                    const selectedOption = this.options[this.selectedIndex];
                    const url = selectedOption.dataset.url;
                    const currentTime = audio.currentTime;
                    const wasPlaying = !audio.paused;

                    audio.src = url;
                    audio.load();
                    audio.currentTime = currentTime;

                    if (wasPlaying) {
                        audio.play();
                    }
                });
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function (e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                switch (e.key) {
                    case ' ':
                        e.preventDefault();
                        playPauseBtn.click();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        audio.currentTime = Math.max(0, audio.currentTime - 10);
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        audio.currentTime = Math.min(audio.duration, audio.currentTime + 10);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        volumeSlider.value = Math.min(100, parseInt(volumeSlider.value) + 10);
                        volumeSlider.dispatchEvent(new Event('input'));
                        break;
                    case 'ArrowDown':
                        e.preventDefault();
                        volumeSlider.value = Math.max(0, parseInt(volumeSlider.value) - 10);
                        volumeSlider.dispatchEvent(new Event('input'));
                        break;
                }
            });

            // Format time helper
            function formatTime(seconds) {
                if (isNaN(seconds)) return '0:00:00';
                const h = Math.floor(seconds / 3600);
                const m = Math.floor((seconds % 3600) / 60);
                const s = Math.floor(seconds % 60);
                return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }

            // Save progress to server
            function saveProgress() {
                if (!userId) return;

                fetch(`/api/v1/books/${bookId}/progress`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('api_token'),
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        currentTime: audio.currentTime,
                        duration: audio.duration,
                        completed: audio.currentTime >= audio.duration - 10
                    })
                }).catch(err => console.error('Failed to save progress:', err));
            }

            // Save progress on page unload
            window.addEventListener('beforeunload', saveProgress);
        });
    </script>
@endsection
