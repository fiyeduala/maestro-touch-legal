<?php

namespace App\Http\Controllers\Site;

use App\Domain\Content\PostViews;
use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Receives the blog post page's "view" and "read" reports (D51). Always answers 204, counted or not. */
class PostBeaconController extends Controller
{
    public function __invoke(Request $request, PostViews $views): Response
    {
        $data = $request->validate([
            'post' => ['required', 'integer'],
            'event' => ['required', 'in:view,read'],
            'referrer' => ['nullable', 'string', 'max:2000'],
        ]);

        $post = Post::visible()->whereKey($data['post'])->first();
        if ($post) {
            $views->record($post, $data['event'], $request->user(), $request->ip(), $request->userAgent(), $data['referrer'] ?? null);
        }

        return response()->noContent();
    }
}
