/**
 * Skin Designer Application
 */

class SkinState {
    constructor(skinData, assets) {
        this.skin = skinData;
        this.assets = assets || [];
        this.manifest = skinData.manifest || {};

        // Ensure manifest structure
        if (!this.manifest.dimensions) {
            this.manifest.dimensions = {
                portrait: { width: 360, height: 640, aspectRatio: 'free' },
                landscape: { width: 640, height: 360, aspectRatio: 'free' }
            };
        }
        if (!this.manifest.layout) {
            this.manifest.layout = {
                portrait: [],
                landscape: []
            };
        }

        // Initialize drive mode dimensions
        if (!this.manifest.dimensions.drivePortrait) {
             this.manifest.dimensions.drivePortrait = { width: 360, height: 800, aspectRatio: 'free' };
        }
        if (!this.manifest.dimensions.driveLandscape) {
             this.manifest.dimensions.driveLandscape = { width: 800, height: 360, aspectRatio: 'free' };
        }

        this.currentOrientation = 'portrait';
        this.selectedElementId = null;
        this.listeners = [];
        this.updatingFromJson = false;

        // History
        this.history = [];
        this.historyIndex = -1;
        this.pushState();
    }

    subscribe(callback) {
        this.listeners.push(callback);
    }

    notify(eventType = 'update') {
        if (this.updatingFromJson && eventType !== 'manifestLoaded') return;
        this.listeners.forEach(cb => cb(this, eventType));
    }

    setOrientation(orientation) {
        this.currentOrientation = orientation;
        this.selectedElementId = null;
        this.notify('orientationChanged');
    }

    getDimensions() {
        return this.manifest.dimensions[this.currentOrientation] || { width: 360, height: 640 };
    }

    getLayout() {
        if (!this.manifest.layout[this.currentOrientation]) {
             this.manifest.layout[this.currentOrientation] = [];
        }
        return this.manifest.layout[this.currentOrientation];
    }

    // Recursive helper to find element and its parent array
    findElementAndParent(id, currentList) {
        if (!currentList) currentList = this.getLayout();
        for (let i = 0; i < currentList.length; i++) {
            if (currentList[i].id === id) {
                return { element: currentList[i], parent: currentList, index: i };
            }
            if (currentList[i].children) {
                const result = this.findElementAndParent(id, currentList[i].children);
                if (result) return result;
            }
        }
        return null;
    }

    // Helper to generate unique ID
    generateUniqueId(baseId) {
        let finalId = baseId;
        let counter = 1;
        while (this.findElementAndParent(finalId)) {
            finalId = `${baseId}-${counter++}`;
        }
        return finalId;
    }

    addElement(element, parentId = null) {
        let targetList = this.getLayout();
        if (parentId) {
            const parent = this.findElementAndParent(parentId);
            if (parent && parent.element) {
                if (!parent.element.children) parent.element.children = [];
                targetList = parent.element.children;
            }
        }

        element.id = this.generateUniqueId(element.id || element.type);
        if (!element.themeable) element.themeable = {};

        targetList.push(element);
        this.selectedElementId = element.id;
        this.notify('elementAdded');
        this.pushState();
        return element.id;
    }

    updateElement(id, updates) {
        const result = this.findElementAndParent(id);
        if (result) {
            const { element, parent, index } = result;
            if (updates.id && updates.id !== id) {
                 if (this.findElementAndParent(updates.id)) {
                     alert('ID must be unique');
                     return;
                 }
                 this.selectedElementId = updates.id;
            }
            parent[index] = { ...element, ...updates };
            this.notify('elementUpdated');
            this.pushState();
        }
    }

    removeElement(id) {
        const result = this.findElementAndParent(id);
        if (result) {
            result.parent.splice(result.index, 1);
            if (this.selectedElementId === id) this.selectedElementId = null;
            this.notify('elementRemoved');
            this.pushState();
        }
    }

    selectElement(id) {
        // If clicking same, maybe don't deselect? Or do?
        // User wants "clicking background deselects", so id=null comes here.
        this.selectedElementId = id;
        this.notify('selectionChanged');
    }

    getSelectedElement() {
        if (!this.selectedElementId) return null;
        const result = this.findElementAndParent(this.selectedElementId);
        return result ? result.element : null;
    }

    updateManifestJson(jsonString) {
        try {
            const newManifest = JSON.parse(jsonString);
            this.manifest = newManifest;
            if (!this.manifest.layout) this.manifest.layout = {};
            this.updatingFromJson = true;
            this.notify('manifestLoaded');
            this.updatingFromJson = false;
            this.pushState();
            return true;
        } catch (e) {
            return false;
        }
    }

    resolveAssetUrl(path) {
        if (!path) return '';

        // If it's already a full URL or data URI, return as-is
        if (path.startsWith('http') || path.startsWith('data:')) return path;

        // If it's an absolute path starting with /skin-asset/, return as-is (already proxied)
        if (path.startsWith('/skin-asset/')) return path;

        // Check if we have this asset in the uploaded assets list
        const asset = this.assets.find(a => a.relative_path === path || a.path === path || a.name === path);
        if (asset) return asset.url;

        // Use the skin asset proxy route
        // Format: /skin-asset/{skinId}/{path}
        const skinId = this.skin.id;
        const assetPath = path.startsWith('/') ? path.substring(1) : path;

        return `/skin-asset/${skinId}/${assetPath}`;
    }

    getBackgroundImage() {
         return this.manifest.backgrounds ? this.manifest.backgrounds[this.currentOrientation] : null;
    }

    setBackgroundImage(path) {
         if (!this.manifest.backgrounds) this.manifest.backgrounds = {};
         this.manifest.backgrounds[this.currentOrientation] = path;
         this.notify('backgroundUpdated');
         this.pushState();
    }

    addAsset(asset) {
        this.assets.push(asset);
        this.notify('assetsUpdated');
    }

    pushState() {
        if (this.historyIndex < this.history.length - 1) {
            this.history = this.history.slice(0, this.historyIndex + 1);
        }
        const stateStr = JSON.stringify(this.manifest);
        if (this.history.length > 0 && this.history[this.historyIndex] === stateStr) return;

        this.history.push(stateStr);
        this.historyIndex++;
        if (this.history.length > 50) {
            this.history.shift();
            this.historyIndex--;
        }
    }

    undo() {
        if (this.historyIndex > 0) {
            this.historyIndex--;
            this.restoreState();
        }
    }

    redo() {
        if (this.historyIndex < this.history.length - 1) {
            this.historyIndex++;
            this.restoreState();
        }
    }

    restoreState() {
        try {
            this.manifest = JSON.parse(this.history[this.historyIndex]);
            this.updatingFromJson = true;
            this.selectedElementId = null;
            this.notify('manifestLoaded');
            this.updatingFromJson = false;
        } catch(e) {}
    }
}

class SampleData {
    constructor(state) {
        this.state = state;
        this.coverUrl = null;
        this.data = {
            'book.title': 'The Hitchhiker\'s Guide to the Galaxy',
            'book.author': 'Douglas Adams',
            'book.narrator': 'Stephen Fry',
            'book.series': 'Hitchhiker\'s Guide',
            'book.duration': '5h 51m',
            'book.description': 'Seconds before the Earth is demolished to make way for a galactic freeway, Arthur Dent is plucked off the planet by his friend Ford Prefect, a researcher for the revised edition of The Hitchhiker\'s Guide to the Galaxy.',
            'playback.currentTime': '1:23:45',
            'playback.timeLeft': '4:27:15',
            'playback.totalTime': '5:51:00',
            'playback.percentage': '25%',
            'playback.speed': '1.0x',
            'playback.chapter': 'Chapter 3'
        };
    }

