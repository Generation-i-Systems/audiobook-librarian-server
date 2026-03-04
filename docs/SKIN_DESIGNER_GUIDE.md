# Librarian Player - Skin Designer Guide

## ⭐ What's New: Automatic Layout System

Skin creation just got **much easier**! New features include:

- **Flexible positioning**: Use `"auto"` to center, `"max"` for full width/height, `"max-20"` for margins
- **Automatic layout**: Containers can auto-space children horizontally or vertically
- **No more calculations**: Just put buttons in a container with `layoutDirection` and they space themselves!

See [Positioning and Anchoring](#positioning-and-anchoring) and [Container](#6-container--enhanced) sections for details.

---

## Quick Start

Creating a custom skin for Librarian Player is straightforward:

1. Create a folder for your skin
2. Add a `manifest.json` file with your layout
3. Optionally add custom images/fonts to an `assets` folder
4. Create a `preview.png` (512x512) showing your skin
5. ZIP everything up and import into Librarian

**That's it!** No coding required - skins are pure JSON + assets.

---

## Table of Contents

1. [Understanding the Basics](#understanding-the-basics)
2. [Skin Package Structure](#skin-package-structure)
3. [Manifest Format](#manifest-format)
4. [Element Types](#element-types)
5. [Positioning and Anchoring](#positioning-and-anchoring) ⭐ NEW: Flexible values & auto-layout
6. [Gradient Backgrounds](#gradient-backgrounds)
7. [Themes and Colors](#themes-and-colors)
8. [Actions and Gestures](#actions-and-gestures)
9. [Data Bindings](#data-bindings)
10. [Creating Your First Skin](#creating-your-first-skin)
11. [Advanced Techniques](#advanced-techniques)
12. [Testing and Debugging](#testing-and-debugging)
13. [Best Practices](#best-practices)

**📚 See also**: [Skin Layout System Guide](SKIN_LAYOUT_GUIDE.md) for detailed examples and migration guide

---

## Understanding the Basics

### What is a Skin?

A **skin** is a complete visual layout for the Librarian audiobook player. It defines:

- Where elements appear on screen (buttons, text, progress bar, etc.)
- How elements look (fonts, sizes, colors)
- What actions happen when you tap, swipe, or press elements

### What is a Theme?

A **theme** is a color scheme that can be applied to any skin. Themes override the skin's default
colors, allowing users to customize the look without changing the layout.

### Key Concepts

- **Elements**: Building blocks of your skin (buttons, text, images, etc.)
- **Anchoring**: Where elements are positioned from (top-left, bottom-center, etc.)
- **Data Binding**: Connecting elements to live data (book title, current time, etc.)
- **Actions**: What happens when users interact with elements
- **Gestures**: Touch interactions (tap, swipe, long-press)

---

## Skin Package Structure

Your skin is a **ZIP file** with this structure:

```
my-awesome-skin.zip
├── manifest.json              # REQUIRED: Your skin definition
├── preview.png                # REQUIRED: 512x512 preview image
├── assets/                    # OPTIONAL: Custom resources
│   ├── images/
│   │   ├── play-button.png
│   │   ├── pause-button.png
│   │   └── background.png
│   └── fonts/
│       └── custom-font.ttf
└── README.md                  # OPTIONAL: Credits/info
```

### File Requirements

**REQUIRED**:

- `manifest.json` - Your skin definition (root level)
- `preview.png` - Preview image, 512x512 pixels (root level)

**OPTIONAL**:

- `assets/images/` - Custom button images, backgrounds, etc.
- `assets/fonts/` - Custom fonts (.ttf or .otf)
- `README.md` - Credits, changelog, license info

### File Limits

- **Total ZIP size**: 50 MB max
- **Individual images**: 5 MB max
- **Individual fonts**: 2 MB max
- **Supported image formats**: PNG, JPG, WebP
- **Supported font formats**: TTF, OTF

---

## Manifest Format

The `manifest.json` is the heart of your skin. Here's a minimal example:

```json
{
  "version": "1.0",
  "skinName": "My First Skin",
  "author": "Your Name",
  "description": "A simple custom skin",
  "minPlayerVersion": "2.0.0",
  "previewImage": "preview.png",
  "dimensions": {
    "portrait": {
      "width": 360,
      "height": 640,
      "aspectRatio": "free"
    },
    "landscape": {
      "width": 640,
      "height": 360,
      "aspectRatio": "free"
    }
  },
  "theme": {
    "allowColorOverride": true,
    "embeddedThemes": {
      "default": {
        "version": "1.0",
        "themeName": "My Skin Default",
        "author": "Your Name",
        "description": "Default theme for my skin",
        "colors": {
          "primary": "#2196F3",
          "background": "#121212",
          "surface": "#1E1E1E",
          "text": "#FFFFFF",
          "accent": "#FF4081",
          "progressActive": "#2196F3",
          "progressInactive": "#424242",
          "timeText": "#FFFFFF",
          "buttonTint": "#2196F3"
        }
      }
    },
    "defaultTheme": "default"
  },
  "layout": {
    "portrait": [
      // Your elements go here
    ],
    "landscape": [
      // Your landscape elements go here
    ]
  }
}
```

### Root Properties

| Property           | Type   | Required | Description                               |
|--------------------|--------|----------|-------------------------------------------|
| `version`          | string | ✅        | Manifest version (use "1.0")              |
| `skinName`         | string | ✅        | Display name of your skin                 |
| `author`           | string | ⬜        | Your name/username                        |
| `description`      | string | ⬜        | Brief description                         |
| `minPlayerVersion` | string | ⬜        | Min app version (default: "2.0.0")        |
| `previewImage`     | string | ⬜        | Preview filename (default: "preview.png") |
| `dimensions`       | object | ✅        | Canvas dimensions for layouts             |
| `theme`            | object | ✅        | Theme configuration                       |
| `layout`           | object | ✅        | Element layouts                           |
| `fonts`            | array  | ⬜        | Custom font definitions                   |
| `animations`       | object | ⬜        | Animation definitions                     |

### Dimensions

Define your design canvas size. The app will scale your skin to fit any screen:

```json
"dimensions": {
"portrait": {
"width": 360, // Design width in pixels
"height": 640, // Design height in pixels
"aspectRatio": "free"
},
"landscape": {
"width": 640,
"height": 360,
"aspectRatio": "free"
},
"drivePortrait": { // Optional - drive mode portrait dimensions
"width": 360,
"height": 800,
"aspectRatio": "free"
},
"driveLandscape": { // Optional - drive mode landscape dimensions
"width": 800,
"height": 360,
"aspectRatio": "free"
}
}
```

**Tip**: Use 360x640 for portrait - it's a common baseline and scales well.

### ⚠️ IMPORTANT: Landscape Layout Requirement

**A skin without a functional landscape layout is considered NON-FUNCTIONAL.**

All skins MUST provide both portrait AND landscape layouts. Users expect to be able to rotate their device and have a usable player interface in both orientations.

**Minimum Landscape Requirements:**
- ✅ Cover image element (visible and appropriately sized)
- ✅ Book title and author text
- ✅ Playback progress bar (interactive)
- ✅ Essential playback controls (at minimum: play/pause, skip backward/forward)
- ✅ Current time and total time display

**Recommended Landscape Layout Pattern:**
- **Cover on left side** (typically 40-50% of screen width)
- **Controls on right side** with:
  - Title/author text at top
  - Progress bars and time displays
  - Playback buttons arranged horizontally

**Example:**
```json
"landscape": [
  {
    "id": "cover",
    "type": "cover-image",
    "x": 20,
    "y": 20,
    "width": 320,
    "height": 320,
    "anchor": "top-left"
  },
  {
    "id": "title-text",
    "type": "text",
    "x": 360,
    "y": 20,
    "width": 420,
    "height": 30,
    "anchor": "top-left",
    "dataBinding": "book.title"
  }
  // ... more elements
]
```

An empty landscape layout (`"landscape": []`) will cause the skin to fail validation and provide a poor user experience.

### Drive Mode Layouts (Optional)

Drive mode provides a simplified, distraction-reduced interface with large touch targets for use while driving. Skins can optionally define **two additional layout keys**: `drivePortrait` and `driveLandscape`.

If a skin does not include drive mode layouts, the app falls back to the regular portrait/landscape layouts.

**Drive Mode Design Principles:**
- **Large buttons** (80–100px) for easy tapping without looking
- **Minimal controls** — typically just play/pause and skip forward/backward
- **No progress bar** — reduces visual complexity
- **Must include an exit button** — uses the `exit-drive-mode` action so the user can return to normal mode
- **High contrast** — ensure text and buttons are clearly visible

**Drive Mode Layout Keys:**

| Layout Key        | Description                                     |
|-------------------|-------------------------------------------------|
| `drivePortrait`   | Drive mode layout for portrait orientation      |
| `driveLandscape`  | Drive mode layout for landscape orientation     |

Both keys are **optional**. You can provide one or both. Their dimensions should match the corresponding portrait/landscape dimensions.

**Example — Drive Portrait Layout:**

```json
"drivePortrait": [
  {
    "id": "background",
    "type": "image",
    "x": 0, "y": 0,
    "width": 360, "height": 800,
    "anchor": "top-left",
    "image": "assets/images/background.png",
    "themeable": { "tint": "background" }
  },
  {
    "id": "exit-button",
    "type": "button",
    "x": 20, "y": 40,
    "width": 60, "height": 60,
    "anchor": "top-left",
    "action": "exit-drive-mode",
    "iconStyle": "filled",
    "buttonShape": "circle",
    "themeable": {
      "tint": "surface",
      "backgroundColor": "error"
    }
  },
  {
    "id": "cover",
    "type": "cover-image",
    "x": 0, "y": 120,
    "width": 280, "height": 280,
    "anchor": "top-center",
    "visible": true,
    "themeable": {
      "borderColor": "primary",
      "backgroundColor": "surface"
    }
  },
  {
    "id": "title-text",
    "type": "text",
    "x": 0, "y": 420,
    "width": 340, "height": 40,
    "anchor": "top-center",
    "dataBinding": "book.title",
    "fontSize": 24,
    "fontWeight": "bold",
    "textAlign": "center",
    "themeable": { "color": "text" },
    "scrolling": { "enabled": true, "speed": 30 }
  },
  {
    "id": "controls-container",
    "type": "container",
    "x": 0, "y": 550,
    "width": 360, "height": 120,
    "anchor": "top-left",
    "layoutDirection": "horizontal",
    "padding": 10,
    "children": [
      {
        "id": "btn-back-30",
        "type": "button",
        "width": 80, "height": 80,
        "action": "skip-backward-30",
        "image": "assets/images/button_back_30s.png",
        "themeable": { "tint": "primary" },
        "x": "line", "y": "center"
      },
      {
        "id": "btn-play-pause",
        "type": "button",
        "width": 100, "height": 100,
        "action": "toggle-play-pause",
        "images": {
          "playing": "assets/images/button_pause.png",
          "paused": "assets/images/button_play.png"
        },
        "themeable": {
          "tint": "background",
          "backgroundColor": "primary"
        },
        "buttonShape": "circle",
        "x": "line", "y": "center"
      },
      {
        "id": "btn-fwd-30",
        "type": "button",
        "width": 80, "height": 80,
        "action": "skip-forward-30",
        "image": "assets/images/button_forward_30s.png",
        "themeable": { "tint": "primary" },
        "x": "line", "y": "center"
      }
    ]
  }
]
```

**Example — Drive Landscape Layout:**

```json
"driveLandscape": [
  {
    "id": "background",
    "type": "image",
    "x": 0, "y": 0,
    "width": 800, "height": 360,
    "anchor": "top-left",
    "image": "assets/images/background.png",
    "themeable": { "tint": "background" }
  },
  {
    "id": "exit-button",
    "type": "button",
    "x": 20, "y": 20,
    "width": 60, "height": 60,
    "anchor": "top-left",
    "action": "exit-drive-mode",
    "iconStyle": "filled",
    "buttonShape": "circle",
    "themeable": {
      "tint": "surface",
      "backgroundColor": "error"
    }
  },
  {
    "id": "cover",
    "type": "cover-image",
    "x": 100, "y": 20,
    "width": 200, "height": 200,
    "anchor": "top-left",
    "visible": true,
    "themeable": {
      "borderColor": "primary",
      "backgroundColor": "surface"
    }
  },
  {
    "id": "info-container",
    "type": "container",
    "x": 320, "y": 20,
    "width": 460, "height": 320,
    "anchor": "top-left",
    "layoutDirection": "vertical",
    "padding": 10,
    "children": [
      {
        "id": "title-text",
        "type": "text",
        "width": 440, "height": 40,
        "dataBinding": "book.title",
        "fontSize": 24,
        "fontWeight": "bold",
        "themeable": { "color": "text" },
        "scrolling": { "enabled": true },
        "x": "center", "y": "line"
      },
      {
        "id": "author-text",
        "type": "text",
        "width": 440, "height": 30,
        "dataBinding": "book.author",
        "fontSize": 18,
        "themeable": { "color": "text" },
        "x": "center", "y": "line"
      },
      {
        "id": "controls-row",
        "type": "container",
        "width": 440, "height": 120,
        "layoutDirection": "horizontal",
        "children": [
          {
            "id": "btn-back-30",
            "type": "button",
            "width": 80, "height": 80,
            "action": "skip-backward-30",
            "image": "assets/images/button_back_30s.png",
            "themeable": { "tint": "primary" },
            "x": "line", "y": "center"
          },
          {
            "id": "btn-play-pause",
            "type": "button",
            "width": 100, "height": 100,
            "action": "toggle-play-pause",
            "images": {
              "playing": "assets/images/button_pause.png",
              "paused": "assets/images/button_play.png"
            },
            "themeable": {
              "tint": "background",
              "backgroundColor": "primary"
            },
            "buttonShape": "circle",
            "x": "line", "y": "center"
          },
          {
            "id": "btn-fwd-30",
            "type": "button",
            "width": 80, "height": 80,
            "action": "skip-forward-30",
            "image": "assets/images/button_forward_30s.png",
            "themeable": { "tint": "primary" },
            "x": "line", "y": "center"
          }
        ],
        "x": "center", "y": "line"
      }
    ]
  }
]
```

**Key differences from regular layouts:**
- The `exit-drive-mode` button is **required** so users can leave drive mode
- Use **larger font sizes** (18–24px) for better readability
- Keep the number of interactive elements to **3–5** at most
- Avoid progress bars and detailed time displays

---

## Element Types

### 1. Cover Image

Displays the audiobook cover art.

```json
{
  "id": "cover",
  "type": "cover-image",
  "x": 20,
  "y": 20,
  "width": 320,
  "height": 320,
  "anchor": "top-left",
  "visible": true,
  "themeable": {
    "borderColor": "primary"
  },
  "gestures": {
    "tap": "toggle-play-pause",
    "longPress": "show-chapters",
    "swipeLeft": "next-chapter",
    "swipeRight": "prev-chapter"
  }
}
```

**Properties**:

- `themeable.borderColor` - Border color (theme color key)
- `themeable.backgroundColor` - Background color
- `customImage` - Use a static image instead of book cover
- `gestures` - Touch interactions. When `tap` is set to `"toggle-play-pause"`, the player also shows a large, low-opacity play/pause icon over the cover for about 2 seconds after the tap, then hides it automatically.

---

### 2. Text

Displays dynamic text (book title, time, etc.).

```json
{
  "id": "title",
  "type": "text",
  "x": 20,
  "y": 360,
  "width": 320,
  "height": 40,
  "anchor": "top-left",
  "dataBinding": "book.title",
  "fontSize": 20,
  "fontWeight": "bold",
  "fontFamily": "serif",
  "textAlign": "center",
  "themeable": {
    "color": "text"
  },
  "scrolling": {
    "enabled": true,
    "speed": 30,
    "pauseDuration": 2000
  }
}
```

**Properties**:

- `dataBinding` - What data to display (see [Data Bindings](#data-bindings))
- `fontSize` - Text size in pixels
- `fontWeight` - Font weight (see [Font Weights](#font-weights))
- `fontFamily` - Font family (see [Font Families](#font-families))
- `textAlign` - "left", "center", or "right"
- `themeable.color` - Text color (theme color key)
- `scrolling.enabled` - Scroll long text (marquee)
- `scrolling.speed` - Scroll speed (pixels per second)
- `scrolling.pauseDuration` - Pause at ends (milliseconds)

**Font Families**:
Cross-platform standard fonts (work on Android, iOS, and Desktop):

- `"serif"` - Serif font (Times, Georgia-style)
- `"sans-serif"` - Sans-serif font (Roboto, SF Pro, Arial-style)
- `"monospace"` - Monospace font (Courier, Menlo, Consolas-style)
- `"cursive"` - Cursive/handwriting font
- `"default"` - System default font

Named fonts (automatically mapped to platform equivalents):

- `"roboto"` - Maps to Roboto (Android), San Francisco (iOS), Arial (Desktop)
- `"arial"`, `"helvetica"` - Maps to platform sans-serif
- `"times"`, `"georgia"` - Maps to platform serif
- `"courier"`, `"menlo"`, `"consolas"` - Maps to platform monospace

Custom fonts from your skin bundle:

- `"custom:my-font.ttf"` - Font file in `assets/fonts/my-font.ttf`

Google Fonts (downloaded automatically):

- `"google:Poppins"` - Google Font name (future feature)

**Font Weights**:

- `"thin"` or `"100"` - Thinnest weight
- `"extra-light"` or `"200"`
- `"light"` or `"300"`
- `"normal"` or `"400"` - Default
- `"medium"` or `"500"`
- `"semi-bold"` or `"600"`
- `"bold"` or `"700"` - Standard bold
- `"extra-bold"` or `"800"`
- `"black"` or `"900"` - Heaviest weight

**Cross-Platform Note**: All standard font families automatically map to the best available font on
each platform, ensuring your skin looks great everywhere!

---

### 3. Button

Interactive button for playback control.

```json
{
  "id": "play-button",
  "type": "button",
  "x": 140,
  "y": 500,
  "width": 80,
  "height": 80,
  "anchor": "bottom-center",
  "action": "toggle-play-pause",
  "iconStyle": "rounded",
  "themeable": {
    "tint": "primary"
  },
  "gestures": {
    "tap": "toggle-play-pause",
    "longPress": "create-bookmark"
  }
}
```

**Properties**:

- `action` - Button action (see [Actions](#actions-and-gestures))
- `iconStyle` - Icon variant: "filled", "outlined", "rounded", "sharp"
- `buttonShape` - Background shape: "circle", "rounded", "square", "none" (optional)
- `buttonPadding` - Padding inside button in dp (optional, default: 0)
- `themeable.tint` - Icon tint color (theme color key)
- `themeable.backgroundColor` - Button background color (theme color key, optional)
- `images.default` - Custom button image (optional)
- `gestures` - Override default tap behavior

**Built-in Icons**: The app provides Material Icons for common actions. Set `action` to get the
right icon automatically:

- `toggle-play-pause` → Play/Pause icon (changes based on state)
- `prev-chapter` → Skip Previous icon
- `next-chapter` → Skip Next icon
- `skip-backward-30` → Replay 30 icon
- `skip-forward-30` → Forward 30 icon
- `create-bookmark` → Bookmark icon

**Button Shapes Example**:

```json
{
  "id": "play-button",
  "type": "button",
  "x": 140,
  "y": 500,
  "width": 80,
  "height": 80,
  "action": "toggle-play-pause",
  "buttonShape": "circle",
  // Circular background
  "buttonPadding": 10,
  // 10dp padding inside
  "themeable": {
    "tint": "primary",
    // Icon color
    "backgroundColor": "surface"
    // Background color
  }
}
```

**Tip**: Use `buttonShape: "none"` or omit `backgroundColor` for transparent buttons without
backgrounds.

---

### 4. Progress Bar

Shows and controls playback position.

```json
{
  "id": "progress",
  "type": "progress-bar",
  "x": 20,
  "y": 450,
  "width": 320,
  "height": 20,
  "anchor": "top-left",
  "dataBinding": "playback.position",
  "interactive": true,
  "themeable": {
    "activeColor": "progressActive",
    "inactiveColor": "progressInactive",
    "thumbColor": "primary"
  }
}
```

**Properties**:

- `dataBinding` - Must be "playback.position" (0.0 to 1.0)
- `interactive` - Allow seeking by tapping/dragging
- `themeable.activeColor` - Filled portion color
- `themeable.inactiveColor` - Unfilled portion color
- `themeable.thumbColor` - Draggable thumb color

---

### 5. Image

Static decorative image with advanced background modes, gradient support, and decorative positioning.

```json
{
  "id": "background",
  "type": "image",
  "x": 0,
  "y": 0,
  "width": 360,
  "height": 640,
  "anchor": "top-left",
  "customImage": "assets/images/background.png",
  "backgroundMode": "stretch",
  "backgroundColor": "#121212",
  "themeable": {
    "tint": "surface"
  },
  "decorativeImages": [
    {
      "path": "assets/images/corner-decoration.png",
      "position": "top-right",
      "offsetX": -10,
      "offsetY": 10,
      "width": 50,
      "height": 50,
      "alpha": 0.8
    }
  ]
}
```

**Properties**:

- `customImage` - Path to main image file in your ZIP
- `backgroundMode` - How image fills space (see below)
- `backgroundColor` - Hex color for padding areas (solid color)
- `backgroundGradient` - Gradient background (see [Gradient Backgrounds](#gradient-backgrounds))
- `themeable.tint` - Optional color tint overlay
- `decorativeImages` - Array of positioned decorative images

**Note**: `backgroundGradient` overrides `backgroundColor` if both are specified.

**Background Modes**:

- `"stretch"` - Stretch to fill entire area (may distort)
- `"fit-width"` - Scale to fit width, maintain aspect ratio
- `"fit-height"` - Scale to fit height, maintain aspect ratio
- `"center"` - Center image at original size
- `"tile"` - Tile image to fill area (both directions)
- `"tile-horizontal"` - Tile horizontally, fit vertically
- `"tile-vertical"` - Tile vertically, fit horizontally

**Decorative Images**:
Decorative images are additional images positioned relative to the element:

```json
{
  "path": "assets/images/star.png",
  "position": "top-right",
  // Anchor point
  "offsetX": -20,
  // Pixels from anchor (negative = left/up)
  "offsetY": 10,
  // Pixels from anchor (positive = right/down)
  "width": 40,
  // Optional fixed width
  "height": 40,
  // Optional fixed height
  "tint": "accent",
  // Optional theme color tint
  "alpha": 0.9
  // Opacity (0.0 to 1.0)
}
```

**Position Values**:

- `"top"`, `"bottom"`, `"left"`, `"right"`
- `"top-left"`, `"top-right"`, `"bottom-left"`, `"bottom-right"`
- `"center"`

---

### 6. Container ⭐ ENHANCED

A container element groups multiple child elements together with **automatic layout** or relative positioning. Containers now support automatic horizontal/vertical spacing, making button layouts trivial!

#### Automatic Line Layout (NEW!)

The easiest way to position buttons - just set `layoutDirection` and children space themselves automatically:

```json
{
  "id": "button-row",
  "type": "container",
  "x": 0,
  "y": 500,
  "width": "max",
  "height": 80,
  "layoutDirection": "horizontal",
  "gap": 15,
  "padding": 20,
  "backgroundColor": "#1a1a1a80",
  "children": [
    {
      "id": "btn-back",
      "type": "button",
      "x": "line",
      "y": "auto",
      "width": 60,
      "height": 60,
      "action": "skip-backward-30"
    },
    {
      "id": "btn-play",
      "type": "button",
      "x": "line",
      "y": "auto",
      "width": 60,
      "height": 60,
      "action": "toggle-play-pause"
    },
    {
      "id": "btn-forward",
      "type": "button",
      "x": "line",
      "y": "auto",
      "width": 60,
      "height": 60,
      "action": "skip-forward-30"
    }
  ]
}
```

**Result**: Three buttons automatically spaced horizontally with 15px gaps, 20px padding, and vertically centered!

#### Manual Positioning (Traditional)

You can still manually position children if you prefer:

```json
{
  "id": "control-panel",
  "type": "container",
  "x": 0,
  "y": 400,
  "width": 360,
  "height": 240,
  "backgroundColor": "#1a1a1a",
  "children": [
    {
      "id": "play-button",
      "type": "button",
      "x": 160,
      "y": 100,
      "width": 60,
      "height": 60,
      "action": "toggle-play-pause"
    }
  ]
}
```

**Properties**:

- `children` - Array of child elements (positioned relative to container)
- `layoutDirection` - ⭐ NEW: `"horizontal"` or `"vertical"` for automatic spacing
- `gap` - ⭐ NEW: Space between items in line layout (default: 0)
- `padding` - ⭐ NEW: Padding inside container (default: 0)
- `backgroundColor` - Solid color background (hex format)
- `backgroundGradient` - Gradient background (see [Gradient Backgrounds](#gradient-backgrounds))
- `customImage` - Background image path
- `backgroundMode` - How background image fills space

**Key Features**:

- **Automatic layout** ⭐ NEW - Set `layoutDirection` and children space themselves
- **Flexible positioning** ⭐ NEW - Use `"line"` for auto-spacing, `"auto"` for centering
- **Relative positioning** - Child elements use `x`, `y` relative to container's top-left corner
- **Nested containers** - Containers can contain other containers
- **Background support** - Containers can have solid colors, gradients, or images
- **Modular layouts** - Group related elements for easier management

**Layout Direction Examples**:

Horizontal layout (buttons in a row):
```json
{
  "layoutDirection": "horizontal",
  "gap": 10,
  "children": [
    {"id": "btn1", "x": "line", "y": "auto", "width": 60, "height": 60},
    {"id": "btn2", "x": "line", "y": "auto", "width": 60, "height": 60}
  ]
}
```

Vertical layout (buttons in a column):
```json
{
  "layoutDirection": "vertical",
  "gap": 10,
  "children": [
    {"id": "btn1", "x": "auto", "y": "line", "width": 60, "height": 60},
    {"id": "btn2", "x": "auto", "y": "line", "width": 60, "height": 60}
  ]
}
```

**Example Use Cases**:

- ⭐ NEW: Automatic button rows/columns with perfect spacing
- Group playback controls into a control panel
- Create themed sections with distinct backgrounds
- Build reusable component groups
- Stack elements vertically with consistent gaps

---

### 7. Rectangle

A simple filled rectangle for backgrounds, separators, and overlays. Supports solid colors and gradients.

```json
{
  "type": "rectangle",
  "id": "separator",
  "x": 0,
  "y": 300,
  "width": 360,
  "height": 2,
  "backgroundColor": "#CCCCCC"
}
```

**With gradient**:

```json
{
  "type": "rectangle",
  "id": "background-overlay",
  "x": 0,
  "y": 0,
  "width": 360,
  "height": 640,
  "backgroundGradient": {
    "type": "linear",
    "colors": ["#00000000", "#000000AA"],
    "angle": 180
  }
}
```

**Properties**:

- `backgroundColor` - Solid color fill (hex format)
- `backgroundGradient` - Gradient fill (see [Gradient Backgrounds](#gradient-backgrounds))

**Note**: `backgroundGradient` overrides `backgroundColor` if both are specified.

**Example Use Cases**:

- Visual separators between sections
- Gradient overlays for improved text readability
- Decorative background elements
- Semi-transparent overlays

---

## Positioning and Anchoring

### Coordinate System

Elements can be positioned using **flexible values** for easier layout:

```json
{
  "x": 20,           // Numeric: 20 pixels from anchor point
  "y": "auto",       // Auto: automatically centered
  "width": "max",    // Max: full container width
  "height": 40       // Numeric: 40 pixels tall
}
```

**Important**: The app automatically scales your skin to fit any screen size. Design at your
comfortable size (like 360x640) and it will scale perfectly.

### Flexible Position Values (x, y) ⭐ NEW

Instead of only numeric values, you can now use:

| Value | Description | Example |
|-------|-------------|---------|
| `100` | Numeric pixel position | `"x": 100` |
| `"auto"` | Automatically centers the element | `"x": "auto"` |
| `"line"` | Used in containers for automatic spacing | `"x": "line"` |
| `"max"` | Sets to maximum dimension | `"x": "max"` |
| `"max-20"` | Maximum minus N pixels | `"x": "max-20"` |

### Flexible Size Values (width, height) ⭐ NEW

| Value | Description | Example |
|-------|-------------|---------|
| `200` | Numeric pixel size | `"width": 200` |
| `"auto"` | Full container size | `"width": "auto"` |
| `"max"` | Maximum dimension | `"width": "max"` |
| `"max-40"` | Maximum minus N pixels | `"width": "max-40"` |

**Examples:**

```json
// Centered cover image
{
  "id": "cover",
  "type": "cover-image",
  "x": "auto",
  "y": "auto",
  "width": 300,
  "height": 300
}

// Full-width background
{
  "id": "background",
  "type": "image",
  "x": 0,
  "y": 0,
  "width": "max",
  "height": "max"
}

// Progress bar with margins
{
  "id": "progress",
  "type": "progress-bar",
  "x": 20,
  "y": "max-60",
  "width": "max-40",
  "height": 6
}
```

### Anchor Points

The `anchor` property determines where an element is positioned from:

#### Top Anchors (default)

- `"top-left"` - Position from top-left corner
- `"top-center"` - Position from top-center (x is relative to center)
- `"top-right"` - Position from top-right corner

#### Center Anchors

- `"center-left"` - Position from vertical center, left edge
- `"center"` - Position from center of screen
- `"center-right"` - Position from vertical center, right edge

#### Bottom Anchors ⭐ NEW

- `"bottom-left"` - Position from bottom-left corner
- `"bottom-center"` - Position from bottom-center
- `"bottom-right"` - Position from bottom-right corner

### Bottom Anchoring Explained

**Bottom anchors** are crucial for responsive layouts. When using bottom anchors, the `y` value is
measured **from the bottom of the screen upward**.

**Example**: Position a button 100 pixels from the bottom:

```json
{
  "id": "play-button",
  "type": "button",
  "x": 140,
  "y": 100,
  // 100 pixels FROM BOTTOM
  "width": 80,
  "height": 80,
  "anchor": "bottom-center"
  // Anchor to bottom
}
```

This ensures controls stay at the bottom regardless of screen height!

### Positioning Tips

**✅ DO**:

- Use `bottom-*` anchors for navigation/control buttons
- Use `top-*` anchors for titles, cover art
- Center important elements with `center` or `*-center` anchors

**❌ DON'T**:

- Use top anchors for bottom controls (they'll float in the middle on tall screens)
- Use bottom anchors for title text (it'll be upside down!)
- Hardcode positions assuming a specific screen size

---

## Gradient Backgrounds

All background-capable elements (containers, rectangles, and images) support gradient backgrounds with extensive customization options.

### Gradient Types

#### Linear Gradients

Straight-line gradients from one point to another.

```json
{
  "backgroundGradient": {
    "type": "linear",
    "colors": ["#FF0000", "#0000FF"],
    "angle": 90
  }
}
```

**Angle guide** — `0°` (default) flows colors[0] → colors[last] top-to-bottom; rotating clockwise shifts the direction:
- `0°` = Top → Bottom (↓) — *default*
- `90°` = Left → Right (→)
- `180°` = Bottom → Top (↑) — reverses color order visually
- `270°` = Right → Left (←)

#### Radial Gradients

Circular gradients radiating from a center point.

```json
{
  "backgroundGradient": {
    "type": "radial",
    "colors": ["#FFFFFF", "#000000"],
    "centerX": 0.5,
    "centerY": 0.5,
    "radius": 0.7
  }
}
```

**Properties:**
- `centerX` - Center X position (0.0 to 1.0, 0.5 = middle)
- `centerY` - Center Y position (0.0 to 1.0, 0.5 = middle)
- `radius` - Gradient radius (0.0 to 1.0, relative to smallest dimension)

#### Sweep Gradients

Circular gradients that sweep around a center point like a color wheel.

```json
{
  "backgroundGradient": {
    "type": "sweep",
    "colors": ["#FF0000", "#00FF00", "#0000FF", "#FF0000"],
    "centerX": 0.5,
    "centerY": 0.5
  }
}
```

### Cover Art Color Token

Use `"cover"` as a color in any gradient to dynamically use the dominant color extracted from the current book's cover art. This enables backgrounds that automatically match the album art.

```json
{
  "backgroundGradient": {
    "type": "linear",
    "angle": 180,
    "colors": ["cover", "#000000"]
  }
}
```

This creates a gradient from the cover's dominant color at the top to black at the bottom. For a light skin, use `"#FFFFFF"` as the end color instead.

**Other examples with `"cover"`:**

```json
// Left-to-right (landscape split)
{ "type": "linear", "angle": 90, "colors": ["cover", "#000000"] }

// Radial burst from cover color
{ "type": "radial", "colors": ["cover", "#000000"], "radius": 0.8 }

// Soft vignette (cover color in center)
{ "type": "radial", "colors": ["cover", "#00000099"], "centerX": 0.5, "centerY": 0.3 }
```

> **Note**: The `"cover"` token resolves to `Color.Transparent` if no cover art is loaded, so the gradient gracefully falls back to the remaining colors.

### Color Stops

Control exact positioning of colors in gradients:

```json
{
  "backgroundGradient": {
    "type": "linear",
    "colors": ["#FF0000", "#00FF00", "#0000FF"],
    "stops": [0.0, 0.3, 1.0],
    "angle": 90
  }
}
```

**Without `stops`**, colors are evenly distributed (0.0, 0.5, 1.0 for 3 colors).
**With `stops`**, you control exact positions (0.0 = start, 1.0 = end).

### Complete Gradient Reference

All gradient properties:

```json
{
  "backgroundGradient": {
    "type": "linear | radial | sweep",
    "colors": ["#RRGGBB", "#RRGGBB", ...],  // REQUIRED
    "stops": [0.0, 0.5, 1.0],                // OPTIONAL
    "angle": 0.0,                             // For linear gradients
    "centerX": 0.5,                           // For radial/sweep gradients
    "centerY": 0.5,                           // For radial/sweep gradients
    "radius": 0.5                             // For radial gradients
  }
}
```

| Property  | Type     | Required | Default | Description                                          |
|-----------|----------|----------|---------|------------------------------------------------------|
| `type`    | string   | No       | `"linear"` | Gradient type: `"linear"`, `"radial"`, or `"sweep"` |
| `colors`  | string[] | Yes      | -       | Array of hex colors or `"cover"` (e.g., `["cover", "#000000"]`) |
| `stops`   | float[]  | No       | Even    | Color stop positions (0.0-1.0). Must match `colors` length if provided |
| `angle`   | float    | No       | `0`     | Direction: 0=top→bottom, 90=left→right, 180=bottom→top, 270=right→left |
| `centerX` | float    | No       | `0.5`   | Center X position for radial/sweep (0.0-1.0)        |
| `centerY` | float    | No       | `0.5`   | Center Y position for radial/sweep (0.0-1.0)        |
| `radius`  | float    | No       | `0.5`   | Radius for radial gradients (0.0-1.0)               |

### Gradient Examples

**Sunset gradient:**

```json
{
  "type": "rectangle",
  "id": "sunset-bg",
  "x": 0,
  "y": 0,
  "width": 360,
  "height": 640,
  "backgroundGradient": {
    "type": "linear",
    "colors": ["#FF6B6B", "#FFD93D", "#6BCF7F", "#4D96FF"],
    "stops": [0.0, 0.3, 0.6, 1.0],
    "angle": 180
  }
}
```

**Circular spotlight:**

```json
{
  "type": "rectangle",
  "id": "spotlight",
  "x": 0,
  "y": 0,
  "width": 360,
  "height": 640,
  "backgroundGradient": {
    "type": "radial",
    "colors": ["#FFFFFFAA", "#00000000"],
    "centerX": 0.5,
    "centerY": 0.3,
    "radius": 0.4
  }
}
```

**Color wheel effect:**

```json
{
  "type": "rectangle",
  "id": "color-wheel",
  "x": 80,
  "y": 200,
  "width": 200,
  "height": 200,
  "backgroundGradient": {
    "type": "sweep",
    "colors": [
      "#FF0000", "#FFFF00", "#00FF00",
      "#00FFFF", "#0000FF", "#FF00FF", "#FF0000"
    ],
    "centerX": 0.5,
    "centerY": 0.5
  }
}
```

**Subtle fade overlay:**

```json
{
  "type": "rectangle",
  "id": "fade-overlay",
  "x": 0,
  "y": 400,
  "width": 360,
  "height": 240,
  "backgroundGradient": {
    "type": "linear",
    "colors": ["#00000000", "#000000DD"],
    "angle": 180
  }
}
```

---

## Themes and Colors

### Theme Structure

Themes define color palettes that can be applied to any skin:

```json
"theme": {
"allowColorOverride": true,
"embeddedThemes": {
"dark": {
"version": "1.0",
"themeName": "Dark Mode",
"author": "Your Name",
"description": "Dark color scheme",
"colors": {
"primary": "#2196F3",
"background": "#121212",
"surface": "#1E1E1E",
"text": "#FFFFFF",
"accent": "#FF4081",
"progressActive": "#2196F3",
"progressInactive": "#424242",
"timeText": "#B3E5FC",
"buttonTint": "#2196F3"
}
},
"light": {
"version": "1.0",
"themeName": "Light Mode",
"author": "Your Name",
"description": "Light color scheme",
"colors": {
"primary": "#1976D2",
"background": "#FFFFFF",
"surface": "#F5F5F5",
"text": "#212121",
"accent": "#D81B60",
"progressActive": "#1976D2",
"progressInactive": "#E0E0E0",
"timeText": "#424242",
"buttonTint": "#1976D2"
}
}
},
"defaultTheme": "dark"
}
```

### Standard Color Keys

Use these standard keys for consistency:

| Key                | Purpose                | Example   |
|--------------------|------------------------|-----------|
| `primary`          | Main brand color       | `#2196F3` |
| `background`       | Screen background      | `#121212` |
| `surface`          | Card/panel backgrounds | `#1E1E1E` |
| `text`             | Primary text color     | `#FFFFFF` |
| `accent`           | Highlight color        | `#FF4081` |
| `progressActive`   | Filled progress bar    | `#2196F3` |
| `progressInactive` | Empty progress bar     | `#424242` |
| `timeText`         | Time display text      | `#B3E5FC` |
| `buttonTint`       | Button icon tint       | `#2196F3` |

**You can add custom keys** for your specific elements:

```json
"colors": {
"primary": "#2196F3",
"titleText": "#FFD700",
"authorText": "#C0C0C0",
"bookmarkIcon": "#FF4081"
}
```

Then reference them in elements:

```json
{
  "id": "author",
  "type": "text",
  "dataBinding": "book.author",
  "themeable": {
    "color": "authorText"
    // Uses your custom color
  }
}
```

### Color Formats

Colors must be in **hex format**:

- 6-digit: `#RRGGBB` (e.g., `#2196F3`)
- 8-digit with alpha: `#RRGGBBAA` (e.g., `#2196F380` for 50% opacity)

**❌ NOT SUPPORTED**:

- Named colors (`"red"`, `"blue"`)
- RGB/RGBA functions (`rgb(33, 150, 243)`)

### Embedded Themes

You can bundle multiple themes with your skin:

```json
"theme": {
"allowColorOverride": true,
"embeddedThemes": {
"neon": {/* neon theme colors */},
"pastel": {/* pastel theme colors */},
"monochrome": {
/* monochrome theme colors */
}
},
"defaultTheme": "neon"
}
```

Users can switch between your embedded themes or apply their own custom themes.

### Allow Color Override

```json
"allowColorOverride": true  // Users can apply their own themes
"allowColorOverride": false // Lock to your embedded themes only
```

**Recommendation**: Always set to `true` unless your skin relies on specific colors to look correct.

---

## Actions and Gestures

### Actions

Actions are player functions that buttons and gestures can trigger:

#### Playback Control

- `toggle-play-pause` - Toggle between play and pause
- `play` - Start playback
- `pause` - Pause playback

#### Chapter Navigation

- `next-chapter` - Skip to next chapter
- `prev-chapter` - Go to previous chapter

#### Time Skipping

**Standard Skip Times** (with dedicated icons):

- `skip-forward-5` - Skip forward 5 seconds
- `skip-backward-5` - Skip backward 5 seconds
- `skip-forward-10` - Skip forward 10 seconds
- `skip-backward-10` - Skip backward 10 seconds
- `skip-forward-30` - Skip forward 30 seconds
- `skip-backward-30` - Skip backward 30 seconds
- `skip-forward-60` - Skip forward 1 minute
- `skip-backward-60` - Skip backward 1 minute
- `skip-forward-600` - Skip forward 10 minutes
- `skip-backward-600` - Skip backward 10 minutes

**Custom Skip Times** (any number of seconds):

- `skip-forward:{seconds}` - Skip forward custom time (e.g., `skip-forward:22`)
- `skip-backward:{seconds}` - Skip backward custom time (e.g., `skip-backward:77`)

**Examples**:

```json
{
  "id": "skip-22s",
  "type": "button",
  "action": "skip-forward:22",
  // Custom 22 second skip
  "themeable": {
    "tint": "primary"
  }
}
```

```json
{
  "id": "skip-7s",
  "type": "button",
  "action": "skip-backward:7",
  // Custom 7 second rewind
  "themeable": {
    "tint": "primary"
  }
}
```

**Note**: Custom skip times use generic fast-forward/fast-rewind icons. Standard times (5, 10, 30)
have dedicated numbered icons.

#### Dialog/Menu Actions

- `show-chapters` - Open chapter list
- `open-chapters` - Alias of `show-chapters` (used by some skins)
- `show-bookmarks` - Open bookmarks list
- `open-bookmarks` - Alias of `show-bookmarks` (used by some skins)
- `show-speed-selector` - Open playback speed selector
- `open-speed` - Alias of `show-speed-selector` (used by some skins)
- `show-sleep-timer` - Open sleep timer
- `create-bookmark` - Create bookmark at current position
- `show-history` - Show listening history

#### Drive Mode

- `exit-drive-mode` - Exit drive mode and return to the normal player interface. This action should be placed on a clearly visible button (typically a circle with an "X" icon) in drive mode layouts.

#### High-Speed Seeking (5x)

These actions enable high-speed seeking while the user holds a button (typically via `longPress` on a skip button):

- `start-5x-forward` - Begin 5x-speed forward seek
- `stop-5x-forward` - Stop the 5x-speed forward seek
- `start-5x-backward` - Begin 5x-speed backward seek
- `stop-5x-backward` - Stop the 5x-speed backward seek

**Recommended usage:**

- Bind `start-5x-forward` as a `longPress` gesture on a forward skip button (for example, `skip-forward-30`).
- Bind `start-5x-backward` as a `longPress` gesture on a backward skip button (for example, `skip-backward-30`).
- The player automatically sends the matching `stop-…` action when the long press is released, so you normally only need to reference the `start-…` actions in your manifest.

### Gestures

Gestures define touch interactions:

```json
"gestures": {
"tap": "toggle-play-pause",
"doubleTap": "skip-forward-30",
"longPress": "create-bookmark",
"swipeLeft": "next-chapter",
"swipeRight": "prev-chapter",
"swipeUp": "show-chapters",
"swipeDown": "show-sleep-timer"
}
```

**Supported Gestures**:

- `tap` - Single quick tap
- `doubleTap` - Two quick taps
- `longPress` - Press and hold (>500ms)
- `swipeLeft` - Swipe left
- `swipeRight` - Swipe right
- `swipeUp` - Swipe up
- `swipeDown` - Swipe down

**Where to Use**:

- **Buttons**: Add extra functionality via long-press
- **Cover Image**: Make cover interactive (swipe to skip, tap to play)
- **Progress Bar**: Built-in tap/drag (don't override)
- **Text Elements**: Less common, but possible

---

## Text Format Tokens

Use `textFormat` on `text` elements to combine multiple values into a single display string.
Tokens are wrapped in `{curly braces}`:

```json
{
  "id": "time-remaining-label",
  "type": "text",
  "textFormat": "{chapterTimeRemainingHuman} ({speed})"
}
```

### Available Tokens

| Token | Example | Description |
|-------|---------|-------------|
| `{currentTime}` | "2:15:42" | Full book current time |
| `{totalTime}` | "10:53:07" | Full book total time |
| `{timeRemaining}` | "8:37:25" | Full book time remaining (speed-adjusted, H:MM:SS) |
| `{timeRemainingHuman}` | "8h 37m 25s" | Full book time remaining (speed-adjusted, human-readable) |
| `{timeRemainingLabel}` | "left" | Localized label (e.g. "left") |
| `{speed}` | "1.5x" | Current playback speed |
| `{chapterTitle}` | "Chapter 3" | Current chapter name |
| `{chapterCurrentTime}` | "0:12:30" | Chapter current time |
| `{chapterTotalTime}` | "0:45:00" | Chapter total duration |
| `{chapterTimeRemaining}` | "0:32:30" | Chapter time remaining (speed-adjusted, M:SS) |
| `{chapterTimeRemainingHuman}` | "32m 30s" | Chapter time remaining (speed-adjusted, human-readable) |
| `{bookTitle}` | "The Martian" | Book title |
| `{bookAuthor}` | "Andy Weir" | Book author |

> **Tip**: Use `{chapterTimeRemainingHuman}` instead of `{chapterTimeRemaining}` for a more readable display like "32m 30s" instead of "0:32:30".

---

## Data Bindings

Data bindings connect elements to live player data. Use these in the `dataBinding` property of text
elements:

### Book Information

```json
"dataBinding": "book.title"     // "The Martian"
"dataBinding": "book.author"    // "Andy Weir"
```

### Playback Time

```json
"dataBinding": "playback.currentTime"  // "2:15:42"
"dataBinding": "playback.totalTime"    // "10:53:07"
```

### Playback Position

```json
"dataBinding": "playback.position"  // 0.0 to 1.0 (for progress bars)
```

### Playback State

```json
"dataBinding": "playback.state"  // "PLAYING", "PAUSED", or "LOADING"
```

### Playback Speed

```json
"dataBinding": "playback.speed"  // "1.0x", "1.5x", etc.
```

### Chapter Information

```json
"dataBinding": "chapter.current"       // "Chapter 3: The Storm"
"dataBinding": "chapter.index"         // "3"
"dataBinding": "chapter.currentTime"   // "0:12:30"
"dataBinding": "chapter.totalTime"     // "0:45:00"
"dataBinding": "chapter.timeRemaining" // "0:32:30"
"dataBinding": "chapter.position"      // 0.0 to 1.0 (for progress bars)
```

### Example: Complete Time Display

```json
{
  "id": "time-current",
  "type": "text",
  "x": 20,
  "y": 480,
  "width": 100,
  "height": 30,
  "dataBinding": "playback.currentTime",
  "fontSize": 16,
  "textAlign": "left",
  "themeable": {
    "color": "timeText"
  }
},
{
"id": "time-separator",
"type": "text",
"x": 160,
"y": 480,
"width": 40,
"height": 30,
"dataBinding": null, // Static text, no binding
"fontSize": 16,
"textAlign": "center"
// Note: Static text not yet implemented, placeholder for future
},
{
"id": "time-total",
"type": "text",
"x": 240,
"y": 480,
"width": 100,
"height": 30,
"dataBinding": "playback.totalTime",
"fontSize": 16,
"textAlign": "right",
"themeable": {
"color": "timeText"
}
}
```

---

## Creating Your First Skin

### Step-by-Step Tutorial

Let's create a simple skin called "Minimal Player":

#### 1. Create Folder Structure

```
minimal-player/
├── manifest.json
└── preview.png
```

#### 2. Create manifest.json

```json
{
  "version": "1.0",
  "skinName": "Minimal Player",
  "author": "Your Name",
  "description": "Clean and simple layout",
  "minPlayerVersion": "2.0.0",
  "previewImage": "preview.png",
  "dimensions": {
    "portrait": {
      "width": 360,
      "height": 640,
      "aspectRatio": "free"
    },
    "landscape": {
      "width": 640,
      "height": 360,
      "aspectRatio": "free"
    }
  },
  "theme": {
    "allowColorOverride": true,
    "embeddedThemes": {
      "minimal": {
        "version": "1.0",
        "themeName": "Minimal",
        "author": "Your Name",
        "description": "Clean minimal theme",
        "colors": {
          "primary": "#6200EE",
          "background": "#FFFFFF",
          "surface": "#F5F5F5",
          "text": "#000000",
          "accent": "#03DAC6",
          "progressActive": "#6200EE",
          "progressInactive": "#E0E0E0",
          "timeText": "#666666",
          "buttonTint": "#6200EE"
        }
      }
    },
    "defaultTheme": "minimal"
  },
  "layout": {
    "portrait": [
      {
        "id": "cover",
        "type": "cover-image",
        "x": 30,
        "y": 80,
        "width": 300,
        "height": 300,
        "anchor": "top-left",
        "themeable": {
          "borderColor": "primary"
        }
      },
      {
        "id": "title",
        "type": "text",
        "x": 30,
        "y": 400,
        "width": 300,
        "height": 50,
        "anchor": "top-left",
        "dataBinding": "book.title",
        "fontSize": 24,
        "fontWeight": "bold",
        "textAlign": "center",
        "themeable": {
          "color": "text"
        }
      },
      {
        "id": "author",
        "type": "text",
        "x": 30,
        "y": 455,
        "width": 300,
        "height": 30,
        "anchor": "top-left",
        "dataBinding": "book.author",
        "fontSize": 18,
        "textAlign": "center",
        "themeable": {
          "color": "text"
        }
      },
      {
        "id": "progress",
        "type": "progress-bar",
        "x": 30,
        "y": 200,
        "width": 300,
        "height": 10,
        "anchor": "bottom-left",
        "dataBinding": "playback.position",
        "interactive": true,
        "themeable": {
          "activeColor": "progressActive",
          "inactiveColor": "progressInactive"
        }
      },
      {
        "id": "time-current",
        "type": "text",
        "x": 30,
        "y": 165,
        "width": 100,
        "height": 25,
        "anchor": "bottom-left",
        "dataBinding": "playback.currentTime",
        "fontSize": 14,
        "textAlign": "left",
        "themeable": {
          "color": "timeText"
        }
      },
      {
        "id": "time-total",
        "type": "text",
        "x": 230,
        "y": 165,
        "width": 100,
        "height": 25,
        "anchor": "bottom-left",
        "dataBinding": "playback.totalTime",
        "fontSize": 14,
        "textAlign": "right",
        "themeable": {
          "color": "timeText"
        }
      },
      {
        "id": "btn-prev",
        "type": "button",
        "x": 60,
        "y": 100,
        "width": 60,
        "height": 60,
        "anchor": "bottom-left",
        "action": "prev-chapter",
        "iconStyle": "rounded",
        "themeable": {
          "tint": "primary"
        }
      },
      {
        "id": "btn-play",
        "type": "button",
        "x": 150,
        "y": 100,
        "width": 80,
        "height": 80,
        "anchor": "bottom-left",
        "action": "toggle-play-pause",
        "iconStyle": "rounded",
        "themeable": {
          "tint": "primary"
        }
      },
      {
        "id": "btn-next",
        "type": "button",
        "x": 240,
        "y": 100,
        "width": 60,
        "height": 60,
        "anchor": "bottom-left",
        "action": "next-chapter",
        "iconStyle": "rounded",
        "themeable": {
          "tint": "primary"
        }
      }
    ],
    "landscape": []
  }
}
```

#### 3. Create Preview Image

Create a 512x512 PNG showing what your skin looks like. You can:

- Take a screenshot of your skin in the app
- Create a mockup in a design tool
- Use the app's built-in preview generator (coming soon)

#### 4. ZIP It Up

Select all files in your `minimal-player` folder and create a ZIP:

- `manifest.json`
- `preview.png`

Name it `minimal-player.zip`

#### 5. Import to Librarian

1. Open Librarian app
2. Go to Player 2.0
3. Tap menu → "Change Skin"
4. Tap "Import Skin"
5. Select your `minimal-player.zip`
6. Done! Your skin is now available

---

## Advanced Techniques

### Custom Fonts

Add custom fonts to make your skin unique:

#### 1. Add Font to ZIP

```
my-skin.zip
├── manifest.json
├── preview.png
└── assets/
    └── fonts/
        └── my-font.ttf
```

#### 2. Declare Font in Manifest

```json
"fonts": [
{
"id": "my-font.ttf",
"path": "assets/fonts/my-font.ttf",
"family": "MyCustomFont"
}
]
```

#### 3. Use Font in Elements

```json
{
  "id": "title",
  "type": "text",
  "fontFamily": "custom:my-font.ttf",
  // Reference by id
  "dataBinding": "book.title"
}
```

**Note**: Custom font loading is planned but not yet implemented. Currently use built-in families: "
serif", "sans-serif", "monospace", "cursive".

### Custom Button Images

Replace default icons with your own images:

#### 1. Add Images to ZIP

```
my-skin.zip
├── manifest.json
├── preview.png
└── assets/
    └── images/
        ├── play.png
        └── pause.png
```

#### 2. Reference in Button

```json
{
  "id": "btn-play",
  "type": "button",
  "action": "toggle-play-pause",
  "images": {
    "paused": "assets/images/play.png",    // Show play icon when paused
    "playing": "assets/images/pause.png"   // Show pause icon when playing
  }
}
```

**Note**: Custom images override the built-in Material Icons.

**State-Specific Images Explained:**

For `toggle-play-pause` buttons, the system automatically switches images based on playback state:

| Playback State | Image Key Used | Typical Image | Why? |
|----------------|----------------|---------------|------|
| **Playing** | `"playing"` | Pause button | User needs to pause |
| **Paused** | `"paused"` | Play button | User needs to play |

**Example:**
```json
{
  "action": "toggle-play-pause",
  "images": {
    "paused": "assets/images/play.png",    // ← Shows when audio is PAUSED
    "playing": "assets/images/pause.png"   // ← Shows when audio is PLAYING
  }
}
```

**Without Custom Images:**

If you don't provide `images`, the system uses built-in Material Icons that automatically change:
```json
{
  "action": "toggle-play-pause",
  "iconStyle": "filled",  // Optional: "filled", "outlined", "rounded"
  "themeable": {
    "tint": "primary"     // Icon color from theme
  }
}
```

### Scrolling Text

Make long text scroll (marquee effect):

```json
{
  "id": "title",
  "type": "text",
  "dataBinding": "book.title",
  "width": 300,
  "scrolling": {
    "enabled": true,
    "speed": 30,
    // Pixels per second
    "pauseDuration": 2000
    // Pause 2 seconds at each end
  }
}
```

### Multiple Embedded Themes

Offer users variety within your skin:

```json
"embeddedThemes": {
"light": {/* light colors */},
"dark": {/* dark colors */},
"sunset": {
/* warm orange tones */
},
"ocean": {
/* cool blue tones */
}
},
"defaultTheme": "dark"
```

Users can switch themes without changing skins!

### Landscape Layout

Provide optimized landscape layout:

```json
"layout": {
"portrait": [/* portrait elements */],
"landscape": [
{
"id": "cover",
"type": "cover-image",
"x": 20,
"y": 20,
"width": 250,
"height": 250,
"anchor": "top-left"
},
{
"id": "controls",
"type": "button",
"x": 300,
"y": 100,
// ... more landscape-specific positioning
}
]
}
```

---

## Testing and Debugging

### Testing Checklist

Before sharing your skin, test:

#### Visual Testing

- ✅ Does it look good on different screen sizes?
- ✅ Are all elements visible (not cut off)?
- ✅ Do colors have good contrast?
- ✅ Does text fit without truncating?

#### Functional Testing

- ✅ Do all buttons work?
- ✅ Does the progress bar seek correctly?
- ✅ Do gestures trigger the right actions?
- ✅ Does the cover image display?

#### Orientation Testing

- ✅ Does portrait mode work?
- ✅ Does landscape mode work?
- ✅ Do elements stay anchored correctly?

#### Theme Testing

- ✅ Does your default theme look good?
- ✅ Do other themes apply correctly?
- ✅ Are all themeable colors defined?

### Skin Validator Tool

**Librarian includes a powerful validation tool** that automatically checks your skin for errors, missing files, invalid properties, and best practice violations.

#### Installation

The validator is located in the project repository:

```bash
# Download the validator
curl -O https://raw.githubusercontent.com/your-repo/librarian/main/tools/validate_skin.py

# Or clone the entire repository
git clone https://github.com/your-repo/librarian.git
```

**Requirements**: Python 3.6 or higher (no external dependencies needed)

#### Usage

```bash
# Validate a skin ZIP file
python3 validate_skin.py my-awesome-skin.zip

# Validate a skin directory
python3 validate_skin.py my-skin-folder/
```

#### What It Checks

The validator performs **50+ automated checks** across all aspects of your skin:

**File Structure**:
- ✅ Required files exist (manifest.json, preview.png)
- ✅ File sizes within limits (ZIP < 50MB, images < 5MB, fonts < 2MB)
- ✅ All referenced files exist (images, fonts)
- ✅ ZIP file integrity

**Manifest Validation**:
- ✅ Valid JSON syntax
- ✅ Required fields present
- ✅ Portrait and landscape dimensions defined
- ✅ Landscape layout is not empty
- ✅ Theme structure is correct
- ✅ All colors are valid hex format

**Element Validation**:
- ✅ Required properties (id, type, x, y, width, height)
- ✅ Unique element IDs
- ✅ Valid element types
- ✅ Elements within reasonable bounds
- ✅ Valid anchor points
- ✅ Container children validated recursively

**Property Validation**:
- ✅ Actions are recognized (including custom skip-forward:N)
- ✅ Data bindings are valid
- ✅ Gestures are valid types
- ✅ Colors are proper hex format (#RRGGBB or #RRGGBBAA)
- ✅ Gradients have correct structure (type, colors, stops)
- ✅ Font properties are valid
- ✅ Background modes are recognized

**Best Practices**:
- ✅ Button touch targets meet minimum size (48x48 dp)
- ✅ Element counts are reasonable (< 30 per layout)
- ✅ Font sizes are appropriate
- ✅ Text elements have data bindings

#### Example Output

**Valid skin**:

```
Validating skin: my-skin.zip

SUCCESSS (1):
  SUCCESS: manifest.json is valid JSON

============================================================
SUMMARY:
  Errors: 0
  Warnings: 0
  Info: 0
  Success: 1

✅ VALIDATION PASSED
Skin is valid and ready to use!
```

**Skin with issues**:

```
Validating skin: broken-skin.zip

ERRORS (3):
  ERROR: Invalid color format: 'red' (use #RRGGBB or #RRGGBBAA)
         [manifest.theme.embeddedThemes.default.colors.primary]
  ERROR: Landscape layout is empty - skins must provide functional
         landscape layouts [manifest.layout.landscape]
  ERROR: Invalid action: 'invalid-action' (see documentation for
         valid actions) [manifest.layout.portrait[2].action]

WARNINGS (1):
  WARNING: Button size (20x20) is below recommended minimum (48x48 dp)
           [manifest.layout.portrait[1]]

============================================================
SUMMARY:
  Errors: 3
  Warnings: 1
  Info: 0
  Success: 0

❌ VALIDATION FAILED
Please fix the errors above before using this skin.
```

#### Result Types

The validator categorizes findings into four levels:

- 🔴 **ERROR** - Critical issues that will prevent the skin from working correctly
- 🟡 **WARNING** - Issues that may cause problems or reduce quality
- 🔵 **INFO** - Informational messages about your skin
- 🟢 **SUCCESS** - Confirmations of correct configurations

#### When to Use the Validator

**During Development**:
```bash
# Quick check after making changes
python3 validate_skin.py my-skin/

# Before creating the final ZIP
python3 validate_skin.py my-skin.zip
```

**Before Sharing**:
- Always validate before sharing your skin with others
- Fix all errors (red messages)
- Consider addressing warnings (yellow messages)

**In Automated Workflows**:
```bash
# In a build script
python3 validate_skin.py my-skin.zip || exit 1

# In a pre-commit hook
python3 validate_skin.py skins/*.zip
```

#### Common Validation Errors and Fixes

**Error: "Invalid color format"**
```json
// ❌ Wrong
"color": "red"
"color": "rgb(255, 0, 0)"

// ✅ Correct
"color": "#FF0000"
"color": "#FF0000AA"  // With alpha
```

**Error: "Landscape layout is empty"**
```json
// ❌ Wrong
"landscape": []

// ✅ Correct - provide functional landscape layout
"landscape": [
  {
    "id": "cover",
    "type": "cover-image",
    "x": 20,
    "y": 20,
    "width": 200,
    "height": 200
  },
  // ... more landscape elements
]
```

**Error: "Invalid action"**
```json
// ❌ Wrong
"action": "playPause"
"action": "skip_30"

// ✅ Correct
"action": "toggle-play-pause"
"action": "skip-forward-30"
```

**Error: "Duplicate element id"**
```json
// ❌ Wrong - two elements with same id
[
  {"id": "button1", "type": "button", ...},
  {"id": "button1", "type": "button", ...}  // Duplicate!
]

// ✅ Correct - unique ids
[
  {"id": "button1", "type": "button", ...},
  {"id": "button2", "type": "button", ...}
]
```

**Error: "Image file not found"**
```json
// ❌ Wrong - file doesn't exist in ZIP
"customImage": "assets/background.png"  // File missing!

// ✅ Correct - ensure file exists
// Make sure assets/background.png is in your ZIP
```

**Warning: "Button size below minimum"**
```json
// ⚠️ Warning - too small for comfortable tapping
{
  "type": "button",
  "width": 20,
  "height": 20
}

// ✅ Better - meets accessibility guidelines
{
  "type": "button",
  "width": 60,
  "height": 60
}
```

**Error: "Gradient must have at least 2 colors"**
```json
// ❌ Wrong
"backgroundGradient": {
  "type": "linear",
  "colors": ["#FF0000"]  // Only 1 color!
}

// ✅ Correct
"backgroundGradient": {
  "type": "linear",
  "colors": ["#FF0000", "#0000FF"]  // 2+ colors
}
```

#### Advanced Validation

The validator also checks:

**Gradient Properties**:
```json
// Validates color stops match colors length
{
  "backgroundGradient": {
    "colors": ["#FF0000", "#00FF00", "#0000FF"],
    "stops": [0.0, 0.5, 1.0]  // ✅ Length matches
  }
}
```

**Container Children**:
```json
// Recursively validates all nested elements
{
  "type": "container",
  "children": [
    {
      "type": "button",
      "action": "play"  // ✅ Validated
    },
    {
      "type": "container",  // ✅ Nested containers validated
      "children": [...]
    }
  ]
}
```

**Custom Skip Actions**:
```json
// Validates custom skip durations
{
  "action": "skip-forward:45"  // ✅ Valid custom skip
}
```

#### Tips for Clean Validation

1. **Use the validator frequently** during development
2. **Fix errors before warnings** - errors prevent the skin from working
3. **Read the location path** - tells you exactly where the problem is
4. **Check the documentation** - if you're unsure about valid values
5. **Start simple** - validate a minimal skin first, then add complexity

#### Exit Codes

The validator returns exit codes for automation:

- `0` - Validation passed (no errors, warnings OK)
- `1` - Validation failed (errors found)

This makes it easy to integrate into build scripts:

```bash
#!/bin/bash
if python3 validate_skin.py my-skin.zip; then
  echo "Skin is valid, creating release..."
  # ... build steps
else
  echo "Skin validation failed!"
  exit 1
fi
```

### Skin Preview Tool

The **Skin Preview Tool** (`preview_skin.py`) generates realistic visual previews of your skin by rendering it with sample data. This helps you see exactly how your skin will look on a device before testing it in the app.

#### Features

- Renders both **portrait** and **landscape** orientations
- Generates a **combined preview** showing both orientations side-by-side
- Shows realistic book cover, title, author, progress, and chapter information
- Properly handles all element types (buttons, text, images, containers, gradients)
- Supports **color themes** - preview your skin with any app theme
- **Watch mode** - automatically regenerates previews when files change
- **Cross-platform** - works on Windows, macOS, and Linux

#### Installation

**Requirements**:
- Python 3.6 or higher
- Pillow (PIL) library

**Install Python and Pillow**:

On **Windows**:
1. Download Python from [python.org](https://www.python.org/downloads/)
   - During installation, check "Add Python to PATH"
2. Open Command Prompt and install Pillow:
   ```cmd
   pip install Pillow
   ```

On **macOS**:
```bash
# Python 3 is usually pre-installed
# Install Pillow
pip3 install Pillow
```

On **Linux**:
```bash
# Install Python and Pillow
sudo apt-get install python3 python3-pip
pip3 install Pillow
```

**Get the Tool**:

```bash
# Download just the preview tool
curl -O https://raw.githubusercontent.com/your-repo/librarian/main/tools/preview_skin.py

# Or clone the entire repository
git clone https://github.com/your-repo/librarian.git
```

#### Usage

**Basic Preview**:
```bash
# Preview a skin ZIP file
python3 preview_skin.py my-awesome-skin.zip

# Preview a skin directory
python3 preview_skin.py my-skin-folder/

# Preview multiple skins
python3 preview_skin.py skin1.zip skin2.zip skin3.zip
```

On Windows, use `python` instead of `python3`:
```cmd
python preview_skin.py my-awesome-skin.zip
```

**Auto-Open Preview**:
```bash
# Generate and automatically open the preview
python3 preview_skin.py my-skin.zip --open
```

**Custom Output Directory**:
```bash
# Save previews to a specific directory
python3 preview_skin.py my-skin.zip --output ./previews/
```

**Preview with Color Theme**:
```bash
# Preview with a specific app color theme
python3 preview_skin.py my-skin.zip --color-theme "Dark"
python3 preview_skin.py my-skin.zip --color-theme "Metro Blue"
python3 preview_skin.py my-skin.zip --color-theme "Forest Green"
```

**Watch Mode** (highly recommended during development):
```bash
# Automatically regenerate preview when files change
python3 preview_skin.py my-skin-folder/ --watch
```

Watch mode will:
1. Open the preview in your default image viewer
2. Monitor your skin files for changes
3. Automatically regenerate the preview when you save changes
4. On Linux, use smart viewers (pqiv, eog) that auto-reload without closing

**Custom Image Viewer**:
```bash
# Use a specific image viewer (Linux)
python3 preview_skin.py my-skin.zip --open --viewer eog

# Use a specific image viewer (Windows)
python preview_skin.py my-skin.zip --open --viewer mspaint
```

#### Generated Files

The tool generates three preview images in the output directory:

1. **`my-skin-portrait.png`** - Portrait orientation preview
2. **`my-skin-landscape.png`** - Landscape orientation preview
3. **`my-skin-combined.png`** - Side-by-side comparison of both orientations

When using `--open`, only the combined image opens to avoid overwhelming your screen.

#### Sample Output

The preview shows your skin with realistic data:

- **Book Cover**: Sample audiobook cover image
- **Title**: "The Fellowship of the Ring"
- **Author**: "J.R.R. Tolkien"
- **Progress**: 2:15:32 / 11:45:23 (19%)
- **Current Chapter**: "Chapter 4: A Short Cut to Mushrooms"
- **Playback Speed**: 1.0x
- **Sleep Timer**: 30 min remaining

All element types are rendered as they would appear in the app:
- Buttons with icons/text
- Text fields with proper fonts and colors
- Cover images
- Progress bars and time displays
- Containers with backgrounds and gradients
- Custom images and overlays

#### Button Size Scaling

The preview tool automatically scales buttons to match how they appear on real devices (approximately 27% smaller than manifest dimensions). This accounts for the difference between design dimensions and actual device rendering.

If you notice buttons appearing different sizes on your device:
1. Open `preview_skin.py` in a text editor
2. Find the `BUTTON_SCALE` constant (around line 325)
3. Adjust the value (default: 0.73)
   - Lower values make buttons smaller in preview
   - Higher values make buttons larger in preview
4. Regenerate your preview to see the change

#### Workflow Example

**Development Workflow**:

1. **Start watch mode** while editing your skin:
   ```bash
   python3 preview_skin.py my-skin/ --watch
   ```

2. **Edit your skin files** in your favorite editor

3. **Save changes** - preview automatically updates

4. **Check the preview** to see your changes instantly

5. **Iterate** until satisfied

**Before Sharing**:

1. **Generate final previews** with different themes:
   ```bash
   python3 preview_skin.py my-skin.zip --color-theme "Dark" --output ./previews/dark/
   python3 preview_skin.py my-skin.zip --color-theme "Light" --output ./previews/light/
   ```

2. **Include in your skin package** - add preview images to your skin documentation

3. **Validate** one final time:
   ```bash
   python3 validate_skin.py my-skin.zip
   ```

#### Troubleshooting

**"Pillow not found" error**:
```bash
# Install Pillow
pip install Pillow  # Windows
pip3 install Pillow  # macOS/Linux
```

**Preview looks different from device**:
- Colors may vary due to screen calibration
- Button sizes are scaled to match device rendering
- Adjust `BUTTON_SCALE` in the script if needed (see Button Size Scaling above)

**Watch mode not working**:
- Make sure you're specifying a directory, not a ZIP file
- Watch mode monitors manifest.json and asset files for changes
- On Windows, the preview window won't auto-reload - just close and reopen it

**Preview shows "Image not found"**:
- Check that all `customImage` paths exist in your skin
- Verify image files are in the correct directory
- Image paths are case-sensitive on Linux/macOS

**Windows: "python3 not found"**:
- Use `python` instead of `python3` on Windows
- Make sure Python was added to PATH during installation

### Common Issues

#### Elements Not Showing

- **Check**: Element is within canvas bounds
- **Check**: `visible: true` is set
- **Check**: Width and height are > 0

#### Colors Look Wrong

- **Check**: Color format is `#RRGGBB` or `#RRGGBBAA`
- **Check**: Theme color keys match your element's `themeable` properties
- **Check**: `allowColorOverride` is true if using external themes

#### Buttons Not Working

- **Check**: `action` is a valid action name (see [Actions](#actions-and-gestures))
- **Check**: Button size is large enough to tap (min 48x48 dp)
- **Check**: Button is not covered by another element

#### Bottom Elements Floating

- **Check**: Using `bottom-*` anchor for bottom controls
- **Check**: `y` value makes sense for bottom positioning

#### Text Truncated

- **Check**: Width is sufficient for typical content
- **Check**: Enable scrolling for long text
- **Check**: Font size isn't too large for height

### Debug Mode

_(Coming in future update)_

Enable debug mode to see:

- Element bounds (colored boxes)
- Anchor points (dots)
- Touch areas (outlines)
- Data binding values (tooltips)

---

## Best Practices

### Design Principles

**Keep It Simple**: Start with basic layouts before adding complexity.

**Test Early**: Import your skin frequently during development to catch issues.

**Use Anchors**: Always use appropriate anchors (bottom anchors for controls!).

**Responsive Design**: Remember your skin will scale to any screen size.

**Accessibility**:

- Use minimum 48x48dp touch targets
- Ensure good color contrast (4.5:1 for normal text)
- Don't rely solely on color to convey information

### Performance Tips

**Optimize Images**:

- Use PNG for images with transparency
- Use JPG for photos/gradients without transparency
- Compress images before adding to skin
- Don't use unnecessarily large images

**Limit Elements**:

- Keep element count reasonable (<30 per layout)
- Don't create invisible placeholder elements
- Reuse elements where possible

**Font Sizes**:

- Use reasonable font sizes (12-32px typically)
- Test with different system font scale settings

### Organization Tips

**Element IDs**:

- Use descriptive IDs: `"title"`, `"btn-play"`, `"time-current"`
- Use consistent naming: `btn-*` for buttons, `time-*` for time displays
- IDs must be unique within a layout

**Group Related Elements**:
Order elements logically in your manifest:

1. Background/decorative elements
2. Cover image
3. Text elements (title, author, chapter)
4. Progress bar and time displays
5. Control buttons

**Comment Your Manifest**:
While JSON doesn't support comments, use descriptive IDs and consider a separate README.md
explaining your design choices.

### Sharing Your Skin

**Include a README**:

```markdown
# My Awesome Skin

Created by: Your Name
Version: 1.0
License: CC BY 4.0

## Features

- Clean minimal design
- 3 embedded themes
- Optimized for one-handed use

## Credits

- Font: [Font Name] by [Author]
- Icons: Material Design Icons

## Changelog

### 1.0

- Initial release
```

**Preview Image**:

- Make it eye-catching
- Show the skin with actual content
- Include both light and dark theme variants if applicable

**Test on Multiple Devices**:

- Small phone (5-6")
- Large phone (6-7")
- Tablet (if possible)
- Portrait and landscape

---

## Reference Tables

### Quick Reference: Element Types

| Type           | Purpose               | Key Properties                                       |
|----------------|-----------------------|------------------------------------------------------|
| `cover-image`  | Book cover            | `gestures`, `customImage`                            |
| `text`         | Dynamic text          | `dataBinding`, `fontSize`, `fontFamily`, `scrolling` |
| `button`       | Control button        | `action`, `iconStyle`, `images`                      |
| `progress-bar` | Seek bar              | `interactive`, `themeable`                           |
| `image`        | Static image          | `customImage`, `backgroundMode`, `backgroundGradient`|
| `container`    | Group child elements  | `children`, `backgroundColor`, `backgroundGradient`  |
| `rectangle`    | Filled box            | `backgroundColor`, `backgroundGradient`              |
| `visualizer`   | Audio viz             | (not yet implemented)                                |

### Quick Reference: Anchors

| Anchor          | Position From    | X Direction | Y Direction |
|-----------------|------------------|-------------|-------------|
| `top-left`      | Top-left corner  | Right →     | Down ↓      |
| `top-center`    | Top-center       | Center ↔    | Down ↓      |
| `top-right`     | Top-right corner | ← Left      | Down ↓      |
| `center-left`   | Middle-left      | Right →     | Center ↕    |
| `center`        | Screen center    | Center ↔    | Center ↕    |
| `center-right`  | Middle-right     | ← Left      | Center ↕    |
| `bottom-left`   | Bottom-left      | Right →     | ↑ Up        |
| `bottom-center` | Bottom-center    | Center ↔    | ↑ Up        |
| `bottom-right`  | Bottom-right     | ← Left      | ↑ Up        |

### Quick Reference: Actions

| Action                | Description       | Typical Use                       |
|-----------------------|-------------------|-----------------------------------|
| `toggle-play-pause`   | Play/pause toggle | Play button                       |
| `prev-chapter`        | Previous chapter  | Skip back button                  |
| `next-chapter`        | Next chapter      | Skip forward button               |
| `skip-backward-30`    | Back 30 sec       | Rewind 30s button                 |
| `skip-forward-30`     | Forward 30 sec    | Forward 30s button                |
| `create-bookmark`     | Create bookmark   | Bookmark button, long-press       |
| `show-chapters`       | Open chapters     | Chapters button, cover long-press |
| `show-bookmarks`      | Open bookmarks    | Bookmarks button                  |
| `show-speed-selector` | Change speed      | Speed button                      |
| `show-sleep-timer`    | Sleep timer       | Timer button                      |

### Quick Reference: Data Bindings

| Binding                | Example Value   | Use For              |
|------------------------|-----------------|----------------------|
| `book.title`           | "The Martian"   | Title text           |
| `book.author`          | "Andy Weir"     | Author text          |
| `playback.currentTime` | "2:15:42"       | Current time display |
| `playback.totalTime`   | "10:53:07"      | Total time display   |
| `playback.position`    | 0.207 (0.0-1.0) | Progress bar         |
| `playback.state`       | "PLAYING"       | State indicator      |
| `playback.speed`       | "1.5x"          | Speed display        |
| `chapter.current`      | "Chapter 3"     | Chapter name         |
| `chapter.index`        | "3"             | Chapter number       |
| `chapter.currentTime`  | "0:12:30"       | Chapter current time |
| `chapter.totalTime`    | "0:45:00"       | Chapter total time   |
| `chapter.timeRemaining`| "0:32:30"       | Chapter time left    |
| `chapter.position`     | 0.278 (0.0-1.0) | Chapter progress bar |

---

## Need Help?

### Resources

- **Full Specification**: See `PLAYER_SPECIFICATION.md` - Full spec
- **Design Guidelines**: See `SKIN_DESIGNER_GUIDE.md` - Design guidelines
- **Example Skins**: Check built-in skins in the app for reference
- **Community**: Share and discuss skins at [community link]

### Feedback

Found a bug? Have a feature request?

- GitHub: [repository issues link]
- Discord: [community server]
- Email: [support email]

---

## Appendix: Complete Minimal Example

Here's a complete, minimal working skin you can use as a template:

```json
{
  "version": "1.0",
  "skinName": "Ultra Minimal",
  "author": "Librarian Team",
  "description": "Absolute bare minimum skin",
  "previewImage": "preview.png",
  "dimensions": {
    "portrait": {
      "width": 360,
      "height": 640,
      "aspectRatio": "free"
    },
    "landscape": {
      "width": 640,
      "height": 360,
      "aspectRatio": "free"
    }
  },
  "theme": {
    "allowColorOverride": true,
    "embeddedThemes": {
      "default": {
        "version": "1.0",
        "themeName": "Default",
        "colors": {
          "primary": "#2196F3",
          "background": "#000000",
          "text": "#FFFFFF",
          "progressActive": "#2196F3",
          "progressInactive": "#424242"
        }
      }
    },
    "defaultTheme": "default"
  },
  "layout": {
    "portrait": [
      {
        "id": "cover",
        "type": "cover-image",
        "x": 30,
        "y": 100,
        "width": 300,
        "height": 300,
        "anchor": "top-left"
      },
      {
        "id": "title",
        "type": "text",
        "x": 30,
        "y": 420,
        "width": 300,
        "height": 40,
        "dataBinding": "book.title",
        "fontSize": 20,
        "textAlign": "center",
        "themeable": {
          "color": "text"
        }
      },
      {
        "id": "play",
        "type": "button",
        "x": 140,
        "y": 120,
        "width": 80,
        "height": 80,
        "anchor": "bottom-center",
        "action": "toggle-play-pause",
        "themeable": {
          "tint": "primary"
        }
      },
      {
        "id": "progress",
        "type": "progress-bar",
        "x": 30,
        "y": 200,
        "width": 300,
        "height": 10,
        "anchor": "bottom-left",
        "dataBinding": "playback.position",
        "interactive": true,
        "themeable": {
          "activeColor": "progressActive",
          "inactiveColor": "progressInactive"
        }
      }
    ],
    "landscape": []
  }
}
```

Save this as `manifest.json`, add a `preview.png`, ZIP them up, and you have a working skin!

---

**Happy skinning! 🎨**
