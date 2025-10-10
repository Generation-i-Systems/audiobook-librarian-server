# Web Audio Player

## Overview

The web audio player provides a modern, browser-based interface for listening to audiobooks directly from the web application without downloading.

## Features

### Playback Controls
- **Play/Pause** - Start or pause playback
- **Skip Backward** - Jump back 10 or 30 seconds
- **Skip Forward** - Jump forward 10 or 30 seconds
- **Seek** - Click on progress bar to jump to any position
- **Variable Speed** - Adjust playback speed from 0.5x to 2.0x
- **Volume Control** - Adjust volume from 0% to 100%

### Progress Tracking
- **Auto-Save** - Progress is automatically saved every 10 seconds
- **Resume** - Automatically resumes from last position
- **Cross-Device** - Progress syncs across devices via API
- **Visual Feedback** - Progress bar shows current position

### User Experience
- **Keyboard Shortcuts**:
  - `Space` - Play/Pause
  - `←` - Skip back 10 seconds
  - `→` - Skip forward 10 seconds
  - `↑` - Increase volume
  - `↓` - Decrease volume
- **Persistent Settings** - Playback speed and volume saved to localStorage
- **Multiple Files** - Support for books with multiple audio files
- **Responsive Design** - Works on desktop, tablet, and mobile

## Usage

### Accessing the Player

1. Navigate to any book's detail page
2. Click the green "Play" button
3. The player will load with the book's audio

**URL Pattern:**
```
/books/{book_id}/play
```

### Player Interface

```
┌─────────────────────────────────────────────┐
│  Cover Image    Book Title                  │
│                 Author, Narrator, Duration   │
├─────────────────────────────────────────────┤
│  ═══════════════════════════════════════    │ Progress Bar
│  0:00:00                         12:34:56    │ Time Display
│                                              │
│  [◄◄30] [◄10]  [▶/❚❚]  [10►] [30►►]       │ Playback Controls
│                                              │
│  Speed: 1.0x  ━━━━━━━━━━━━━━━━━━━━━━━━━    │ Speed Control
│  Volume: 100% ━━━━━━━━━━━━━━━━━━━━━━━━━    │ Volume Control
│                                              │
│  Audio File: [Select File ▼]                │ File Selector
└─────────────────────────────────────────────┘
```

## Technical Details

### Architecture

**Controller:** `app/Http/Controllers/PlayerController.php`
- Loads book data from DocumentStore
- Finds audio files in book directory
- Retrieves user progress
- Renders player view

**View:** `resources/views/player/show.blade.php`
- HTML5 `<audio>` element for playback
- JavaScript for controls and progress tracking
- Bootstrap for responsive UI
- Bootstrap Icons for control buttons

**API Integration:**
- Uses existing `/api/v1/books/{book}/download/{file}` for streaming
- Uses existing `/api/v1/books/{book}/progress` for progress tracking
- Supports HTTP range requests for seeking

### Audio Streaming

The player uses the existing API streaming endpoints which support:
- **HTTP Range Requests** - For seeking and resuming
- **Chunked Transfer** - For efficient streaming
- **Multiple Formats** - M4B, M4A, MP3
- **Large Files** - Handles multi-GB audiobooks

### Progress Tracking

Progress is tracked at multiple levels:
1. **Client-Side** - Updates every frame for smooth UI
2. **Auto-Save** - Saves to server every 10 seconds
3. **On Exit** - Saves when user leaves page
4. **Resume** - Loads saved position on player load

### Browser Compatibility

Tested and working on:
- ✅ Chrome/Edge (Chromium)
- ✅ Firefox
- ✅ Safari
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

**Requirements:**
- HTML5 audio support
- JavaScript enabled
- localStorage support (for settings)

## Configuration

### Environment Variables

Uses existing configuration:
- `BOOK_STORAGE_PATH` - Path to audiobook files

### Permissions

- **Authentication Required** - Must be logged in to use player
- **Book Access** - User must have access to the book
- **API Token** - Uses session authentication

## Development

### Adding Features

**Example: Add bookmark button**

