<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog analytics (D51). One row per visitor, per post, per day. Anonymous visitors are a keyed hash
 * that changes every day, so no address is stored and a visitor cannot be followed across days.
 * Signed-in clients are recorded by account, so the firm can see which clients read a post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->string('referrer_host', 190)->nullable();
            $table->string('source', 20)->default('beacon');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'visitor_hash', 'viewed_on']);
            $table->index(['viewed_on', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_views');
    }
};
