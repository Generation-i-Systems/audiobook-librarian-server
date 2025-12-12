<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Audiobooks by Your Favorite Authors</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #4CAF50;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 0 0 5px 5px;
        }
        .book-item {
            background-color: white;
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            gap: 15px;
        }
        .book-cover {
            flex-shrink: 0;
        }
        .book-cover img {
            width: 100px;
            height: auto;
            border-radius: 3px;
        }
        .book-details {
            flex-grow: 1;
        }
        .book-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .book-author {
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        .book-narrator {
            color: #95a5a6;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .book-category {
            display: inline-block;
            background-color: #3498db;
            color: white;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 12px;
            margin-right: 5px;
        }
        .book-link {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 15px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 3px;
        }
        .book-link:hover {
            background-color: #45a049;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            color: #7f8c8d;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📚 New Audiobooks Available!</h1>
    </div>

    <div class="content">
        <p>Hello {{ $userName }},</p>

        <p>We found {{ count($books) }} new audiobook{{ count($books) > 1 ? 's' : '' }} by your favorite authors:</p>

        @foreach($books as $book)
            <div class="book-item">
                @if($book['cover_url'])
                    <div class="book-cover">
                        <img src="{{ $book['cover_url'] }}" alt="{{ $book['title'] }} cover">
                    </div>
                @endif

                <div class="book-details">
                    <div class="book-title">{{ $book['title'] }}</div>

                    @if($book['author'])
                        <div class="book-author">
                            <strong>By:</strong> {{ $book['author'] }}
                        </div>
                    @endif

                    @if($book['narrator'])
                        <div class="book-narrator">
                            <strong>Narrated by:</strong> {{ $book['narrator'] }}
                        </div>
                    @endif

                    @if($book['category'])
                        <div>
                            <span class="book-category">{{ ucfirst($book['category']) }}</span>
                        </div>
                    @endif

                    @if($book['description'])
                        <p style="font-size: 14px; color: #666; margin-top: 10px;">
                            {{ Str::limit($book['description'], 200) }}
                        </p>
                    @endif

                    <a href="{{ $book['url'] }}" class="book-link">View on AudiobookBay</a>
                </div>
            </div>
        @endforeach

        <div class="footer">
            <p>You're receiving this email because you've favorited these authors in your Librarian account.</p>
            <p>To manage your favorite authors, log in to your account.</p>
        </div>
    </div>
</body>
</html>