1. Add button to view:
```blade
<button class="btn btn-outline-primary" id="addBookmark">
    <i class="bi bi-bookmark-plus"></i> Bookmark
</button>
```

2. Add JavaScript handler:
```javascript
document.getElementById('addBookmark').addEventListener('click', function() {
    const currentTime = audio.currentTime;
    // Call bookmark API
    fetch(`/api/v1/books/${bookId}/bookmarks`, {
        method: 'POST',
        body: JSON.stringify({ time: currentTime })
    });
});
```

### Testing

**Manual Testing:**
1. Load player with a book
2. Test all controls
3. Verify progress saves
4. Test keyboard shortcuts
5. Test on mobile device

**Automated Testing:**
```bash
# Test controller
php artisan test --filter PlayerControllerTest

# Test routes
php artisan route:list | grep play
```

## Troubleshooting

### Player Won't Load

**Symptoms:** Player page shows error or redirects

**Causes:**
- Book not found in database
- No audio files in book directory
- Incorrect permissions

**Solutions:**
1. Verify book exists: `php artisan books:list`
2. Check file path: `ls -la /path/to/book/directory`
3. Verify permissions: `ls -ld /path/to/book/directory`

### Audio Won't Play

**Symptoms:** Play button doesn't work or audio stops

**Causes:**
- File format not supported by browser
- File path incorrect
- API authentication failed

**Solutions:**
1. Check browser console for errors
2. Verify file exists and is readable
3. Check API logs for authentication errors
4. Try different browser

### Progress Not Saving

**Symptoms:** Player doesn't resume from last position

**Causes:**
- API token missing or expired
- Progress API endpoint not working
- localStorage disabled

**Solutions:**
1. Check browser console for API errors
2. Verify user is authenticated
3. Test progress API directly
4. Enable localStorage in browser

### Seeking Not Working

**Symptoms:** Clicking progress bar doesn't seek

**Causes:**
- Server doesn't support range requests
- File too large
- Network issues

**Solutions:**
1. Verify server supports HTTP range requests
2. Check server logs for errors
3. Test with smaller file

## Future Enhancements

Potential improvements:
- **Chapters** - Display and navigate by chapters
- **Bookmarks** - Visual bookmarks on progress bar
- **Sleep Timer** - Auto-pause after specified time
- **Equalizer** - Audio equalization controls
- **Playback Queue** - Queue multiple books
- **Offline Mode** - Service worker for offline playback
- **Chromecast** - Cast to TV or speakers
- **Lyrics/Transcript** - Display synchronized text
- **Speed Presets** - Quick speed selection buttons
- **A-B Repeat** - Loop between two points

## API Endpoints Used

### Get Book Data
```
GET /api/v1/books/{book}
```

### Stream Audio File
```
GET /api/v1/books/{book}/download/{file}
Headers: Range: bytes=0-
```

### Save Progress
```
PUT /api/v1/books/{book}/progress
Body: {
  "currentTime": 1234,
  "duration": 45678,
  "completed": false
}
```

### Get Progress
```
GET /api/v1/books/{book}/progress
Response: {
  "currentTime": 1234,
  "duration": 45678,
  "completed": false
}
```

## Security

### Authentication
- Session-based authentication required
- CSRF token validation on progress updates
- API token for cross-device sync

### Authorization
- Users can only access books they have permission for
- Admin users have access to all books

### File Access
- Files served through API controller
- Path traversal protection
- File type validation

## Performance

### Optimization
- **Lazy Loading** - Audio loaded on demand
- **Chunked Streaming** - Efficient bandwidth usage
- **Progress Throttling** - Saves every 10 seconds, not every second
- **LocalStorage** - Settings cached client-side

### Bandwidth
- Typical audiobook: 50-100 MB/hour
- Streaming uses same bandwidth as download
- Seeking doesn't re-download entire file

## Accessibility

### Keyboard Navigation
- All controls accessible via keyboard
- Standard media key support
- Focus indicators visible

### Screen Readers
- ARIA labels on all controls
- Semantic HTML structure
- Status announcements

### Mobile
- Touch-friendly controls
- Responsive layout
- Works in portrait and landscape
