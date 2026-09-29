<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Backups;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams one encrypted backup to the technical administrator who confirmed their password on the Operations
 * page. The signed link lasts five minutes and only works for that user's own verified staff session.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, Backups $backups, string $name): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->isActive() && $user->hasRole(Role::TechnicalAdministrator), 403);
        abort_unless((int) $request->query('user') === $user->getKey(), 403);
        $path = $backups->find($name);
        abort_unless($path, 404);

        Audit::record('backup.downloaded', "Backup {$name} downloaded", context: ['size' => filesize($path)]);

        $response = response()->download($path, $name, ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff']);
        // BinaryFileResponse marks itself public by default; this must never sit in a shared cache.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
