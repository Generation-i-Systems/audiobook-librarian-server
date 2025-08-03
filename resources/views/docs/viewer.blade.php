<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - Audiobook Librarian Docs</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f8f9fa;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; margin-top: 30px; }
        h3 { color: #5d6d7e; margin-top: 25px; }
        code {
            background: #f1f2f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 0.9em;
        }
        pre {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 20px 0;
        }
        pre code {
            background: none;
            padding: 0;
            color: #ecf0f1;
        }
        a {
            color: #3498db;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        ul {
            margin-left: 20px;
        }
        li {
            margin: 8px 0;
        }
        .breadcrumb {
            background: #ecf0f1;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
        .nav {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 200px;
        }
        .nav h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
        }
        .nav a {
            display: block;
            padding: 5px 0;
            font-size: 0.9em;
        }
        @media (max-width: 768px) {
            .nav { position: static; margin-bottom: 20px; }
            body { padding: 10px; }
            .container { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="nav">
        <h4>Quick Links</h4>
        <a href="/docs">📚 Docs Home</a>
        <a href="/docs/openapi.json" target="_blank">📄 OpenAPI JSON</a>
        <a href="/docs/api/authentication">🔐 Authentication</a>
        <a href="/docs/api/examples">💻 Code Examples</a>
        <a href="/docs/api/middleware">⚙️ Middleware</a>
        <a href="/docs/api/README">📖 API Overview</a>
    </div>

    <div class="container">
        @if($path !== 'index.md')
        <div class="breadcrumb">
            <a href="/docs">📚 Documentation Home</a> → {{ $title }}
        </div>
        @endif

        <div class="content">
            {!! $content !!}
        </div>

        <hr style="margin: 40px 0; border: none; border-top: 1px solid #bdc3c7;">
        
        <div style="text-align: center; color: #7f8c8d; font-size: 0.9em;">
            <p>
                <strong>Audiobook Librarian API Documentation</strong><br>
                <a href="/docs">📚 Documentation Home</a> | 
                <a href="/docs/openapi.json" target="_blank">📄 OpenAPI Specification</a> | 
                <a href="/api/v1" target="_blank">🔗 API Base URL</a>
            </p>
        </div>
    </div>

    <script>
        // Add syntax highlighting for code blocks
        document.querySelectorAll('pre code').forEach((block) => {
            block.style.fontFamily = "'Monaco', 'Consolas', 'Courier New', monospace";
        });
        
        // Make external links open in new tab
        document.querySelectorAll('a[href^="http"]').forEach((link) => {
            link.setAttribute('target', '_blank');
        });
    </script>
</body>
</html>