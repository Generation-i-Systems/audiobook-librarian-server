<!DOCTYPE html>
<html>
<head>
    <title>Success</title>
    <script>
        function tryClose() {
            try {
                // Refresh opener if it exists
                if (window.opener && !window.opener.closed) {
                    try {
                        window.opener.location.reload();
                    } catch (e) {
                        console.log('Opener reload failed (likely cross-origin)');
                    }
                }

                // Compatibility trick for modern browsers
                window.open('', '_self', '');
                window.close();
            } catch (e) {
                console.log('Close attempt failed');
            }
        }

        window.onload = function() {
            tryClose();
            // Aggressive retry sequence in case of racing/loading issues
            setTimeout(tryClose, 50);
            setTimeout(tryClose, 200);
            setTimeout(tryClose, 500);
            setTimeout(tryClose, 1000);
            setTimeout(tryClose, 2000);
        };
    </script>
</head>
<body style="background-color: #ffffff;">
    <!-- Entirely empty to avoid user frustration with fallback messages -->
</body>
</html>
