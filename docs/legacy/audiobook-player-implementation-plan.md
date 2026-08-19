# Audiobook Web Player - Implementation Plan

## Overview
Build a comprehensive web-based audiobook player with progress tracking, cross-device sync, and advanced playback features for the audiobook library system.

---

## Phase 1: Core Infrastructure

### 1.1 Database Schema

#### Progress Tracking Table
**Migration**: `create_audiobook_progress_table.php`

```php
Schema::create('audiobook_progress', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('book_id'); // MongoDB ObjectId or MySQL ID
    $table->integer('current_position')->default(0); // Seconds
    $table->integer('duration')->nullable(); // Total duration in seconds
    $table->integer('file_index')->default(0); // For multi-file books
    $table->decimal('playback_rate', 3, 2)->default(1.00); // 0.50 to 3.00
    $table->timestamp('last_played_at');
    $table->boolean('completed')->default(false);
    $table->timestamps();
    
    $table->unique(['user_id', 'book_id']);
    $table->index(['user_id', 'last_played_at']);
});
```

#### Bookmarks Table
**Migration**: `create_audiobook_bookmarks_table.php`

```php
Schema::create('audiobook_bookmarks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('book_id');
    $table->integer('position'); // Seconds
    $table->integer('file_index')->default(0);
    $table->string('title')->nullable();
    $table->text('note')->nullable();
    $table->timestamps();
    
    $table->index(['user_id', 'book_id']);
});
```

#### Playback Queue Table
**Migration**: `create_audiobook_queue_table.php`

```php
Schema::create('audiobook_queue', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('book_id');
    $table->integer('position')->default(0); // Queue order
    $table->timestamps();
    
    $table->unique(['user_id', 'book_id']);
    $table->index(['user_id', 'position']);
});
```

### 1.2 Audio Streaming Controller

**File**: `app/Http/Controllers/AudioStreamController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioStreamController extends Controller
{
    public function __construct(
        private DocumentStoreServiceInterface $documentStore
    ) {}

    /**
     * Stream audio file with HTTP Range support
     */
    public function stream(Request $request, string $bookId, int $fileIndex = 0)
    {
        // Get book and verify access
        $book = $this->documentStore->getBookById($bookId);
        
        if (!$book) {
            abort(404, 'Book not found');
        }
        
        // Get file path
        $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
        $directoryPath = $storageRoot . '/' . $book['directoryPath'];
        
        // Get audio files
        $audioFiles = glob($directoryPath . '/*.{m4b,mp3,m4a}', GLOB_BRACE);
        
        if (!isset($audioFiles[$fileIndex])) {
            abort(404, 'Audio file not found');
        }
        
        $filePath = $audioFiles[$fileIndex];
        $fileSize = filesize($filePath);
        $mimeType = $this->getMimeType($filePath);
        
        // Handle Range requests for seeking
        $range = $request->header('Range');
        
        if ($range) {
            return $this->streamRange($filePath, $fileSize, $mimeType, $range);
        }
        
        return $this->streamFull($filePath, $fileSize, $mimeType);
    }
    
    private function streamRange($filePath, $fileSize, $mimeType, $range)
    {
        list($start, $end) = $this->parseRange($range, $fileSize);
        
        $length = $end - $start + 1;
        
        return response()->stream(function() use ($filePath, $start, $length) {
            $file = fopen($filePath, 'rb');
            fseek($file, $start);
            
            $buffer = 8192;
            $bytesRemaining = $length;
            
            while ($bytesRemaining > 0 && !feof($file)) {
                $bytesToRead = min($buffer, $bytesRemaining);
                echo fread($file, $bytesToRead);
                $bytesRemaining -= $bytesToRead;
                flush();
            }
            
            fclose($file);
        }, 206, [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
    
    private function streamFull($filePath, $fileSize, $mimeType)
    {
        return response()->stream(function() use ($filePath) {
            $file = fopen($filePath, 'rb');
            fpassthru($file);
            fclose($file);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
    
    private function parseRange($range, $fileSize)
    {
        preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
        
        $start = (int) $matches[1];
        $end = !empty($matches[2]) ? (int) $matches[2] : $fileSize - 1;
        
        return [$start, $end];
    }
    
    private function getMimeType($filePath)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        return match(strtolower($extension)) {
            'm4b', 'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream',
        };
    }
}
```