    get(key) {
        return this.data[key] || `{${key}}`;
    }

    set(key, value) {
        this.data[key] = value;
        this.state.notify('sampleDataUpdated');
    }

    getCover() {
        return this.coverUrl || '/images/sample_cover.jpg';
    }

    getBindingValue(binding) {
        switch (binding) {
            case 'book.title': return "The Hitchhiker's Guide to the Galaxy";
            case 'book.author': return 'Douglas Adams';
            case 'book.narrator': return 'Stephen Fry';
            case 'book.series': return 'Hitchhiker\'s Guide #1';
            case 'book.duration': return '5h 51m';
            case 'book.description': return 'Seconds before the Earth is demolished...';
            case 'playback.currentTime': return '01:23:45';
            case 'playback.timeLeft': return '04:27:15';
            case 'playback.totalTime': return '05:51:00';
            case 'playback.percentage': return '24%';
            case 'playback.speed': return '1.0x';
            case 'playback.chapter': return 'Chapter 3';
            default: return `[${binding}]`;
        }
    }

    fetchSampleData(bookId = null) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        let url = '/gallery/skins/sample-data';
        if (bookId) {
            url += `?book_id=${bookId}`;
        }

        fetch(url, {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                console.error('Sample data error:', data.error);
                return;
            }
            if (data.cover) this.coverUrl = data.cover;

            Object.keys(data).forEach(key => {
                if (key !== 'cover' && key !== 'available_books' && key !== 'current_book_id') {
                    this.data[key] = data[key];
                }
            });

            if (data.available_books) {
                this.updateBookSelector(data.available_books, data.current_book_id);
            }

            this.state.notify('sampleDataUpdated');
        })
        .catch(err => console.error('Failed to fetch sample data:', err));
    }

    updateBookSelector(books, currentId) {
        // Change: Insert into the editor container, not the section wrapper,
        // to avoid breaking the header/collapsible logic
        const container = document.getElementById('sample-data-editor');
        if (!container) return;

        let selector = document.getElementById('sample-book-select');
        if (!selector) {
            const selectGroup = document.createElement('div');
            selectGroup.className = 'prop-group sample-selector';
            selectGroup.style.padding = '0 0 12px 0';
            selectGroup.style.borderBottom = '1px solid #444';
            selectGroup.style.marginBottom = '12px';

            const label = document.createElement('label');
            label.innerText = 'Preview Book:';
            label.className = 'prop-label';
            label.style.display = 'block';
            label.style.marginBottom = '5px';

            selector = document.createElement('select');
            selector.id = 'sample-book-select';
            selector.className = 'prop-select';
            selector.style.width = '100%';

            selector.onchange = (e) => {
                const id = e.target.value;
                if (id) this.fetchSampleData(id);
            };

            selectGroup.appendChild(label);
            selectGroup.appendChild(selector);

            // Insert at the VERY top of the list
            container.insertBefore(selectGroup, container.firstChild);
        }

        // Rebuild options
        selector.innerHTML = '';
        books.forEach(book => {
            const opt = document.createElement('option');
            opt.value = book.id;
            opt.innerText = book.title;
            if (book.id == currentId) opt.selected = true;
            selector.appendChild(opt);
        });
    }
}

class SkinRenderer {
    constructor(containerId, state, sampleData) {
        this.container = document.getElementById(containerId);
        this.state = state;
        this.sampleData = sampleData;
        this.scale = 1;
        this.manualZoom = null; // null means auto-fit
        this.state.subscribe((state, type) => {
            if (['backgroundUpdated', 'update', 'elementAdded', 'elementUpdated', 'elementRemoved', 'orientationChanged', 'selectionChanged', 'manifestLoaded', 'sampleDataUpdated'].includes(type)) {
                this.render();
            }
        });

        // Expose zoom controls globally
        window.zoomIn = () => this.adjustZoom(0.1);
        window.zoomOut = () => this.adjustZoom(-0.1);
        window.zoomReset = () => { this.manualZoom = null; this.render(); };
    }

    adjustZoom(delta) {
        if (this.manualZoom === null) this.manualZoom = this.scale;
        this.manualZoom = Math.max(0.1, Math.min(3.0, this.manualZoom + delta));
        this.render();
    }



    render() {
        const dims = this.state.getDimensions();
        const layout = this.state.getLayout();

        this.container.innerHTML = '';

        const parent = this.container.parentElement; // preview-area
        // Ensure parent has click listener for deselect
        if (!parent.hasDeselectListener) {
            parent.onclick = (e) => {
                if (e.target === parent) {
                    this.state.selectElement(null);
                }
            };
            parent.hasDeselectListener = true;
        }

        const availableWidth = parent.clientWidth - 40;
        const availableHeight = parent.clientHeight - 40;

        if (this.manualZoom !== null) {
            this.scale = this.manualZoom;
        } else {
            const scaleX = availableWidth / dims.width;
            const scaleY = availableHeight / dims.height;
            this.scale = Math.min(scaleX, scaleY, 1.2);
            if (this.scale < 0.1) this.scale = 0.1;
        }

        // Update zoom label
        const zoomLabel = document.getElementById('zoom-level');
        if (zoomLabel) zoomLabel.innerText = Math.round(this.scale * 100) + '%';

        this.container.style.width = `${dims.width}px`;
        this.container.style.height = `${dims.height}px`;
        this.container.style.transform = `scale(${this.scale})`;

        const bgImage = this.state.getBackgroundImage();
        if (bgImage) {
            this.container.style.backgroundImage = `url('${this.state.resolveAssetUrl(bgImage)}')`;
            this.container.style.backgroundSize = 'cover';
            this.container.style.backgroundPosition = 'center';
            this.container.style.backgroundRepeat = 'no-repeat';
        } else {
            this.container.style.backgroundImage = 'none';
        }

        this.renderNodes(this.container, layout);
    }

    renderNodes(parentElement, elements, parentWidth = null, parentHeight = null) {
        if (!elements) return;

        // For top-level elements, use container dimensions
        const containerWidth = parentWidth || parseInt(this.container.style.width) || 360;
        const containerHeight = parentHeight || parseInt(this.container.style.height) || 640;

        elements.forEach(el => {
            // Create the node for the current element
            const node = this.createNode(el, containerWidth, containerHeight);
            parentElement.appendChild(node);

            // Recursion for children if this element is a container
            if (el.children && el.children.length > 0) {
                // For containers, calculate their actual dimensions
                const elWidth = this.calculateSize(el.width, containerWidth);
                const elHeight = this.calculateSize(el.height, containerHeight);

                // Calculate line layout positions if this container uses layoutDirection
                const linePositions = this.calculateLineLayout(
                    el.children,
                    elWidth,
                    elHeight,
                    el.layoutDirection,
                    el.gap || 0,
                    el.padding || 0
                );

                // Render children with calculated positions
                el.children.forEach(child => {
                    // Override child position if in line layout
                    const linePos = linePositions.find(p => p.elementId === child.id);
                    const childWithPosition = linePos ? {
                        ...child,
                        x: linePos.x,
                        y: linePos.y
                    } : child;

                    // Create child node and append to current element's node
                    const childNode = this.createNode(childWithPosition, elWidth, elHeight);
                    node.appendChild(childNode);

                    // Recursively render grandchildren
                    if (childWithPosition.children && childWithPosition.children.length > 0) {
                        const childWidth = this.calculateSize(childWithPosition.width, elWidth);
                        const childHeight = this.calculateSize(childWithPosition.height, elHeight);
                        this.renderNodes(childNode, childWithPosition.children, childWidth, childHeight);
                    }
                });
            }
        });
    }

