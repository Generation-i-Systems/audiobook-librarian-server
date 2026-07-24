export function renderAppConnectQr(container, toCanvas) {
    if (!container) {
        return;
    }

    const connectUrl = container?.getAttribute("data-connect-url");
    if (!connectUrl || typeof toCanvas !== "function") {
        container.textContent = connectUrl || "No connection link available.";
        return;
    }

    container.innerHTML = "";
    const canvas = document.createElement("canvas");
    container.appendChild(canvas);

    toCanvas(canvas, connectUrl, {
        width: 200,
        margin: 1,
    }, (error) => {
        if (error) {
            console.error("[app-connect-qr] Failed to render QR code", error);
            container.textContent = connectUrl;
        }
    });
}

export function initializeAppConnectQrs(toCanvas) {
    document.querySelectorAll(".app-connect-qr").forEach((container) => {
        renderAppConnectQr(container, toCanvas);
    });
}
