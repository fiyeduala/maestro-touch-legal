<?php

namespace App\Domain\Content;

use App\Models\Post;
use App\Models\PostView;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Blog analytics (D51). The post page reports a "view" when it opens and a "read" when the visitor
 * reaches the end of the article after spending some time on it. Each visitor counts once per post
 * per day. Staff are never counted. Anonymous visitors are a daily keyed hash of their address and
 * browser, so the address itself is never stored; signed-in clients are recorded by account.
 */
class PostViews
{
    /** Browsers that announce themselves as robots, link previews or monitoring. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|scan|monitor|headless|lighthouse|curl|wget|python|java\/|go-http|okhttp|axios|facebookexternalhit|whatsapp|telegram|discord|embedly|bingpreview|pingdom|uptime/i';

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /** Whether this visit should be counted at all. */
    public static function counts(?User $user, ?string $userAgent): bool
    {
        return ! ($user && $user->isStaff()) && ! self::isBot($userAgent);
    }

    public static function visitorHash(?User $user, ?string $ip, ?string $userAgent, CarbonInterface $day): string
    {
        $subject = $user ? 'user:'.$user->getKey() : 'anon:'.$ip.'|'.$userAgent;

        return hash_hmac('sha256', $subject, config('app.key').'|post-views|'.$day->toDateString());
    }

    /** Records a view, or marks today's view as read. Returns false when nothing was counted. */
    public function record(Post $post, string $event, ?User $user, ?string $ip, ?string $userAgent, ?string $referrer = null): bool
    {
        if (! self::counts($user, $userAgent)) {
            return false;
        }

        $day = now(config('app.firm_timezone'))->startOfDay();
        $key = [
            'post_id' => $post->id,
            'visitor_hash' => self::visitorHash($user, $ip, $userAgent, $day),
            'viewed_on' => $day->toDateString(),
        ];

        PostView::query()->insertOrIgnore($key + [
            'user_id' => $user?->getKey(),
            'referrer_host' => self::referrerHost($referrer),
            'source' => 'beacon',
            'created_at' => now(),
        ]);

        if ($event === 'read') {
            PostView::query()->where($key)->whereNull('read_at')->update(['read_at' => now()]);
        }

        return true;
    }

    /** The other site the visitor came from, or null for direct visits and links within this site. */
    public static function referrerHost(?string $referrer): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(preg_replace('/^www\./i', '', $host));
        $own = strtolower(preg_replace('/^www\./i', '', (string) parse_url(config('app.url'), PHP_URL_HOST)));

        return $host === $own ? null : substr($host, 0, 190);
    }

    /**
     * Past visits from a web server access log ("combined" format, as cPanel keeps them). Only
     * successful GET requests for a published post's address are counted, robots are skipped, and
     * each address and browser counts once per post per day, as live visits do. These are page
     * loads, not reads, and behind Cloudflare the logged address may be Cloudflare's, so treat the
     * numbers as approximate.
     *
     * @param  iterable<string>  $lines
     * @return array{lines: int, counted: int}
     */
    public function importAccessLog(iterable $lines): array
    {
        $posts = Post::query()->whereNotNull('published_at')->pluck('id', 'slug');
        $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "GET (\S+) HTTP\/[\d.]+" (\d{3}) \S+ "([^"]*)" "([^"]*)"/';
        $read = 0;
        $counted = 0;

        foreach ($lines as $line) {
            $read++;
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }
            [, $ip, $time, $path, $status, $referrer, $agent] = $m;
            $slug = trim((string) parse_url($path, PHP_URL_PATH), '/');
            if ($status !== '200' || ! isset($posts[$slug]) || self::isBot($agent)) {
                continue;
            }
            $at = Carbon::createFromFormat('d/M/Y:H:i:s O', $time);
            if (! $at) {
                continue;
            }
            $day = $at->copy()->timezone(config('app.firm_timezone'))->startOfDay();

            $counted += PostView::query()->insertOrIgnore([
                'post_id' => $posts[$slug],
                'visitor_hash' => self::visitorHash(null, $ip, $agent, $day),
                'viewed_on' => $day->toDateString(),
                'referrer_host' => self::referrerHost($referrer === '-' ? null : $referrer),
                'source' => 'access_log',
                'created_at' => $at->copy()->timezone(config('app.timezone')),
            ]);
        }

        return ['lines' => $read, 'counted' => $counted];
    }
}