    calculateSize(flexValue, containerSize) {
        if (typeof flexValue === 'number') return flexValue;
        if (flexValue === 'auto' || flexValue === 'max') return containerSize;
        if (typeof flexValue === 'string' && flexValue.startsWith('max-')) {
            const minus = parseInt(flexValue.substring(4));
            return containerSize - minus;
        }
        return containerSize; // Default to container size if unknown
    }

    calculatePosition(flexValue, containerSize, elementSize) {
        if (typeof flexValue === 'number') return flexValue;
        if (flexValue === 'auto' || flexValue === 'center') {
            return Math.floor((containerSize - elementSize) / 2); // Center
        }
        if (flexValue === 'line') return 0; // Will be calculated by line layout
        if (flexValue === 'max') return containerSize;
        if (typeof flexValue === 'string' && flexValue.startsWith('max-')) {
            const minus = parseInt(flexValue.substring(4));
            return containerSize - minus;
        }
        return 0; // Default to 0 if unknown
    }

    calculateLineLayout(children, containerWidth, containerHeight, layoutDirection, gap, padding) {
        const isHorizontal = layoutDirection === 'horizontal';
        const isVertical = layoutDirection === 'vertical';

        if (!isHorizontal && !isVertical) {
            return []; // No line layout
        }

        // Find children that use line positioning
        const lineChildren = children.filter(child => {
            if (isHorizontal) return child.x === 'line';
            if (isVertical) return child.y === 'line';
            return false;
        });

        if (lineChildren.length === 0) return [];

        // Calculate available space
        const availableSpace = (isHorizontal ? containerWidth : containerHeight) - (2 * padding);

        // Calculate total size of line children
        const totalChildSize = lineChildren.reduce((sum, child) => {
            const size = isHorizontal
                ? this.calculateSize(child.width, containerWidth)
                : this.calculateSize(child.height, containerHeight);
            return sum + size;
        }, 0);

        // Calculate spacing - matches client implementation
        let actualGap, edgeSpacing;
        if (gap === 0) {
            // Auto-calculate gap to evenly distribute items
            const remainingSpace = availableSpace - totalChildSize;
            // Divide by (count + 1) to get equal spacing at edges and between items
            actualGap = lineChildren.length > 0
                ? Math.max(0, Math.floor(remainingSpace / (lineChildren.length + 1)))
                : 0;
            edgeSpacing = actualGap; // Same as gap for even distribution
        } else {
            // Use specified gap
            actualGap = gap;
            const totalGapSpace = gap * (lineChildren.length - 1);
            const remainingSpace = availableSpace - totalChildSize - totalGapSpace;
            edgeSpacing = remainingSpace > 0 ? Math.floor(remainingSpace / 2) : 0;
        }

        // Calculate positions
        const positions = [];
        let currentPos = padding + edgeSpacing;

        lineChildren.forEach(child => {
            const childWidth = this.calculateSize(child.width, containerWidth);
            const childHeight = this.calculateSize(child.height, containerHeight);

            const x = isHorizontal
                ? currentPos
                : this.calculatePosition(child.x, containerWidth, childWidth);

            const y = isVertical
                ? currentPos
                : this.calculatePosition(child.y, containerHeight, childHeight);

            positions.push({ elementId: child.id, x, y });

            const childSize = isHorizontal ? childWidth : childHeight;
            currentPos += childSize + actualGap;
        });

        return positions;
    }

    createNode(el, containerWidth, containerHeight) {
        const div = document.createElement('div');
        div.id = `el-${el.id}`;
        div.className = 'skin-element';
        div.dataset.id = el.id; // For JSON jump

        if (this.state.selectedElementId === el.id) {
            div.className += ' selected';
        }

        div.style.position = 'absolute';

        // Calculate actual dimensions
        const actualWidth = this.calculateSize(el.width, containerWidth);
        const actualHeight = this.calculateSize(el.height, containerHeight);

        // Parse anchor position - determines the reference point in the container
        const anchor = el.anchor || 'top-left';
        const anchorPoints = {
            'top-left': { x: 0, y: 0 },
            'top-center': { x: containerWidth / 2, y: 0 },
            'top-right': { x: containerWidth, y: 0 },
            'center-left': { x: 0, y: containerHeight / 2 },
            'center': { x: containerWidth / 2, y: containerHeight / 2 },
            'center-right': { x: containerWidth, y: containerHeight / 2 },
            'bottom-left': { x: 0, y: containerHeight },
            'bottom-center': { x: containerWidth / 2, y: containerHeight },
            'bottom-right': { x: containerWidth, y: containerHeight }
        };

        const anchorPoint = anchorPoints[anchor] || anchorPoints['top-left'];

        // Calculate element anchor offset - where on the element the anchor point is
        const elementAnchorOffset = {
            'top-left': { x: 0, y: 0 },
            'top-center': { x: -actualWidth / 2, y: 0 },
            'top-right': { x: -actualWidth, y: 0 },
            'center-left': { x: 0, y: -actualHeight / 2 },
            'center': { x: -actualWidth / 2, y: -actualHeight / 2 },
            'center-right': { x: -actualWidth, y: -actualHeight / 2 },
            'bottom-left': { x: 0, y: -actualHeight },
            'bottom-center': { x: -actualWidth / 2, y: -actualHeight },
            'bottom-right': { x: -actualWidth, y: -actualHeight }
        };

        const elemOffset = elementAnchorOffset[anchor] || elementAnchorOffset['top-left'];

        // Calculate x/y offsets from anchor point
        const xOffset = typeof el.x === 'number' ? el.x : this.calculatePosition(el.x, containerWidth, actualWidth);
        const yOffset = typeof el.y === 'number' ? el.y : this.calculatePosition(el.y, containerHeight, actualHeight);

        // Final position = anchor point + element anchor offset + x/y offset
        const finalX = anchorPoint.x + elemOffset.x + xOffset;
        const finalY = anchorPoint.y + elemOffset.y + yOffset;

        // Apply positioning
        div.style.left = `${finalX}px`;
        div.style.top = `${finalY}px`;

        // Apply dimensions
        div.style.width = `${actualWidth}px`;
        div.style.height = `${actualHeight}px`;

        // Background color

        // Background from properties (supports gradients if string is valid CSS)
        if (el.backgroundColor) {
            if (el.backgroundColor.includes('gradient')) {
                div.style.backgroundImage = el.backgroundColor;
            } else {
                div.style.backgroundColor = el.backgroundColor;
            }
        }

        switch (el.type) {
            case 'text':
                if (el.dataBinding) {
                    div.innerText = this.sampleData.get(el.dataBinding);
                } else {
                    div.innerText = el.text || 'Text';
                }
                div.style.fontSize = el.fontSize ? `${el.fontSize}px` : '16px';
                div.style.color = this.resolveThemeColor(el.themeable?.color, '#ffffff');
                div.style.textAlign = el.textAlign || 'left';
                div.style.fontWeight = el.fontWeight || 'normal';
                break;
            case 'button':
                if (el.image) {
                    const img = document.createElement('img');
                    img.src = this.state.resolveAssetUrl(el.image);
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.objectFit = 'contain';
                    div.appendChild(img);
                } else {
                     if (!el.backgroundColor) div.style.backgroundColor = el.themeable?.tint ? this.resolveThemeColor(el.themeable.tint, '#495057') : '#495057';
                     div.style.borderRadius = '999px';
                     div.style.color = 'white';
                     div.style.display = 'flex';
                     div.style.alignItems = 'center';
                     div.style.justifyContent = 'center';
                     div.innerText = this.getActionIcon(el.action);
                }
                break;
            case 'image':
            case 'cover-image':
                if (el.image) {
                     const img = document.createElement('img');
                     img.src = this.state.resolveAssetUrl(el.image);
                     img.style.width = '100%';
                     img.style.height = '100%';
                     img.style.objectFit = 'cover';
                     div.appendChild(img);
                } else if (el.type === 'cover-image') {
                    // Sample cover
                    div.style.overflow = 'hidden';
                    const sampleCover = this.sampleData.getCover();
                    if (sampleCover) {
                        const coverImg = document.createElement('img');
                        coverImg.src = sampleCover;
                        coverImg.style.width = '100%';
                        coverImg.style.height = '100%';
                        coverImg.style.objectFit = 'cover';
                        coverImg.style.display = 'block';
                        coverImg.onerror = () => {
                            coverImg.remove();
                            if (!el.backgroundColor) div.style.background = 'linear-gradient(135deg, #444 0%, #666 100%)';
                            div.style.display = 'flex';
                            div.style.alignItems = 'center';
                            div.style.justifyContent = 'center';
                            div.innerHTML = '<span style="font-size: 24px; color: white;">📕</span>';
                        };
                        div.appendChild(coverImg);
                    } else {
                        if (!el.backgroundColor) div.style.background = 'linear-gradient(135deg, #444 0%, #666 100%)';
                        div.style.display = 'flex';
                        div.style.alignItems = 'center';
                        div.style.justifyContent = 'center';
                        div.innerHTML = '<span style="font-size: 24px; color: white;">📕</span>';
                    }
                } else {
                     if (!el.backgroundColor) div.style.backgroundColor = '#6c757d';
                     div.style.color = '#adb5bd';
                     div.style.display = 'flex';
                     div.style.alignItems = 'center';
                     div.style.justifyContent = 'center';
                     div.innerText = el.type;
                }
                break;
            case 'progress-bar':
                if (!el.backgroundColor) div.style.backgroundColor = '#495057';
                div.style.borderRadius = '4px';
                const progress = document.createElement('div');
                progress.style.height = '100%';
                progress.style.width = '50%'; // Sample value
                progress.style.borderRadius = '4px';
                progress.style.backgroundColor = this.resolveThemeColor(el.themeable?.activeColor, '#0d6efd');
                div.appendChild(progress);
                break;
            case 'container':
                if (!el.backgroundColor) {
                    div.style.border = '1px dashed #6c757d';
                    div.style.backgroundColor = 'rgba(0,0,0,0.2)';
                }
                break;
        }

        div.addEventListener('click', (e) => {
            e.stopPropagation();
            this.state.selectElement(el.id);
        });

        return div;
    }