### 1.3 Progress API Controller

**File**: `app/Http/Controllers/Api/AudioProgressController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudiobookProgress;
use Illuminate\Http\Request;

class AudioProgressController extends Controller
{
    /**
     * Get progress for a book
     */
    public function show(Request $request, string $bookId)
    {
        $progress = AudiobookProgress::where('user_id', $request->user()->id)
            ->where('book_id', $bookId)
            ->first();
            
        return response()->json($progress ?? [
            'current_position' => 0,
            'file_index' => 0,
            'playback_rate' => 1.0,
            'completed' => false,
        ]);
    }
    
    /**
     * Save progress for a book
     */
    public function store(Request $request, string $bookId)
    {
        $validated = $request->validate([
            'current_position' => 'required|integer|min:0',
            'duration' => 'nullable|integer|min:0',
            'file_index' => 'integer|min:0',
            'playback_rate' => 'numeric|min:0.5|max:3.0',
            'completed' => 'boolean',
        ]);
        
        $progress = AudiobookProgress::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'book_id' => $bookId,
            ],
            array_merge($validated, [
                'last_played_at' => now(),
            ])
        );
        
        return response()->json($progress);
    }
    
    /**
     * Get recently played books
     */
    public function recent(Request $request)
    {
        $limit = $request->get('limit', 10);
        
        $progress = AudiobookProgress::where('user_id', $request->user()->id)
            ->orderBy('last_played_at', 'desc')
            ->limit($limit)
            ->get();
            
        return response()->json($progress);
    }
    
    /**
     * Reset progress for a book
     */
    public function destroy(Request $request, string $bookId)
    {
        AudiobookProgress::where('user_id', $request->user()->id)
            ->where('book_id', $bookId)
            ->delete();
            
        return response()->json(['message' => 'Progress reset']);
    }
}
```

### 1.4 Routes

**File**: `routes/web.php` and `routes/api.php`

```php
// Web routes (web.php)
Route::middleware(['auth'])->group(function () {
    Route::get('/audio/stream/{bookId}/{fileIndex?}', [
        AudioStreamController::class, 
        'stream'
    ])->name('audio.stream');
});

// API routes (api.php)
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('/progress/{bookId}', [AudioProgressController::class, 'show']);
    Route::post('/progress/{bookId}', [AudioProgressController::class, 'store']);
    Route::get('/progress', [AudioProgressController::class, 'recent']);
    Route::delete('/progress/{bookId}', [AudioProgressController::class, 'destroy']);
    
    Route::get('/bookmarks/{bookId}', [AudioBookmarkController::class, 'index']);
    Route::post('/bookmarks/{bookId}', [AudioBookmarkController::class, 'store']);
    Route::delete('/bookmarks/{id}', [AudioBookmarkController::class, 'destroy']);
    
    Route::get('/queue', [AudioQueueController::class, 'index']);
    Route::post('/queue/{bookId}', [AudioQueueController::class, 'add']);
    Route::delete('/queue/{bookId}', [AudioQueueController::class, 'remove']);
    Route::put('/queue/reorder', [AudioQueueController::class, 'reorder']);
});
```

---

## Phase 2: Player UI Component

### 2.1 Player Blade Component

**File**: `resources/views/components/audiobook-player.blade.php`

