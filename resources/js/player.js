/**
 * Player functionality for the audiobook player
 * Handles audio playback, progress tracking, and state persistence
 */

class AudiobookPlayer {
    constructor() {
        this.audio = document.getElementById('audioPlayer');
        this.playPauseBtn = document.getElementById('playPause');
        this.playIcon = document.getElementById('playIcon');
        this.progressBar = document.getElementById('progressBar');
        this.progressFill = document.getElementById('progressFill');
        this.currentTimeDisplay = document.getElementById('currentTime');
        this.durationDisplay = document.getElementById('duration');
        this.playbackRateSlider = document.getElementById('playbackRate');
        this.rateDisplay = document.getElementById('rateDisplay');
        this.volumeSlider = document.getElementById('volume');
        this.volumeDisplay = document.getElementById('volumeDisplay');
        this.rewind30Btn = document.getElementById('rewind30');
        this.rewind10Btn = document.getElementById('rewind10');
        this.forward10Btn = document.getElementById('forward10');
        this.forward30Btn = document.getElementById('forward30');
        this.fileSelector = document.getElementById('fileSelector');

        this.bookId = document.body.dataset.bookId;
        this.userId = document.body.dataset.userId;
        this.bookTitle = document.body.dataset.bookTitle;
        this.bookAuthor = document.body.dataset.bookAuthor;
        this.bookCover = document.body.dataset.bookCover;

        this.init();
    }

    init() {
        this.loadState();
        this.setupEventListeners();
        this.updateGlanceWidget();
    }

    loadState() {
        // Load saved progress
        const savedProgress = parseFloat(document.body.dataset.savedProgress) || 0;
        if (savedProgress > 0) {
            this.audio.currentTime = savedProgress;
        }

        // Load playback rate
        const savedRate = localStorage.getItem('playbackRate') || '1.0';
        this.audio.playbackRate = parseFloat(savedRate);
        if (this.playbackRateSlider) {
            this.playbackRateSlider.value = savedRate;
            this.rateDisplay.textContent = savedRate + 'x';
        }

        // Load volume
        const savedVolume = localStorage.getItem('volume') || '100';
        this.audio.volume = parseInt(savedVolume) / 100;
        if (this.volumeSlider) {
            this.volumeSlider.value = savedVolume;
            this.volumeDisplay.textContent = savedVolume + '%';
        }
    }

    setupEventListeners() {
        // Play/Pause
        this.playPauseBtn?.addEventListener('click', () => this.togglePlayPause());
        this.audio.addEventListener('play', () => this.onPlay());
        this.audio.addEventListener('pause', () => this.onPause());

        // Progress
        this.audio.addEventListener('timeupdate', () => this.onTimeUpdate());
        this.audio.addEventListener('loadedmetadata', () => this.updateDuration());
        this.progressBar?.addEventListener('click', (e) => this.seek(e));

        // Playback controls
        this.playbackRateSlider?.addEventListener('input', () => this.onPlaybackRateChange());
        this.volumeSlider?.addEventListener('input', () => this.onVolumeChange());
        this.rewind30Btn?.addEventListener('click', () => this.skip(-30));
        this.rewind10Btn?.addEventListener('click', () => this.skip(-10));
        this.forward10Btn?.addEventListener('click', () => this.skip(10));
        this.forward30Btn?.addEventListener('click', () => this.skip(30));

        // File selector
        this.fileSelector?.addEventListener('change', () => this.onFileChange());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.onKeyDown(e));