    resolveThemeColor(value, fallback) {
        if (!value) return fallback;
        if (value.startsWith('#')) return value;
        // Resolve theme keys from skin manifest's embedded theme
        const theme = this.state.manifest?.theme;
        if (theme?.embeddedThemes) {
            const defaultThemeId = theme.defaultTheme || Object.keys(theme.embeddedThemes)[0];
            const themeColors = theme.embeddedThemes[defaultThemeId]?.colors;
            if (themeColors && themeColors[value]) return themeColors[value];
        }
        // Default theme color map
        const defaults = {
            primary: '#2196F3', secondary: '#FF4081', background: '#121212',
            surface: '#1E1E1E', text: '#FFFFFF', accent: '#FF4081',
            progressActive: '#2196F3', progressInactive: '#424242',
            timeText: '#FFFFFF', buttonTint: '#2196F3', borderColor: '#666666',
            overlayColor: '#00000080'
        };
        return defaults[value] || fallback;
    }

    getActionIcon(action) {
        if (!action) return '?';
        if (action.includes('play-pause')) return '▶';
        if (action.includes('skip-forward')) return '⏩';
        if (action.includes('skip-backward')) return '⏪';
        return '?';
    }
}

class ElementTree {
    constructor(containerId, state) {
        this.container = document.getElementById(containerId);
        this.state = state;
        this.state.subscribe(() => this.render());
    }

    render() {
        const layout = this.state.getLayout();
        this.container.innerHTML = '';
        this.renderElements(layout, 0);
    }

    renderElements(elements, depth) {
        elements.forEach(el => {
            const item = document.createElement('div');
            item.className = 'tree-item' + (this.state.selectedElementId === el.id ? ' active' : '');
            item.style.paddingLeft = `${depth * 16 + 10}px`;

            // Add expand/collapse icon for containers with children
            let expandIcon = '';
            if (el.children && el.children.length > 0) {
                expandIcon = `<span class="tree-expand">▼</span>`;
            } else {
                expandIcon = `<span class="tree-expand" style="visibility: hidden;">▼</span>`;
            }

            item.innerHTML = `
                ${expandIcon}
                <span class="tree-item-icon">${this.getTypeIcon(el.type)}</span>
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;">${el.id}</span>
            `;
            const del = document.createElement('button');
            del.className = 'tree-item-delete';
            del.innerHTML = '&times;';
            del.onclick = (e) => {
                e.stopPropagation();
                if(confirm('Delete?')) this.state.removeElement(el.id);
            };
            item.appendChild(del);
            item.onclick = () => this.state.selectElement(el.id);
            this.container.appendChild(item);

            // Recursively render children
            if (el.children && el.children.length > 0) {
                this.renderElements(el.children, depth + 1);
            }
        });
    }

    getTypeIcon(type) {
        const icons = { 'button': '👆', 'text': 'T', 'image': '🖼️', 'cover-image': '📕', 'progress-bar': '📊', 'container': '📦' };
        return icons[type] || '❓';
    }
}

class SampleDataEditor {
    constructor(containerId, sampleData) {
        this.container = document.getElementById(containerId);
        this.sampleData = sampleData;

        // Subscribe to updates so we sync when a book is selected or fetched
        this.sampleData.state.subscribe((_, type) => {
            if (type === 'sampleDataUpdated') {
                this.render();
            }
        });

        this.render();
    }

    render() {
        if (!this.container) return;

        // Preserve the book selector if it exists
        const existingSelector = this.container.querySelector('.sample-selector');

        // Remove all children except the selector
        Array.from(this.container.children).forEach(child => {
            if (!child.classList.contains('sample-selector')) {
                child.remove();
            }
        });

        const keys = Object.keys(this.sampleData.data);
        keys.forEach(key => {
            const group = document.createElement('div');
            group.className = 'prop-group';
            const lbl = document.createElement('label');
            lbl.className = 'prop-label';
            lbl.innerText = key;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'prop-input';
            input.value = this.sampleData.data[key];
            input.onchange = (e) => this.sampleData.set(key, e.target.value);

            group.appendChild(lbl);
            group.appendChild(input);
            this.container.appendChild(group);
        });

        // Add cover image preview
        if (this.sampleData.coverUrl) {
            const coverGroup = document.createElement('div');
            coverGroup.className = 'prop-group';
            const coverLbl = document.createElement('label');
            coverLbl.className = 'prop-label';
            coverLbl.innerText = 'Cover Preview';
            const coverImg = document.createElement('img');
            coverImg.src = this.sampleData.coverUrl;
            coverImg.style.maxWidth = '100%';
            coverImg.style.maxHeight = '120px';
            coverImg.style.borderRadius = '4px';
            coverImg.style.objectFit = 'contain';
            coverGroup.appendChild(coverLbl);
            coverGroup.appendChild(coverImg);
            this.container.appendChild(coverGroup);
        }
    }
}