```blade
<div id="audiobook-player" class="audiobook-player" style="display: none;">
    <div class="player-container">
        {{-- Book Info --}}
        <div class="player-header">
            <img id="player-cover" class="player-cover" src="" alt="Book Cover">
            <div class="player-info">
                <h3 id="player-title" class="player-title"></h3>
                <p id="player-author" class="player-author"></p>
                <p id="player-series" class="player-series"></p>
            </div>
            <button id="player-close" class="player-close-btn" title="Close Player">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        {{-- Main Controls --}}
        <div class="player-controls">
            <button id="skip-backward" class="control-btn" title="Skip Backward 30s">
                <i class="fas fa-backward"></i>
                <span class="skip-time">30</span>
            </button>
            
            <button id="play-pause" class="control-btn control-btn-large" title="Play/Pause">
                <i class="fas fa-play"></i>
            </button>
            
            <button id="skip-forward" class="control-btn" title="Skip Forward 30s">
                <i class="fas fa-forward"></i>
                <span class="skip-time">30</span>
            </button>
        </div>
        
        {{-- Progress Bar --}}
        <div class="player-progress">
            <span id="current-time" class="time-display">0:00:00</span>
            <div id="progress-bar" class="progress-bar">
                <div id="progress-fill" class="progress-fill"></div>
                <div id="progress-handle" class="progress-handle"></div>
            </div>
            <span id="total-time" class="time-display">0:00:00</span>
        </div>
        
        {{-- Secondary Controls --}}
        <div class="player-secondary-controls">
            <div class="control-group">
                <button id="volume-btn" class="secondary-btn" title="Volume">
                    <i class="fas fa-volume-up"></i>
                </button>
                <input id="volume-slider" type="range" min="0" max="100" value="100" class="volume-slider">
            </div>
            
            <div class="control-group">
                <button id="speed-btn" class="secondary-btn" title="Playback Speed">
                    <span id="speed-display">1.0x</span>
                </button>
                <div id="speed-menu" class="speed-menu" style="display: none;">
                    <button data-speed="0.5">0.5x</button>
                    <button data-speed="0.75">0.75x</button>
                    <button data-speed="1.0" class="active">1.0x</button>
                    <button data-speed="1.25">1.25x</button>
                    <button data-speed="1.5">1.5x</button>
                    <button data-speed="1.75">1.75x</button>
                    <button data-speed="2.0">2.0x</button>
                    <button data-speed="2.5">2.5x</button>
                    <button data-speed="3.0">3.0x</button>
                </div>
            </div>
            
            <button id="sleep-timer-btn" class="secondary-btn" title="Sleep Timer">
                <i class="fas fa-moon"></i>
                <span id="sleep-timer-display"></span>
            </button>
            
            <button id="chapters-btn" class="secondary-btn" title="Chapters">
                <i class="fas fa-list"></i>
            </button>
            
            <button id="bookmarks-btn" class="secondary-btn" title="Bookmarks">
                <i class="fas fa-bookmark"></i>
            </button>
        </div>
        
        {{-- Chapters Panel --}}
        <div id="chapters-panel" class="side-panel" style="display: none;">
            <div class="panel-header">
                <h4>Chapters</h4>
                <button class="panel-close"><i class="fas fa-times"></i></button>
            </div>
            <div id="chapters-list" class="panel-content">
                <!-- Chapters populated by JS -->
            </div>
        </div>
        
        {{-- Bookmarks Panel --}}
        <div id="bookmarks-panel" class="side-panel" style="display: none;">
            <div class="panel-header">
                <h4>Bookmarks</h4>
                <button id="add-bookmark-btn" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> Add
                </button>
                <button class="panel-close"><i class="fas fa-times"></i></button>
            </div>
            <div id="bookmarks-list" class="panel-content">
                <!-- Bookmarks populated by JS -->
            </div>
        </div>
    </div>
    
    {{-- Hidden Audio Element --}}
    <audio id="audio-element" preload="metadata"></audio>
</div>

<style>
.audiobook-player {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(to bottom, #2c3e50, #1a252f);
    color: white;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
    z-index: 1000;
    padding: 1rem;
}

.player-container {
    max-width: 1200px;
    margin: 0 auto;
}

.player-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.player-cover {
    width: 60px;
    height: 60px;
    border-radius: 4px;
    object-fit: cover;
}

.player-info {
    flex: 1;
}

.player-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.player-author, .player-series {
    margin: 0;
    font-size: 0.9rem;
    opacity: 0.8;
}

.player-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 2rem;
    margin-bottom: 1rem;
}

.control-btn {
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.control-btn:hover {
    background: rgba(255,255,255,0.2);
    transform: scale(1.05);
}

.control-btn-large {
    width: 70px;
    height: 70px;
    font-size: 1.5rem;
}

.skip-time {
    position: absolute;
    bottom: -5px;
    right: -5px;
    font-size: 0.7rem;
    background: rgba(0,0,0,0.5);
    padding: 2px 4px;
    border-radius: 3px;
}

.player-progress {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.time-display {
    font-size: 0.9rem;
    min-width: 60px;
}

.progress-bar {
    flex: 1;
    height: 6px;
    background: rgba(255,255,255,0.2);
    border-radius: 3px;
    position: relative;
    cursor: pointer;
}

.progress-fill {
    height: 100%;
    background: #3498db;
    border-radius: 3px;
    width: 0%;
    transition: width 0.1s;
}

.progress-handle {
    position: absolute;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 14px;
    height: 14px;
    background: white;
    border-radius: 50%;
    left: 0%;
    cursor: grab;
}

.progress-handle:active {
    cursor: grabbing;
}

.player-secondary-controls {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.control-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.secondary-btn {
    background: transparent;
    border: none;
    color: white;
    padding: 0.5rem 1rem;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.2s;
}

.secondary-btn:hover {
    background: rgba(255,255,255,0.1);
}

.volume-slider {
    width: 100px;
}

.speed-menu {
    position: absolute;
    bottom: 100%;
    background: #2c3e50;
    border-radius: 4px;
    padding: 0.5rem;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.25rem;
    margin-bottom: 0.5rem;
}

.speed-menu button {
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    padding: 0.5rem;
    cursor: pointer;
    border-radius: 4px;
}

.speed-menu button.active {
    background: #3498db;
}

.side-panel {
    position: absolute;
    right: 0;
    bottom: 100%;
    width: 300px;
    max-height: 400px;
    background: #2c3e50;
    border-radius: 8px 8px 0 0;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.panel-content {
    padding: 1rem;
    max-height: 340px;
    overflow-y: auto;
}

.player-close-btn {
    background: transparent;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0.5rem;
}
</style>
```

