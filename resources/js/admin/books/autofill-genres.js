function decodeHtmlEntities(text) {
    if (!text || typeof text !== "string") {
        return text;
    }

    const textarea = document.createElement("textarea");
    textarea.innerHTML = text;
    return textarea.value;
}

export function getGenreList(result) {
    if (Array.isArray(result.category) && result.category.length) {
        return result.category.join(", ");
    }

    if (Array.isArray(result.categories) && result.categories.length) {
        return result.categories.join(", ");
    }

    if (Array.isArray(result.genre) && result.genre.length) {
        return result.genre.join(", ");
    }

    if (Array.isArray(result.genres) && result.genres.length) {
        return result.genres
            .map(function (genre) {
                return (
                    (typeof genre === "object" &&
                        genre.genre &&
                        genre.genre.name) ||
                    (typeof genre === "string" && genre) ||
                    ""
                );
            })
            .filter(Boolean)
            .join(", ");
    }

    return "";
}

function getSelectedGenreValues($form) {
    return $form
        .find('#genres-group select[name="genre[]"]')
        .toArray()
        .map(function (select) {
            return String(select.value || "").trim();
        })
        .filter(Boolean);
}

function getConfiguredGenreValues($form) {
    return new Set(
        $form
            .find('#genres-group select[name="genre[]"] option')
            .toArray()
            .map(function (option) {
                return String(option.value || "").trim();
            })
            .filter(Boolean),
    );
}

export function getAutofillGenreValues(item, $form) {
    const configuredGenres = getConfiguredGenreValues($form);
    const seen = new Set();

    return getGenreList(item)
        .split(",")
        .map(function (genre) {
            return decodeHtmlEntities(genre).trim();
        })
        .filter(function (genre) {
            if (!genre || seen.has(genre) || !configuredGenres.has(genre)) {
                return false;
            }

            seen.add(genre);
            return true;
        });
}

export function applyAutofillGenres(item, $form, addGenreRow) {
    if (!$form.length || !item || typeof addGenreRow !== "function") {
        return;
    }

    if (getSelectedGenreValues($form).length > 0) {
        return;
    }

    const genres = getAutofillGenreValues(item, $form);
    if (genres.length === 0) {
        return;
    }

    const blankSelects = $form
        .find('#genres-group select[name="genre[]"]')
        .toArray()
        .filter(function (select) {
            return !String(select.value || "").trim();
        });

    if (blankSelects.length > 0) {
        blankSelects[0].value = genres.shift();
    }

    genres.forEach(function (genre) {
        addGenreRow($form, genre);
    });
}