const VALIDATORS = {
    id: (val) => /^[a-zA-Z0-9_-]+$/.test(val) ? null : "ID must be alphanumeric, underscores or hyphens",
    dimension: (val) => {
        if (typeof val === 'number') return null;
        if (val === 'max' || val === 'auto' || val === 'none' || val === 'start' || val === 'line' || val === 'center') return null;
        if (/^max-\d+$/.test(val)) return null;
        if (/^\d+%$/.test(val)) return null;
        const n = parseFloat(val);
        return isNaN(n) ? "Invalid. Use number, 'max', 'max-N', 'auto', 'line', 'center', or '50%'" : null;
    },
    action: (val) => {
        const validActions = [
            'toggle-play-pause', 'play', 'pause', 'stop',
            'next-chapter', 'prev-chapter', 'next-book', 'prev-book',
            'show-chapters', 'show-bookmarks', 'create-bookmark',
            'sleep-timer', 'playback-speed', 'exit-drive-mode'
        ];
        if (validActions.includes(val)) return null;
        if (/^skip-(forward|backward)-\d+$/.test(val)) return null;
        return "Invalid action format.";
    },
    anchor: (val) => [
        'top-left', 'top-center', 'top-right',
        'center-left', 'center', 'center-right',
        'bottom-left', 'bottom-center', 'bottom-right'
    ].includes(val) ? null : "Invalid anchor",
    themeColor: (val) => {
        if (val === '') return null;
        const themeKeys = ['primary', 'secondary', 'background', 'surface', 'text', 'accent', 'progressActive', 'progressInactive', 'timeText', 'buttonTint', 'borderColor', 'overlayColor'];
        if (themeKeys.includes(val)) return null;
        if (/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/.test(val)) return null;
        return "Use theme key (primary, surface, etc.) or hex color";
    },
    color: (val) => /^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/.test(val) || val === '' ? null : "Invalid hex color",
    generic: (val) => val === '' ? "Value required" : null
};

const TOOLTIPS = {
    id: "Unique identifier for this element.",
    anchor: "Point on the canvas where this element is positioned from.",
    x: "Horizontal position. Supports 'auto' (center), 'max' (full width), 'max-20' (20px margin).",
    y: "Vertical position. Supports 'auto' (center), 'max' (full height), 'max-20' (20px margin).",
    width: "Width in pixels, 'max' (fill), or '50%' (percentage).",
    height: "Height in pixels, 'max' (fill), or '50%' (percentage).",
    action: "Action on tap. Defaults: skip-forward-30, next-chapter, etc.",
    dataBinding: "Dynamic field from book or playback state."
};

class PropertyEditor {
    constructor(containerId, state) {
        this.container = document.getElementById(containerId);
        this.state = state;
        this.state.subscribe((_, type) => {
            if (type === 'selectionChanged' || type === 'elementUpdated' || type === 'assetsUpdated') this.render();
        });
    }

