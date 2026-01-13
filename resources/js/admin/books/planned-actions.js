(function () {
    const initialState = {
        directoryPath: '',
        coverImageUrl: '',
        audibleCoverImageUrl: '',
    };

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }

        const hidden = document.querySelector('input[name="_token"]');
        return hidden ? hidden.value : '';
    }

    function getPlannedActionsUrl() {
        if (window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.plannedActions) {
            return window.BOOK_FORM_ROUTES.plannedActions;
        }

        return '';
    }

    function collectPayload() {
        const directoryPath = (document.getElementById('directoryPath')?.value || '').trim();
        const coverImageUrl = (document.getElementById('coverImageUrl')?.value || '').trim();
        const audibleCoverImageUrl = (document.getElementById('audibleCoverImageUrl')?.value || '').trim();

        return {
            directoryPath: directoryPath,
            coverImageUrl: coverImageUrl,
            audibleCoverImageUrl: audibleCoverImageUrl,
        };
    }

    function hasPendingChanges(actions) {
        if (!Array.isArray(actions) || actions.length === 0) {
            return false;
        }

        if (actions.length === 1 && actions[0] && actions[0].type === 'no_op') {
            return false;
        }

        return true;
    }

    function revertPendingChanges() {
        const directoryPath = document.getElementById('directoryPath');
        const coverImageUrl = document.getElementById('coverImageUrl');
        const audibleCoverImageUrl = document.getElementById('audibleCoverImageUrl');
        const coverText = document.getElementById('coverImageUrlText');

        if (directoryPath) {
            const original = document.querySelector('input[name="originalDirectoryPath"]')?.value;
            directoryPath.value = (original || initialState.directoryPath || '').trim();
        }

        if (coverImageUrl) {
            coverImageUrl.value = (initialState.coverImageUrl || '').trim();
        }

        if (audibleCoverImageUrl) {
            audibleCoverImageUrl.value = (initialState.audibleCoverImageUrl || '').trim();
        }

        if (coverText) {
            coverText.value = (initialState.audibleCoverImageUrl || initialState.coverImageUrl || '').trim();
        }

        document.querySelectorAll('input[name="coverImageCandidate"]').forEach(function (radio) {
            radio.checked = false;
        });

        refreshPlannedActions();
    }

    async function executeImmediateMove() {
        const oldPath = document.querySelector('input[name="originalDirectoryPath"]')?.value?.trim();
        const newPath = document.getElementById('directoryPath')?.value?.trim();

        if (!oldPath || !newPath) {
            alert('Cannot execute move: missing directory paths');
            return;
        }

        if (oldPath === newPath) {
            alert('Old and new paths are the same');
            return;
        }

        if (!window.BOOK_FORM_ROUTES || !window.BOOK_FORM_ROUTES.executeImmediateMove) {
            alert('Move URL not configured');
            return;
        }

        if (!confirm('This will immediately move the book directory from:\n\n' + oldPath + '\n\nto:\n\n' + newPath + '\n\nContinue?')) {
            return;
        }

        try {
            const response = await fetch(window.BOOK_FORM_ROUTES.executeImmediateMove, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    oldDirectoryPath: oldPath,
                    newDirectoryPath: newPath,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert('Failed to move directory: ' + (data.message || 'Unknown error'));
                return;
            }

            // Update the form with the actual moved path
            if (data.directoryPath) {
                const directoryPathInput = document.getElementById('directoryPath');
                if (directoryPathInput) {
                    directoryPathInput.value = data.directoryPath;
                }

                // Update the original path so we don't try to move again on submit
                const originalPathInput = document.querySelector('input[name="originalDirectoryPath"]');
                if (originalPathInput) {
                    originalPathInput.value = data.directoryPath;
                }

                // Update initial state
                initialState.directoryPath = data.directoryPath;
            }

            // Note: We don't need to update coverImage input as it's a file input
            // The cover image moves with the directory and the database is already updated

            alert('Directory moved successfully!');

            // Refresh planned actions to show updated state
            refreshPlannedActions();
        } catch (error) {
            alert('Error executing move: ' + error.message);
        }
    }

    function setPreview(actions, lines) {
        const preview = document.getElementById('planned-actions-preview');
        if (!preview) {
            return;
        }

        if (!Array.isArray(lines) || lines.length === 0) {
            preview.style.display = 'none';
            preview.textContent = '';
            return;
        }

        preview.style.display = 'block';

        while (preview.firstChild) {
            preview.removeChild(preview.firstChild);
        }

        const messageSpan = document.createElement('span');
        messageSpan.textContent = lines.join(' | ');
        preview.appendChild(messageSpan);

        if (hasPendingChanges(actions)) {
            const spacer = document.createTextNode(' ');
            preview.appendChild(spacer);

            // Check if there's a move_files action
            const hasMoveAction = actions.some(function(action) {
                return action && action.type === 'move_files';
            });

            // Add "Update Now" button if there's a move action
            if (hasMoveAction && window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.executeImmediateMove) {
                const updateNowButton = document.createElement('button');
                updateNowButton.type = 'button';
                updateNowButton.className = 'btn btn-sm btn-primary ms-2';
                updateNowButton.textContent = 'Update Now';
                updateNowButton.title = 'Execute the directory move immediately (before form submission)';
                updateNowButton.addEventListener('click', function () {
                    executeImmediateMove();
                });

                preview.appendChild(updateNowButton);
            }

            const revertButton = document.createElement('button');
            revertButton.type = 'button';
            revertButton.className = 'btn btn-sm btn-outline-secondary ms-2';
            revertButton.textContent = 'Revert';
            revertButton.addEventListener('click', function () {
                revertPendingChanges();
            });

            preview.appendChild(revertButton);
        }
    }

    async function refreshPlannedActions() {
        const url = getPlannedActionsUrl();
        if (!url) {
            return;
        }

        const payload = collectPayload();

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            const actions = Array.isArray(data.actions) ? data.actions : [];
            const lines = actions
                .map(function (a) {
                    return a && a.message ? String(a.message) : '';
                })
                .filter(function (m) {
                    return m !== '';
                });

            setPreview(actions, lines);
        } catch (e) {
        }
    }

    function syncCoverUrlTextToHidden() {
        const coverText = document.getElementById('coverImageUrlText');
        if (!coverText) {
            return;
        }

        const coverImageUrl = document.getElementById('coverImageUrl');
        const audibleCoverImageUrl = document.getElementById('audibleCoverImageUrl');

        const value = coverText.value.trim();
        if (coverImageUrl) {
            coverImageUrl.value = value;
        }
        if (audibleCoverImageUrl) {
            audibleCoverImageUrl.value = '';
        }
    }

    function init() {
        const directoryPath = document.getElementById('directoryPath');
        const coverText = document.getElementById('coverImageUrlText');

        initialState.directoryPath = (directoryPath?.value || '').trim();
        initialState.coverImageUrl = (document.getElementById('coverImageUrl')?.value || '').trim();
        initialState.audibleCoverImageUrl = (document.getElementById('audibleCoverImageUrl')?.value || '').trim();

        if (directoryPath) {
            directoryPath.addEventListener('blur', refreshPlannedActions);
            directoryPath.addEventListener('change', refreshPlannedActions);
        }

        if (coverText) {
            coverText.addEventListener('blur', function () {
                syncCoverUrlTextToHidden();
                refreshPlannedActions();
            });
        }

        document.querySelectorAll('input[name="coverImageCandidate"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const preview = document.getElementById('planned-actions-preview');
                if (preview) {
                    preview.style.display = 'none';
                }
                refreshPlannedActions();
            });
        });

        refreshPlannedActions();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
