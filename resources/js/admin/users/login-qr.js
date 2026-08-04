import QRCode from "qrcode";
import { renderAppConnectQr } from "../../app-connect-qr";

function initLoginQrModal() {
    const trigger = document.getElementById("show-login-qr-btn");
    if (!trigger) {
        return;
    }

    const userId = trigger.getAttribute("data-user-id");
    const container = document.getElementById("login-qr-container");
    const errorEl = document.getElementById("login-qr-error");
    let loaded = false;

    trigger.addEventListener("click", () => {
        if (loaded) {
            return;
        }
        loaded = true;

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute("content");

        fetch(`/admin/users/${userId}/login-qr`, {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": csrfToken,
                Accept: "application/json",
            },
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    throw new Error(data.message || "Failed to generate login QR code.");
                }
                container.setAttribute("data-connect-url", data.url);
                renderAppConnectQr(container, QRCode.toCanvas);
                if (errorEl) {
                    errorEl.style.display = "none";
                }
            })
            .catch((error) => {
                loaded = false;
                if (errorEl) {
                    errorEl.textContent = error.message;
                    errorEl.style.display = "block";
                }
            });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initLoginQrModal);
} else {
    initLoginQrModal();
}