    render() {
        const el = this.state.getSelectedElement();
        this.container.innerHTML = '';
        if (!el) {
            this.container.innerHTML = '<div class="prop-label" style="border-bottom: 1px solid #495057; padding-bottom: 8px; margin-bottom: 12px; font-weight: bold; text-transform: uppercase;">Skin Properties</div>';

            this.addInput('Name', this.state.skin.name || '', (val) => {
                this.state.skin.name = val;
                this.state.manifest.skinName = val;
                const titleEl = document.querySelector('h1');
                if (titleEl) titleEl.innerText = val;
            });

            this.addInput('Author', this.state.manifest.author || '', (val) => {
                this.state.manifest.author = val;
            });

            this.addInput('Version', this.state.manifest.version || '1.0', (val) => {
                this.state.manifest.version = val;
            });

            this.addImageInput('Background Image', this.state.getBackgroundImage() || '', (val) => {
                this.state.setBackgroundImage(val);
            });

            this.container.innerHTML += '<div class="text-center small text-muted mt-5">Select an element to edit properties</div>';
            return;
        }

        this.addInput('ID', el.id, (val) => this.state.updateElement(el.id, { id: val }), { validator: VALIDATORS.id, tooltip: TOOLTIPS.id });

        // Layout Props
        this.addSelect('Anchor', el.anchor || 'top-left', ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'], (val) => this.state.updateElement(el.id, { anchor: val }), { tooltip: TOOLTIPS.anchor });

        this.addInput('X', el.x ?? 0, (val) => {
            const num = parseFloat(val);
            this.state.updateElement(el.id, { x: (val === '' || val === undefined) ? 0 : (isNaN(num) ? val : num) });
        }, { validator: VALIDATORS.dimension, tooltip: TOOLTIPS.x });
        this.addInput('Y', el.y ?? 0, (val) => {
            const num = parseFloat(val);
            this.state.updateElement(el.id, { y: (val === '' || val === undefined) ? 0 : (isNaN(num) ? val : num) });
        }, { validator: VALIDATORS.dimension, tooltip: TOOLTIPS.y });

        // Width/Height logic
        this.addInput('Width', el.width, (val) => {
            const num = parseFloat(val);
            this.state.updateElement(el.id, { width: (val === 'max' || isNaN(num) || val.includes('%') || val.includes('-')) ? val : num });
        }, { validator: VALIDATORS.dimension, tooltip: TOOLTIPS.width });

        this.addInput('Height', el.height, (val) => {
            const num = parseFloat(val);
            this.state.updateElement(el.id, { height: (val === 'max' || isNaN(num) || val.includes('%') || val.includes('-')) ? val : num });
        }, { validator: VALIDATORS.dimension, tooltip: TOOLTIPS.height });

        // Background Color (All elements)
        this.addColorInput('Background', el.backgroundColor || '', (val) => this.state.updateElement(el.id, { backgroundColor: val }), { validator: VALIDATORS.color });

        if (el.type === 'text') {
            this.addInput('Text', el.text || '', (val) => this.state.updateElement(el.id, { text: val }));
            // Data Binding Dropdown
            const bindings = ['', 'book.title', 'book.author', 'book.narrator', 'book.series', 'book.duration', 'book.description', 'playback.currentTime', 'playback.timeLeft', 'playback.percentage', 'playback.speed', 'playback.chapter', 'playback.totalTime'];
            this.addSelect('Data Binding', el.dataBinding || '', bindings, (val) => this.state.updateElement(el.id, { dataBinding: val }), { tooltip: TOOLTIPS.dataBinding });

            this.addNumberInput('Font Size', el.fontSize, (val) => this.state.updateElement(el.id, { fontSize: val }));
            this.addThemeColorInput('Color', el.themeable?.color || '#ffffff', (val) => this.state.updateElement(el.id, { themeable: { ...el.themeable, color: val } }));
            this.addSelect('Align', el.textAlign || 'left', ['left', 'center', 'right'], (val) => this.state.updateElement(el.id, { textAlign: val }));
            this.addSelect('Font Weight', el.fontWeight || 'normal', ['normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900'], (val) => this.state.updateElement(el.id, { fontWeight: val }));
        }

        if (el.type === 'button') {
            this.addActionInput('Action', el.action || '', (val) => this.state.updateElement(el.id, { action: val }));
            this.addThemeColorInput('Tint', el.themeable?.tint || '', (val) => this.state.updateElement(el.id, { themeable: { ...el.themeable, tint: val } }));
            this.addImageInput('Icon Image', el.image, (val) => this.state.updateElement(el.id, { image: val }));
        }

        if (el.type === 'image' || el.type === 'cover-image') {
             this.addImageInput('Image Source', el.image, (val) => this.state.updateElement(el.id, { image: val }));
        }

        if (el.type === 'progress-bar') {
             this.addThemeColorInput('Active Color', el.themeable?.activeColor || '#2196F3', (val) => this.state.updateElement(el.id, { themeable: { ...el.themeable, activeColor: val } }));
             this.addThemeColorInput('Inactive Color', el.themeable?.inactiveColor || '#424242', (val) => this.state.updateElement(el.id, { themeable: { ...el.themeable, inactiveColor: val } }));
        }
    }

    addInput(label, value, onChange, options = {}) {
        const error = options.validator ? options.validator(value) : null;
        this.renderField(label, document.createElement('input'), (input) => {
            input.value = value !== undefined ? value : '';
            input.onchange = (e) => onChange(e.target.value);
            input.className = 'prop-input';
        }, { ...options, error });
    }

    addNumberInput(label, value, onChange, options = {}) {
        this.renderField(label, document.createElement('input'), (input) => {
            input.type = typeof value === 'number' ? 'number' : 'text';
            input.value = value !== undefined ? value : '';
            input.className = 'prop-input';
            input.onchange = (e) => {
                const val = e.target.value;
                const num = parseFloat(val);
                onChange(isNaN(num) ? val : num);
            };
        }, options);
    }

    addActionInput(label, value, onChange) {
        const commonActions = [
            { label: 'Play/Pause', value: 'toggle-play-pause' },
            { label: 'Skip Forward 10m', value: 'skip-forward-600' },
            { label: 'Skip Forward 1m', value: 'skip-forward-60' },
            { label: 'Skip Forward 30s', value: 'skip-forward-30' },
            { label: 'Skip Forward 10s', value: 'skip-forward-10' },
            { label: 'Skip Backward 10m', value: 'skip-backward-600' },
            { label: 'Skip Backward 1m', value: 'skip-backward-60' },
            { label: 'Skip Backward 30s', value: 'skip-backward-30' },
            { label: 'Skip Backward 10s', value: 'skip-backward-10' },
            { label: 'Next Chapter', value: 'next-chapter' },
            { label: 'Prev Chapter', value: 'prev-chapter' },
            { label: 'Show Chapters', value: 'show-chapters' },
            { label: 'Bookmarks', value: 'show-bookmarks' },
            { label: 'Add Bookmark', value: 'create-bookmark' },
            { label: 'Sleep Timer', value: 'sleep-timer' },
            { label: 'Playback Speed', value: 'playback-speed' },
            { label: 'Exit Drive Mode', value: 'exit-drive-mode' }
        ];

        const currentValue = value || '';
        const selectedAction = commonActions.find(a => a.value === currentValue);
        const isCustom = !selectedAction && currentValue !== '';

        this.renderField(label, document.createElement('div'), (container) => {
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '6px';

            const select = document.createElement('select');
            select.className = 'prop-select';

            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.innerText = '-- Select Action --';
            select.appendChild(defOpt);

            commonActions.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.value;
                opt.innerText = a.label;
                if (a.value === currentValue) opt.selected = true;
                select.appendChild(opt);
            });

            const customOpt = document.createElement('option');
            customOpt.value = 'CUSTOM';
            customOpt.innerText = 'Custom Action...';
            if (isCustom) customOpt.selected = true;
            select.appendChild(customOpt);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'prop-input';
            input.value = currentValue;
            input.placeholder = 'e.g. skip-forward-45';
            input.style.display = isCustom || select.value === 'CUSTOM' ? 'block' : 'none';

            select.onchange = (e) => {
                const val = e.target.value;
                if (val === 'CUSTOM') {
                    input.style.display = 'block';
                    input.focus();
                } else {
                    input.style.display = 'none';
                    if (val !== '') {
                        input.value = val;
                        onChange(val);
                    }
                }
            };

            input.onchange = (e) => onChange(e.target.value);

            container.appendChild(select);
            container.appendChild(input);
        }, {
            tooltip: TOOLTIPS.action,
            error: VALIDATORS.action(currentValue)
        });
    }

    addColorInput(label, value, onChange, options = {}) {
        const error = options.validator ? options.validator(value) : null;
        this.renderField(label, document.createElement('div'), (container) => {
            container.className = 'color-picker-wrapper';
            const colorInput = document.createElement('input');
            colorInput.type = 'color';
            colorInput.value = value && value.startsWith('#') && value.length <= 7 ? value : '#ffffff';
            colorInput.className = 'color-input-swatch';
            const textInput = document.createElement('input');
            textInput.type = 'text';
            textInput.value = value || '';
            textInput.className = 'prop-input';
            textInput.style.flex = '1';

            colorInput.oninput = (e) => { textInput.value = e.target.value; onChange(e.target.value); };
            textInput.onchange = (e) => { colorInput.value = e.target.value; onChange(e.target.value); };

            container.appendChild(colorInput);
            container.appendChild(textInput);
        }, { ...options, error });
    }

    addThemeColorInput(label, value, onChange) {
        const themeKeys = ['primary', 'secondary', 'background', 'surface', 'text', 'accent', 'progressActive', 'progressInactive', 'timeText', 'buttonTint', 'borderColor', 'overlayColor'];
        const isThemeKey = themeKeys.includes(value);
        const error = VALIDATORS.themeColor(value);

        this.renderField(label, document.createElement('div'), (container) => {
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '6px';

            const select = document.createElement('select');
            select.className = 'prop-select';

            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.innerText = '-- None --';
            select.appendChild(defOpt);

            themeKeys.forEach(key => {
                const opt = document.createElement('option');
                opt.value = key;
                opt.innerText = key;
                if (key === value) opt.selected = true;
                select.appendChild(opt);
            });

            const customOpt = document.createElement('option');
            customOpt.value = 'CUSTOM';
            customOpt.innerText = 'Custom Hex...';
            if (value && !isThemeKey && value !== '') customOpt.selected = true;
            select.appendChild(customOpt);

            const colorRow = document.createElement('div');
            colorRow.className = 'color-picker-wrapper';
            colorRow.style.display = (!isThemeKey && value && value !== '') ? 'flex' : 'none';

            const colorInput = document.createElement('input');
            colorInput.type = 'color';
            colorInput.value = value && value.startsWith('#') && value.length <= 7 ? value : '#ffffff';
            colorInput.className = 'color-input-swatch';

            const textInput = document.createElement('input');
            textInput.type = 'text';
            textInput.value = (!isThemeKey && value) ? value : '';
            textInput.className = 'prop-input';
            textInput.style.flex = '1';
            textInput.placeholder = '#FF0000';

            colorInput.oninput = (e) => { textInput.value = e.target.value; onChange(e.target.value); };
            textInput.onchange = (e) => { onChange(e.target.value); };

            colorRow.appendChild(colorInput);
            colorRow.appendChild(textInput);

            select.onchange = (e) => {
                const val = e.target.value;
                if (val === 'CUSTOM') {
                    colorRow.style.display = 'flex';
                    textInput.focus();
                } else {
                    colorRow.style.display = 'none';
                    onChange(val);
                }
            };

            container.appendChild(select);
            container.appendChild(colorRow);
        }, { error });
    }

    addImageInput(label, value, onChange) {
        this.renderField(label, document.createElement('div'), (container) => {
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '8px';

            if (value) {
                const img = document.createElement('img');
                img.src = this.state.resolveAssetUrl(value);
                img.className = 'prop-image-preview';
                container.appendChild(img);
            }

            const input = document.createElement('input');
            input.type = 'text';
            input.value = value || '';
            input.placeholder = 'assets/images/foo.png';
            input.className = 'prop-input';
            input.onchange = (e) => onChange(e.target.value);
            container.appendChild(input);

            const controls = document.createElement('div');
            controls.style.display = 'flex';
            controls.style.gap = '8px';

            const uploadBtn = document.createElement('button');
            uploadBtn.innerText = 'Upload';
            uploadBtn.className = 'btn btn-sm btn-secondary'; // Bootstrap btn
            uploadBtn.style.flex = '1';
            uploadBtn.onclick = () => {
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.onchange = (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    const formData = new FormData();
                    formData.append('file', file);
                    const path = `assets/images/${file.name}`;
                    formData.append('path', path);
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

                    const btnText = uploadBtn.innerText;
                    uploadBtn.innerText = 'Uploading...';
                    uploadBtn.disabled = true;

                    fetch(`/gallery/skins/${this.state.skin.id}/assets`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        uploadBtn.innerText = btnText;
                        uploadBtn.disabled = false;
                        if (data.success) {
                            this.state.addAsset({ path: data.url, relative_path: path, name: file.name, url: data.url });
                            onChange(path);
                        } else alert('Upload failed: ' + data.error);
                    })
                    .catch(err => {
                        uploadBtn.innerText = btnText;
                        uploadBtn.disabled = false;
                        alert('Upload failed: ' + err.message);
                    });
                };
                fileInput.click();
            };
            controls.appendChild(uploadBtn);

            if (this.state.assets.length > 0) {
                const select = document.createElement('select');
                select.className = 'prop-input';
                select.style.fontSize = '12px';
                select.style.flex = '1';
                select.innerHTML = '<option value="">Select...</option>';
                this.state.assets.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.relative_path;
                    opt.innerText = a.name;
                    if (a.relative_path === value) opt.selected = true;
                    select.appendChild(opt);
                });
                select.onchange = (e) => { if (e.target.value) onChange(e.target.value); };
                controls.appendChild(select);
            }
            container.appendChild(controls);
        });
    }

    addSelect(label, value, options, onChange, fieldOptions = {}) {
        this.renderField(label, document.createElement('select'), (select) => {
            select.className = 'prop-select';
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt;
                o.innerText = opt;
                o.selected = opt === value;
                select.appendChild(o);
            });
            select.onchange = (e) => onChange(e.target.value);
        }, fieldOptions);
    }

    renderField(label, element, setup, options = {}) {
        const group = document.createElement('div');
        group.className = 'prop-group';
        if (options.error) group.classList.add('has-error');

        const lblContainer = document.createElement('div');
        lblContainer.className = 'prop-label-container';

        const lbl = document.createElement('label');
        lbl.className = 'prop-label';
        lbl.innerText = label;
        lblContainer.appendChild(lbl);

        if (options.tooltip) {
            const info = document.createElement('span');
            info.className = 'prop-info-icon';
            info.innerHTML = '<i class="fas fa-info-circle"></i>';
            info.title = options.tooltip;
            lblContainer.appendChild(info);
        }

        group.appendChild(lblContainer);
        setup(element);
        group.appendChild(element);

        if (options.error) {
            const err = document.createElement('div');
            err.className = 'prop-error-text';
            err.innerText = options.error;
            group.appendChild(err);
        }

        this.container.appendChild(group);
    }
}

