<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use App\Models\StaffApplicationFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CVs and supporting documents stream from the confidential disk to authorised reviewers only. */
class StaffApplicationFileController extends Controller
{
    public function __invoke(StaffApplicationFile $file): StreamedResponse
    {
        $file->load('application');
        Gate::authorize('review', $file->application);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        Audit::record('staff_application.file_downloaded', "Downloaded {$file->kind} file for {$file->application->reference}", $file->application,
            context: ['file_id' => $file->id, 'original_name' => $file->original_name]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
