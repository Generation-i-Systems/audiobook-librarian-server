import { buildAudioPreviewUrl } from "@/admin/books/audio-preview-url.js";

describe("book form audio preview URLs", () => {
    test("uses the book playback route and encodes each filename segment", () => {
        expect(
            buildAudioPreviewUrl(
                "/admin/books/11634/play/__FILE__",
                "Disc 1/Track #1 [final].m4b",
            ),
        ).toBe(
            "/admin/books/11634/play/Disc%201/Track%20%231%20%5Bfinal%5D.m4b",
        );
    });

    test("returns null when no persisted book playback route is available", () => {
        expect(buildAudioPreviewUrl(undefined, "chapter.m4b")).toBeNull();
    });
});
