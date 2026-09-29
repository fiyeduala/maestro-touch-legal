<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The old site's images moved from public/wp-content/uploads to public/images (DECISIONS D41).
 * Points stored paths at the new folder. Old addresses still work: they are redirected (routes/web.php).
 */
return new class extends Migration
{
    private const PAIRS = [
        ['wp-content/uploads/', 'images/'],
        ['wp-content\\/uploads\\/', 'images\\/'], // JSON-escaped form inside page content
    ];

    public function up(): void
    {
        $this->swap(0, 1);
    }

    public function down(): void
    {
        $this->swap(1, 0);
    }

    private function swap(int $from, int $to): void
    {
        DB::table('media')->where('disk', 'legacy')->where('path', 'like', $from ? 'images/%' : 'wp-content/uploads/%')
            ->orderBy('id')->each(function ($row) use ($from, $to) {
                DB::table('media')->where('id', $row->id)
                    ->update(['path' => self::PAIRS[0][$to].substr($row->path, strlen(self::PAIRS[0][$from]))]);
            });

        foreach (['page_revisions' => 'content', 'posts' => 'body'] as $table => $column) {
            DB::table($table)->where($column, 'like', '%'.($from ? 'images' : 'wp-content').'%')->orderBy('id')
                ->each(function ($row) use ($table, $column, $from, $to) {
                    $value = $row->{$column};
                    foreach (self::PAIRS as $pair) {
                        $value = str_replace('/'.$pair[$from], '/'.$pair[$to], $value);
                    }
                    if ($value !== $row->{$column}) {
                        DB::table($table)->where('id', $row->id)->update([$column => $value]);
                    }
                });
        }
    }
};
