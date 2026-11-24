<?php

use Illuminate\Support\Facades\Route;
use Asyntai\Chatbot\Classes\Feed;

// The signed, read-only feed Asyntai reads shared pages from. No session:
// every call carries its own signature, and anything else gets a 403.
// Settings changes go through the backend controller, never through here.

Route::get('asyntai/chatbot/feed', function () {
    list($status, $body) = Feed::products(request()->query());

    return response()->json($body, $status, [
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex',
    ]);
});

Route::get('asyntai/chatbot/feed/pages', function () {
    list($status, $body) = Feed::pages(request()->query());

    return response()->json($body, $status, [
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex',
    ]);
});
