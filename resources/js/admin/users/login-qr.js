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
    const detailsEl = document.getElementById("login-qr-details");
    const serverEl = document.getElementById("login-qr-server");
    const usernameEl = document.getElementById("login-qr-username");
    const codeEl = document.getElementById("login-qr-code");
    const emailEl = document.getElementById("login-qr-email");
    const expiryEl = document.getElementById("login-qr-expiry");
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
                if (detailsEl && serverEl && usernameEl && codeEl && emailEl && expiryEl) {
                    try {
                        const serverName = new URL(data.url).origin;
                        serverEl.textContent = `Server: ${data.server_name ? `${data.server_name} (${serverName})` : serverName}`;
                        usernameEl.textContent = data.username ? `Username: ${data.username}` : "";
                        codeEl.textContent = data.code ? `One-time code: ${data.code}` : "";
                        emailEl.textContent = data.email ? `Email: ${data.email}` : "";
                        const minutes = Math.max(1, Math.round((data.expires_in_seconds || 600) / 60));
                        expiryEl.textContent = `Expires in ${minutes} minutes, after one use, or after 5 wrong attempts.`;
                        detailsEl.style.display = "block";
                    } catch (e) {
                        detailsEl.style.display = "none";
                    }
                }
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
