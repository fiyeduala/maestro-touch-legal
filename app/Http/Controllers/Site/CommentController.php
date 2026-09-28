<?php

namespace App\Http\Controllers\Site;

use App\Domain\Operations\Settings;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mews\Purifier\Facades\Purifier;

/** Public comments are held for moderation and only accepted while the owner has comments switched on. */
class CommentController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $post = Post::visible()->where('slug', $slug)->firstOrFail();
        abort_unless($post->comments_visible && Settings::get('content.comments_open'), 403);

        // Honeypot: pretend success so bots learn nothing.
        if ($request->filled('website_url')) {
            return redirect()->to($post->url().'#reply-heading')->with('comment_status', 'Thank you. Your comment is awaiting moderation.');
        }

        $data = $request->validate([
            'comment' => ['required', 'string', 'min:2', 'max:5000'],
            'author_name' => ['required', 'string', 'max:100'],
            'author_email' => ['required', 'email', 'max:190'],
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author_name' => strip_tags($data['author_name']),
            'author_email' => $data['author_email'],
            'body' => Purifier::clean(nl2br(e($data['comment']), false), 'comment'),
            'status' => 'pending',
            'posted_at' => now(),
        ]);

        return redirect()->to($post->url().'#reply-heading')->with('comment_status', 'Thank you. Your comment is awaiting moderation.');
    }
}
