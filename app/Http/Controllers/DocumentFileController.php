<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Documents;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams confidential document versions from the private disk. Every request is authorised and
 * logged. Only PDF and raster images are ever shown inline; everything else is a forced download.
 */
class DocumentFileController extends Controller
{
    public function __construct(private Documents $documents) {}

    /** Staff: any version of a document they may view. */
    public function staff(Request $request, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $version->document);

        return $this->stream($request, $version);
    }

    /** Client: only the version released to them (or their own upload), never a later internal draft. */
    public function client(Request $request, DocumentVersion $version): StreamedResponse
    {
        $document = $version->document;
        Gate::authorize('viewAsClient', $document);
        abort_unless($document->clientVersion()?->id === $version->id, 404);

        return $this->stream($request, $version);
    }

    private function stream(Request $request, DocumentVersion $version): StreamedResponse
    {
        abort_unless(Storage::disk($version->disk)->exists($version->path), 404);
        $inline = $request->boolean('inline') && $version->isSafeInline();
        $this->documents->recordAccess($version, $request->user(), $inline);

        $headers = [
            'Content-Type' => $version->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ];

        return $inline
            ? Storage::disk($version->disk)->response($version->path, $version->original_name, $headers, 'inline')
            : Storage::disk($version->disk)->download($version->path, $version->original_name, $headers);
    }
}
