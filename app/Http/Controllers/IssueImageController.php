<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowIssueImageRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class IssueImageController
{
    public function show(ShowIssueImageRequest $request, string $image): StreamedResponse
    {
        return Storage::disk()->response('commit-images/'.$image, $image, headers: [
            'Content-Type' => 'image/webp',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ], disposition: 'inline');
    }
}