        // Save state on unload
        window.addEventListener('beforeunload', () => this.saveState());
    }

    togglePlayPause() {
        if (this.audio.paused) {
            this.audio.play();
        } else {
            this.audio.pause();
        }
    }

    onPlay() {
        this.playIcon.className = 'bi bi-pause-fill';
        this.updateGlanceWidget();
    }

    onPause() {
        this.playIcon.className = 'bi bi-play-fill';
        this.saveProgress();
        this.updateGlanceWidget();
    }

    onTimeUpdate() {
        const progress = (this.audio.currentTime / this.audio.duration) * 100;
        this.progressFill.style.width = progress + '%';
        this.currentTimeDisplay.textContent = this.formatTime(this.audio.currentTime);

        // Auto-save progress periodically
        if (this.userId && Math.floor(this.audio.currentTime) % 10 === 0) {
            this.saveProgress();
        }

        this.updateGlanceWidget();
    }

    updateDuration() {
        this.durationDisplay.textContent = this.formatTime(this.audio.duration);
        this.updateGlanceWidget();
    }

    seek(e) {
        const rect = this.progressBar.getBoundingClientRect();
        const percent = (e.clientX - rect.left) / rect.width;
        this.audio.currentTime = percent * this.audio.duration;
        this.saveProgress();
    }

    onPlaybackRateChange() {
        const rate = this.playbackRateSlider.value;
        this.audio.playbackRate = rate;
        this.rateDisplay.textContent = rate + 'x';
        localStorage.setItem('playbackRate', rate);
        this.updateGlanceWidget();
    }

    onVolumeChange() {
        const volume = this.volumeSlider.value;
        this.audio.volume = volume / 100;
        this.volumeDisplay.textContent = volume + '%';
        localStorage.setItem('volume', volume);
    }

    skip(seconds) {
        this.audio.currentTime = Math.max(0, Math.min(this.audio.duration, this.audio.currentTime + seconds));
        this.saveProgress();
    }

    onFileChange() {
        const selectedOption = this.fileSelector.options[this.fileSelector.selectedIndex];
        const url = selectedOption.dataset.url;
        const currentTime = this.audio.currentTime;
        const wasPlaying = !this.audio.paused;

        this.audio.src = url;
        this.audio.load();
        this.audio.currentTime = currentTime;

        if (wasPlaying) {
            this.audio.play();
        }
    }

    onKeyDown(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        switch(e.key) {
            case ' ':
                e.preventDefault();
                this.togglePlayPause();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                this.skip(-10);
                break;
            case 'ArrowRight':
                e.preventDefault();
                this.skip(10);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.volumeSlider.value = Math.min(100, parseInt(this.volumeSlider.value) + 10);
                this.volumeSlider.dispatchEvent(new Event('input'));
                break;
            case 'ArrowDown':
                e.preventDefault();
                this.volumeSlider.value = Math.max(0, parseInt(this.volumeSlider.value) - 10);
                this.volumeSlider.dispatchEvent(new Event('input'));
                break;
        }
    }

    formatTime(seconds) {
        if (isNaN(seconds)) return '0:00:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }

    async saveProgress() {
        if (!this.userId) return;

        try {
            const response = await fetch(`/api/v1/books/${this.bookId}/progress`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Authorization': 'Bearer ' + localStorage.getItem('api_token')
                },
                body: JSON.stringify({
                    currentTime: this.audio.currentTime,
                    duration: this.audio.duration,
                    completed: this.audio.currentTime >= this.audio.duration - 10
                })
            });

            if (!response.ok) {
                throw new Error('Failed to save progress');
            }

            this.updateGlanceWidget();
        } catch (error) {
            console.error('Failed to save progress:', error);
        }
    }

    updateGlanceWidget() {
        const nowPlaying = {
            bookId: this.bookId,
            title: this.bookTitle,
            author: this.bookAuthor,
            cover: this.bookCover,
            currentTime: this.audio.currentTime,
            duration: this.audio.duration,
            isPlaying: !this.audio.paused,
            playbackRate: this.audio.playbackRate,
            lastUpdated: new Date().toISOString()
        };

        // Save to localStorage for the glance widget
        localStorage.setItem('nowPlaying', JSON.stringify(nowPlaying));

        // Also save to server if user is logged in
        if (this.userId) {
            this.saveNowPlayingToServer(nowPlaying);
        }
    }

    async saveNowPlayingToServer(nowPlaying) {
        try {
            const response = await fetch('/api/v1/user/preferences/now-playing', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Authorization': 'Bearer ' + localStorage.getItem('api_token')
                },
                body: JSON.stringify(nowPlaying)
            });

            if (!response.ok) {
                throw new Error('Failed to update now playing');
            }
        } catch (error) {
            console.error('Failed to update now playing:', error);
        }
    }

    saveState() {
        this.saveProgress();
        this.updateGlanceWidget();
    }
}

// Initialize player when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('audioPlayer')) {
        window.player = new AudiobookPlayer();
    }
});