### 2.2 Player JavaScript

**File**: `public/js/audiobook-player.js`

```javascript
class AudiobookPlayer {
    constructor() {
        this.audio = document.getElementById('audio-element');
        this.player = document.getElementById('audiobook-player');
        this.currentBook = null;
        this.currentFileIndex = 0;
        this.files = [];
        this.chapters = [];
        this.progressSaveInterval = null;
        this.sleepTimer = null;
        this.sleepTimerInterval = null;
        this.isDragging = false;
        
        this.initializeElements();
        this.attachEventListeners();
        this.initializeKeyboardShortcuts();
        this.loadPlayerState();
    }
    
    initializeElements() {
        // Control elements
        this.playPauseBtn = document.getElementById('play-pause');
        this.skipBackwardBtn = document.getElementById('skip-backward');
        this.skipForwardBtn = document.getElementById('skip-forward');
        this.progressBar = document.getElementById('progress-bar');
        this.progressFill = document.getElementById('progress-fill');
        this.progressHandle = document.getElementById('progress-handle');
        this.currentTimeDisplay = document.getElementById('current-time');
        this.totalTimeDisplay = document.getElementById('total-time');
        this.volumeSlider = document.getElementById('volume-slider');
        this.speedBtn = document.getElementById('speed-btn');
        this.speedDisplay = document.getElementById('speed-display');
        this.speedMenu = document.getElementById('speed-menu');
        
        // Info elements
        this.coverImg = document.getElementById('player-cover');
        this.titleDisplay = document.getElementById('player-title');
        this.authorDisplay = document.getElementById('player-author');
        this.seriesDisplay = document.getElementById('player-series');
        
        // Panel elements
        this.chaptersBtn = document.getElementById('chapters-btn');
        this.chaptersPanel = document.getElementById('chapters-panel');
        this.chaptersList = document.getElementById('chapters-list');
        this.bookmarksBtn = document.getElementById('bookmarks-btn');
        this.bookmarksPanel = document.getElementById('bookmarks-panel');
        this.bookmarksList = document.getElementById('bookmarks-list');
        
        // Sleep timer
        this.sleepTimerBtn = document.getElementById('sleep-timer-btn');
        this.sleepTimerDisplay = document.getElementById('sleep-timer-display');
        
        // Close button
        this.closeBtn = document.getElementById('player-close');
    }
    
    attachEventListeners() {
        // Playback controls
        this.playPauseBtn.addEventListener('click', () => this.togglePlayPause());
        this.skipBackwardBtn.addEventListener('click', () => this.skip(-30));
        this.skipForwardBtn.addEventListener('click', () => this.skip(30));
        
        // Progress bar
        this.progressBar.addEventListener('click', (e) => this.seekToPosition(e));
        this.progressHandle.addEventListener('mousedown', (e) => this.startDragging(e));
        document.addEventListener('mousemove', (e) => this.drag(e));
        document.addEventListener('mouseup', () => this.stopDragging());
        
        // Volume
        this.volumeSlider.addEventListener('input', (e) => this.setVolume(e.target.value / 100));
        
        // Speed
        this.speedBtn.addEventListener('click', () => this.toggleSpeedMenu());
        document.querySelectorAll('.speed-menu button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.setPlaybackRate(parseFloat(e.target.dataset.speed));
                this.toggleSpeedMenu();
            });
        });
        
        // Panels
        this.chaptersBtn.addEventListener('click', () => this.togglePanel('chapters'));
        this.bookmarksBtn.addEventListener('click', () => this.togglePanel('bookmarks'));
        document.querySelectorAll('.panel-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.target.closest('.side-panel').style.display = 'none';
            });
        });
        
        // Sleep timer
        this.sleepTimerBtn.addEventListener('click', () => this.showSleepTimerMenu());
        
        // Close player
        this.closeBtn.addEventListener('click', () => this.close());
        
        // Audio events
        this.audio.addEventListener('timeupdate', () => this.onTimeUpdate());
        this.audio.addEventListener('ended', () => this.onEnded());
        this.audio.addEventListener('loadedmetadata', () => this.onMetadataLoaded());
        this.audio.addEventListener('error', (e) => this.onError(e));
    }
    
    initializeKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Don't trigger if typing in input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }
            
            switch(e.key) {
                case ' ':
                    e.preventDefault();
                    this.togglePlayPause();
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    this.skip(e.shiftKey ? -60 : -15);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.skip(e.shiftKey ? 60 : 15);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.adjustVolume(0.1);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.adjustVolume(-0.1);
                    break;
                case '[':
                    this.adjustSpeed(-0.25);
                    break;
                case ']':
                    this.adjustSpeed(0.25);
                    break;
                case 'm':
                case 'M':
                    this.toggleMute();
                    break;
            }
        });
    }
    
    async loadBook(bookId) {
        try {
            // Fetch book data
            const response = await fetch(`/api/v1/books/${bookId}`);
            const book = await response.json();
            
            this.currentBook = book;
            this.currentFileIndex = 0;
            
            // Update UI
            this.updateBookInfo(book);
            
            // Load progress
            await this.loadProgress();
            
            // Load audio file
            this.loadAudioFile();
            
            // Load chapters
            await this.loadChapters();
            
            // Show player
            this.player.style.display = 'block';
            
            // Start auto-save
            this.startAutoSave();
            
        } catch (error) {
            console.error('Error loading book:', error);
            alert('Error loading audiobook');
        }
    }
    
    updateBookInfo(book) {
        this.titleDisplay.textContent = book.title || 'Unknown Title';
        this.authorDisplay.textContent = Array.isArray(book.author) 
            ? book.author.join(', ') 
            : book.author || 'Unknown Author';
            
        if (book.series) {
            const seriesName = typeof book.series === 'object' 
                ? Object.keys(book.series)[0] 
                : book.series;
            const seriesNumber = typeof book.series === 'object'
                ? book.series[seriesName]
                : book.seriesNumber;
            this.seriesDisplay.textContent = seriesNumber 
                ? `${seriesName} #${seriesNumber}`
                : seriesName;
            this.seriesDisplay.style.display = 'block';
        } else {
            this.seriesDisplay.style.display = 'none';
        }
        
        // Load cover image
        if (book.coverImage) {
            const coverUrl = `/api/v1/cover/${book.directoryPath}/${book.coverImage}`;
            this.coverImg.src = coverUrl;
        }
    }
    
    loadAudioFile() {
        const streamUrl = `/audio/stream/${this.currentBook._id}/${this.currentFileIndex}`;
        this.audio.src = streamUrl;
        this.audio.load();
    }
    
    async loadProgress() {
        try {
            const response = await fetch(`/api/v1/progress/${this.currentBook._id}`);
            const progress = await response.json();
            
            if (progress && progress.current_position) {
                this.currentFileIndex = progress.file_index || 0;
                this.audio.currentTime = progress.current_position;
                this.setPlaybackRate(progress.playback_rate || 1.0);
            }
        } catch (error) {
            console.error('Error loading progress:', error);
        }
    }
    
    async saveProgress() {
        if (!this.currentBook) return;
        
        try {
            await fetch(`/api/v1/progress/${this.currentBook._id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    current_position: Math.floor(this.audio.currentTime),
                    duration: Math.floor(this.audio.duration),
                    file_index: this.currentFileIndex,
                    playback_rate: this.audio.playbackRate,
                    completed: this.audio.currentTime >= this.audio.duration - 10,
                }),
            });
        } catch (error) {
            console.error('Error saving progress:', error);
        }
    }
    
    startAutoSave() {
        if (this.progressSaveInterval) {
            clearInterval(this.progressSaveInterval);
        }
        
        // Save every 10 seconds
        this.progressSaveInterval = setInterval(() => {
            if (!this.audio.paused) {
                this.saveProgress();
            }
        }, 10000);
    }
    
    async loadChapters() {
        // TODO: Extract chapters from M4B metadata or metadata.abs
        // For now, just clear the list
        this.chapters = [];
        this.chaptersList.innerHTML = '<p class="text-muted">No chapters available</p>';
    }
    
    togglePlayPause() {
        if (this.audio.paused) {
            this.audio.play();
            this.playPauseBtn.querySelector('i').className = 'fas fa-pause';
        } else {
            this.audio.pause();
            this.playPauseBtn.querySelector('i').className = 'fas fa-play';
        }
    }
    
    skip(seconds) {
        this.audio.currentTime = Math.max(0, Math.min(this.audio.duration, this.audio.currentTime + seconds));
    }
    
    seekToPosition(e) {
        const rect = this.progressBar.getBoundingClientRect();
        const percent = (e.clientX - rect.left) / rect.width;
        this.audio.currentTime = percent * this.audio.duration;
    }
    
    startDragging(e) {
        this.isDragging = true;
        this.audio.pause();
    }
    
    drag(e) {
        if (!this.isDragging) return;
        
        const rect = this.progressBar.getBoundingClientRect();
        let percent = (e.clientX - rect.left) / rect.width;
        percent = Math.max(0, Math.min(1, percent));
        
        this.progressFill.style.width = `${percent * 100}%`;
        this.progressHandle.style.left = `${percent * 100}%`;
        this.currentTimeDisplay.textContent = this.formatTime(percent * this.audio.duration);
    }
    
    stopDragging() {
        if (!this.isDragging) return;
        
        this.isDragging = false;
        const percent = parseFloat(this.progressFill.style.width) / 100;
        this.audio.currentTime = percent * this.audio.duration;
        this.audio.play();
    }
    
    setVolume(volume) {
        this.audio.volume = Math.max(0, Math.min(1, volume));
        this.volumeSlider.value = volume * 100;
    }
    
    adjustVolume(delta) {
        this.setVolume(this.audio.volume + delta);
    }
    
    toggleMute() {
        this.audio.muted = !this.audio.muted;
        const icon = document.querySelector('#volume-btn i');
        icon.className = this.audio.muted ? 'fas fa-volume-mute' : 'fas fa-volume-up';
    }
    
    setPlaybackRate(rate) {
        this.audio.playbackRate = rate;
        this.speedDisplay.textContent = `${rate}x`;
        
        // Update active button
        document.querySelectorAll('.speed-menu button').forEach(btn => {
            btn.classList.toggle('active', parseFloat(btn.dataset.speed) === rate);
        });
    }
    
    adjustSpeed(delta) {
        const newRate = Math.max(0.5, Math.min(3.0, this.audio.playbackRate + delta));
        this.setPlaybackRate(Math.round(newRate * 4) / 4); // Round to nearest 0.25
    }
    
    toggleSpeedMenu() {
        this.speedMenu.style.display = this.speedMenu.style.display === 'none' ? 'grid' : 'none';
    }
    
    togglePanel(panelName) {
        const panel = document.getElementById(`${panelName}-panel`);
        const isVisible = panel.style.display !== 'none';
        
        // Hide all panels
        document.querySelectorAll('.side-panel').forEach(p => p.style.display = 'none');
        
        // Show requested panel if it was hidden
        if (!isVisible) {
            panel.style.display = 'block';
            
            if (panelName === 'bookmarks') {
                this.loadBookmarks();
            }
        }
    }
    
    async loadBookmarks() {
        // TODO: Implement bookmark loading
        this.bookmarksList.innerHTML = '<p class="text-muted">No bookmarks yet</p>';
    }
    
    showSleepTimerMenu() {
        const minutes = prompt('Sleep timer (minutes):', '30');
        if (minutes) {
            this.setSleepTimer(parseInt(minutes));
        }
    }
    
    setSleepTimer(minutes) {
        if (this.sleepTimer) {
            clearTimeout(this.sleepTimer);
            clearInterval(this.sleepTimerInterval);
        }
        
        const endTime = Date.now() + (minutes * 60 * 1000);
        
        this.sleepTimer = setTimeout(() => {
            this.fadeOutAndPause();
        }, minutes * 60 * 1000);
        
        // Update display every second
        this.sleepTimerInterval = setInterval(() => {
            const remaining = Math.max(0, endTime - Date.now());
            const mins = Math.floor(remaining / 60000);
            const secs = Math.floor((remaining % 60000) / 1000);
            this.sleepTimerDisplay.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
            
            if (remaining === 0) {
                clearInterval(this.sleepTimerInterval);
                this.sleepTimerDisplay.textContent = '';
            }
        }, 1000);
    }
    
    fadeOutAndPause() {
        const fadeInterval = setInterval(() => {
            if (this.audio.volume > 0.1) {
                this.audio.volume -= 0.1;
            } else {
                this.audio.pause();
                this.audio.volume = 1.0;
                clearInterval(fadeInterval);
                this.saveProgress();
            }
        }, 1000);
    }
    
    onTimeUpdate() {
        if (this.isDragging) return;
        
        const percent = (this.audio.currentTime / this.audio.duration) * 100;
        this.progressFill.style.width = `${percent}%`;
        this.progressHandle.style.left = `${percent}%`;
        this.currentTimeDisplay.textContent = this.formatTime(this.audio.currentTime);
    }
    
    onMetadataLoaded() {
        this.totalTimeDisplay.textContent = this.formatTime(this.audio.duration);
    }
    
    onEnded() {
        // TODO: Load next file or next book in queue
        this.saveProgress();
        this.playPauseBtn.querySelector('i').className = 'fas fa-play';
    }
    
    onError(e) {
        console.error('Audio error:', e);
        alert('Error playing audio file');
    }
    
    formatTime(seconds) {
        if (isNaN(seconds)) return '0:00:00';
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    
    loadPlayerState() {
        // Load from localStorage
        const savedState = localStorage.getItem('audiobook-player-state');
        if (savedState) {
            const state = JSON.parse(savedState);
            if (state.bookId) {
                this.loadBook(state.bookId);
            }
        }
    }
    
    savePlayerState() {
        if (this.currentBook) {
            localStorage.setItem('audiobook-player-state', JSON.stringify({
                bookId: this.currentBook._id,
            }));
        }
    }
    
    close() {
        this.audio.pause();
        this.saveProgress();
        this.player.style.display = 'none';
        localStorage.removeItem('audiobook-player-state');
        
        if (this.progressSaveInterval) {
            clearInterval(this.progressSaveInterval);
        }
    }
}

// Initialize player when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.audiobookPlayer = new AudiobookPlayer();
});

// Function to open player from book pages
function playAudiobook(bookId) {
    window.audiobookPlayer.loadBook(bookId);
}
```

---

## Phase 3: Integration Points

### 3.1 Add Play Button to Book Pages

**File**: `resources/views/books/show.blade.php`

```blade
<button onclick="playAudiobook('{{ $book->_id }}')" class="btn btn-primary">
    <i class="fas fa-play"></i> Play Audiobook
</button>
```

### 3.2 Add Player to Layout

**File**: `resources/views/layouts/app.blade.php`

```blade
@auth
    @include('components.audiobook-player')
@endauth
```

---

## Phase 4: Advanced Features (Future)

### 4.1 Chapter Extraction
- Extract chapters from M4B metadata using getID3
- Parse metadata.abs for chapter information
- Display chapter list with timestamps
- Navigate to chapters

### 4.2 Bookmark System
- Add bookmark at current position
- Add notes to bookmarks
- List all bookmarks for a book
- Jump to bookmarks

### 4.3 Queue Management
- Add books to "Up Next" queue
- Reorder queue
- Auto-play next book
- Save queue state

### 4.4 Cross-Device Sync
- Real-time progress sync via WebSocket (optional)
- Conflict resolution based on last_played_at
- Sync across multiple devices

### 4.5 Offline Support (PWA)
- Service worker for caching
- Download books for offline playback
- Sync progress when back online

---

## Testing Plan

### Unit Tests
- Progress tracking CRUD operations
- Bookmark CRUD operations
- Queue management
- Time formatting functions

### Integration Tests
- Audio streaming with range requests
- Progress save/load flow
- Player state persistence
- Keyboard shortcuts

### Manual Testing
- Test on different browsers (Chrome, Firefox, Safari)
- Test on mobile devices
- Test with different audio formats (M4B, MP3, M4A)
- Test with multi-file books
- Test sleep timer functionality
- Test playback speed changes
- Test seeking/skipping

---

## Implementation Order

1. ✅ Database migrations (progress, bookmarks, queue)
2. ✅ AudioStreamController with range support
3. ✅ AudioProgressController API
4. ✅ Basic player UI component
5. ✅ Core player JavaScript (play/pause, seek, progress)
6. ✅ Progress auto-save
7. ✅ Playback speed control
8. ✅ Volume control
9. ✅ Keyboard shortcuts
10. ✅ Sleep timer
11. ⏳ Chapter support
12. ⏳ Bookmark system
13. ⏳ Queue management
14. ⏳ Cross-device sync
15. ⏳ PWA/Offline support

---

## Notes

- Use HTML5 Audio API for maximum compatibility
- HTTP Range requests essential for seeking in large files
- Save progress frequently (every 10 seconds) to prevent data loss
- Use localStorage for player state persistence
- Consider bandwidth throttling for streaming
- Implement proper error handling and user feedback
- Follow accessibility guidelines (ARIA labels, keyboard navigation)
- Test with various network conditions
- Consider implementing audio visualization (optional)
- Add analytics for playback statistics (optional)