class JsonEditor {
    constructor(textareaId, state) {
        this.textarea = document.getElementById(textareaId);
        this.state = state;
        if (typeof CodeMirror !== 'undefined') {
            this.init();
        } else {
            setTimeout(() => this.init(), 100);
        }
    }

    init() {
        this.editor = CodeMirror.fromTextArea(this.textarea, {
            mode: "application/json",
            lineNumbers: true,
            theme: "monokai",
            lint: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            gutters: ["CodeMirror-lint-markers"]
        });
        this.updateEditor();

        // Force refresh after initial render so content is visible without clicking
        setTimeout(() => this.editor.refresh(), 200);
        setTimeout(() => this.editor.refresh(), 500);

        this.state.subscribe((_, type) => {
            if (!this.editor.hasFocus()) {
                 this.updateEditor();
            }
            if (type === 'selectionChanged') {
                const el = this.state.getSelectedElement();
                if (el) this.highlightElement(el.id);
            }
        });
        this.editor.on('change', (cm, changeObj) => {
             if (changeObj.origin === 'setValue') return;
             try {
                 const val = this.editor.getValue();
                 JSON.parse(val);
                 this.state.updateManifestJson(val);
             } catch (e) { }
        });

        window.addEventListener('resize', () => {
             this.editor.refresh();
        });
    }

    updateEditor() {
        if (!this.editor) return;
        const cursor = this.editor.getCursor();
        const json = JSON.stringify(this.state.manifest, null, 4); // Indent 4
        if (this.editor.getValue() !== json) {
            this.editor.setValue(json);
            this.editor.setCursor(cursor);
        }
    }

    highlightElement(id) {
        if (!this.editor) return;
        const searchString = `"id": "${id}"`;

        setTimeout(() => {
            const content = this.editor.getValue();

            // Find match in current orientation's layout section first
            const layoutIndex = content.indexOf('"layout"');
            const orientationKey = `"${this.state.currentOrientation}"`;
            const orientationIndex = layoutIndex >= 0 ? content.indexOf(orientationKey, layoutIndex) : -1;

            let matchIndex = -1;
            if (orientationIndex >= 0) {
                matchIndex = content.indexOf(searchString, orientationIndex);
            }
            if (matchIndex < 0) {
                matchIndex = content.indexOf(searchString);
            }
            if (matchIndex < 0) return;

            const from = this.editor.posFromIndex(matchIndex);
            const to = this.editor.posFromIndex(matchIndex + searchString.length);

            this.editor.setSelection(from, to);
            this.editor.scrollIntoView({line: from.line, ch: 0}, 200);
            this.editor.addLineClass(from.line, "background", "cm-highlight-line");
            setTimeout(() => {
                this.editor.removeLineClass(from.line, "background", "cm-highlight-line");
            }, 2000);
        }, 50);
    }


