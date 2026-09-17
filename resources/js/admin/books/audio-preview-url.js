export function buildAudioPreviewUrl(route, filePath) {
    if (!route) {
        return null;
    }

    const encodedFilePath = filePath
        .split("/")
        .map(function (segment) {
            return encodeURIComponent(segment);
        })
        .join("/");

    return route.replace("__FILE__", encodedFilePath);
}