    initResize() {
        const handle = document.getElementById('json-resize-handle');
        const panel = document.getElementById('json-editor-panel');
        if (!handle || !panel) return;

        let startY, startHeight;

        const onDrag = (e) => {
            const dy = startY - e.clientY;
            let newHeight = startHeight + dy;
            if (newHeight < 100) newHeight = 100;
            if (newHeight > window.innerHeight - 100) newHeight = window.innerHeight - 100;
            panel.style.height = `${newHeight}px`;
            this.editor.refresh();
        };

        const onStop = () => {
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup', onStop);
        };

        handle.addEventListener('mousedown', (e) => {
            if (!panel.classList.contains('docked')) return; // Only resize if docked
            startY = e.clientY;
            startHeight = parseInt(window.getComputedStyle(panel).height, 10);
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', onStop);
            e.preventDefault();
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const appEl = document.getElementById('designer-app');
    const skinData = JSON.parse(appEl.dataset.skin);
    const assetsData = JSON.parse(appEl.dataset.assets);

    const state = new SkinState(skinData, assetsData);
    const sampleData = new SampleData(state);
    sampleData.fetchSampleData();

    new SkinRenderer('preview-container', state, sampleData);
    new ElementTree('element-tree', state);
    new SampleDataEditor('sample-data-editor', sampleData);
    new PropertyEditor('property-editor', state);
    new JsonEditor('json-editor-textarea', state);

    state.notify('init');

    // Orientation switching
    const orientBtns = document.querySelectorAll('[data-orientation]');
    orientBtns.forEach(btn => {
        btn.onclick = () => {
             orientBtns.forEach(b => b.classList.remove('active'));
             btn.classList.add('active');
             state.setOrientation(btn.dataset.orientation);
        };
    });

    // Toggle Landscape/Portrait on init if needed or just simulate click on default
    document.querySelector(`[data-orientation="portrait"]`)?.click();

    // Modal Handling
    const addModal = document.getElementById('add-element-modal');
    const addBtn = document.getElementById('add-element-btn');
    const closeBtn = document.getElementById('modal-close-btn');

    if (addBtn) addBtn.onclick = () => addModal.classList.remove('hidden');
    if (closeBtn) closeBtn.onclick = () => addModal.classList.add('hidden');

    // Close modal on click outside
    if (addModal) {
        addModal.addEventListener('click', (e) => {
            if (e.target === addModal) addModal.classList.add('hidden');
        });
    }

    // JSON Editor Toggling and Floating
    const jsonPanel = document.getElementById('json-editor-panel');
    const jsonToggleBtn = document.getElementById('json-toggle-btn');
    const jsonCloseBtn = document.getElementById('json-close-btn');
    const jsonFloatBtn = document.getElementById('json-float-btn');

    // Init docked state
    if (jsonPanel) jsonPanel.classList.add('docked');

    if (jsonToggleBtn) jsonToggleBtn.onclick = () => {
        jsonPanel.classList.toggle('hidden');
        if (!jsonPanel.classList.contains('hidden')) {
            setTimeout(() => window.dispatchEvent(new Event('resize')), 100);
        }
    };
    if (jsonCloseBtn) jsonCloseBtn.onclick = () => jsonPanel.classList.add('hidden');

    if (jsonFloatBtn) {
        jsonFloatBtn.onclick = () => {
            if (jsonPanel.classList.contains('docked')) {
                // Switch to Floating
                jsonPanel.classList.remove('docked');
                jsonPanel.classList.add('floating');
                jsonFloatBtn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i>'; // Icon for dock
                // Make draggable
                makeDraggable(jsonPanel);
            } else {
                // Switch to Docked
                jsonPanel.classList.remove('floating');
                jsonPanel.classList.add('docked');
                jsonFloatBtn.innerHTML = '<i class="fas fa-expand-arrows-alt"></i>'; // Icon for float
                // Clear drag styles
                jsonPanel.style.top = '';
                jsonPanel.style.left = '';
                jsonPanel.style.width = '';
                jsonPanel.style.height = '';
                jsonPanel.style.transform = '';
            }
            // Trigger editor refresh
            setTimeout(() => window.dispatchEvent(new Event('resize')), 100);
        };
    }

    // Draggable Helper
    function makeDraggable(el) {
        const header = el.querySelector('.json-header');
        if (!header) return;

        let isDragging = false;
        let startX, startY, initialLeft, initialTop;

        header.onmousedown = (e) => {
            if (el.classList.contains('docked')) return; // No drag if docked
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            const rect = el.getBoundingClientRect();
            // We need to work with offset/absolute positioning
            // Ideally we set left/top relative to viewport if fixed, or parent if absolute.
            // .floating is absolute.
            initialLeft = el.offsetLeft;
            initialTop = el.offsetTop; // caution: this might be bottom-aligned by css initially

            // If it was bottom-aligned, we should switch to top/left for dragging
            el.style.bottom = 'auto';
            el.style.right = 'auto';
            el.style.left = `${initialLeft}px`;
            el.style.top = `${initialTop}px`;

            document.onmousemove = (e) => {
                if (!isDragging) return;
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                el.style.left = `${initialLeft + dx}px`;
                el.style.top = `${initialTop + dy}px`;
            };

            document.onmouseup = () => {
                isDragging = false;
                document.onmousemove = null;
                document.onmouseup = null;
            };
        };
    }



    // Collapsible Sample Data
    const sdHeader = document.getElementById('sample-data-header');
    const sdWrapper = document.getElementById('sample-data-wrapper');
    if (sdHeader && sdWrapper) {
        sdHeader.onclick = () => {
            const isCollapsed = sdWrapper.classList.contains('collapsed');
            if (isCollapsed) {
                sdWrapper.classList.remove('collapsed');
                sdHeader.querySelector('i').className = 'fas fa-chevron-down';
            } else {
                sdWrapper.classList.add('collapsed');
                sdHeader.querySelector('i').className = 'fas fa-chevron-up';
            }
        };
    }



    // We need to implement Jump to Line in JsonEditor class.


    // Add Element Type Handlers
    document.querySelectorAll('.element-type-btn').forEach(btn => {
        btn.onclick = () => {
            const type = btn.dataset.type;
            const dims = state.getDimensions();
            const newEl = {
                type: type,
                x: Math.floor(dims.width / 2 - 50),
                y: Math.floor(dims.height / 2 - 50),
                width: 100,
                height: 100,
                id: type
            };
            if (type === 'text') { newEl.text = 'New Text'; newEl.height = 30; newEl.width = 200; }
            state.addElement(newEl);
            addModal.classList.add('hidden');
        };
    });

    // Save Button
    document.getElementById('save-skin-btn').onclick = () => {
         const btn = document.getElementById('save-skin-btn');
         const originalHTML = btn.innerHTML;
         btn.disabled = true;
         btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
         const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

         fetch(`/gallery/skins/${skinData.id}/manifest`, {
             method: 'POST',
             headers: {
                 'Content-Type': 'application/json',
                 'X-CSRF-TOKEN': csrfToken,
                 'Accept': 'application/json'
             },
             body: JSON.stringify({
                 manifest: state.manifest,
                 name: state.skin.name,
                 author: state.manifest.author,
                 version: state.manifest.version
             })
         })
         .then(res => res.json())
         .then(data => {
             if(data.success) {
                 btn.innerHTML = '<i class="fas fa-check"></i> Saved';
                 setTimeout(() => { btn.innerHTML = originalHTML; btn.disabled = false; }, 2000);
             } else {
                 alert('Error: ' + (data.error || 'Unknown'));
                 btn.innerHTML = originalHTML; btn.disabled = false;
             }
         })
         .catch(err => {
             alert('Error: ' + err.message);
             btn.innerHTML = originalHTML; btn.disabled = false;
         });
    };

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        // Undo/Redo
        if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
            e.preventDefault();
            if (e.shiftKey) state.redo();
            else state.undo();
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
             e.preventDefault();
             state.redo();
        }

        // Delete
        if (e.key === 'Delete' || e.key === 'Backspace') {
             const el = state.getSelectedElement();
             if (el) state.removeElement(el.id);
        }

        // Arrow Movement
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
             const el = state.getSelectedElement();
             if (el) {
                 e.preventDefault();
                 const step = e.shiftKey ? 10 : 1;
                 const updates = {};
                 if (e.key === 'ArrowLeft') updates.x = (el.x || 0) - step;
                 if (e.key === 'ArrowRight') updates.x = (el.x || 0) + step;
                 if (e.key === 'ArrowUp') updates.y = (el.y || 0) - step;
                 if (e.key === 'ArrowDown') updates.y = (el.y || 0) + step;
                 state.updateElement(el.id, updates);
             }
        }
    });
});
