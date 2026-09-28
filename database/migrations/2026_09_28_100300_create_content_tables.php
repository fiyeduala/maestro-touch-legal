<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public media only (blog/brand). Confidential files never go here.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 20);
            $table->string('path')->unique(); // relative to the disk root, also the public URL path
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->text('caption')->nullable();
            $table->char('sha256', 64)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('path')->unique(); // public URL path, e.g. "/" or "/about/"
            $table->string('template', 40);
            $table->string('title');
            $table->boolean('is_system')->default(false); // mirrored/legally required pages cannot be deleted
            $table->unsignedBigInteger('draft_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->timestamps();
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->json('content');
            $table->string('status', 20); // draft | published | superseded
            $table->string('note', 500)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['page_id', 'number']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('author_name')->nullable(); // public byline
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index(); // draft | review | scheduled | published | private | archived
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('content_modified_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->boolean('comments_visible')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('posts', fn (Blueprint $table) => $table->fullText(['title', 'excerpt', 'body']));
        }

        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 60);
            $table->timestamp('created_at');
        });

        Schema::create('category_post', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'post_id']);
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->text('body'); // sanitised HTML
            $table->string('status', 20)->index(); // approved | pending | hidden | spam
            $table->timestamp('posted_at');
            $table->timestamps();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to_path')->nullable(); // null with status 410
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->string('source', 20)->default('manual'); // manual | wordpress | system
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40); // wordpress-rest | wordpress-wxr
            $table->boolean('dry_run');
            $table->string('status', 20); // running | completed | failed
            $table->json('stats')->nullable();
            $table->json('issues')->nullable();
            $table->string('report_path')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        // Source-ID → local-record map: makes imports idempotent and resumable.
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->string('source_type', 30); // post | page | media | category | tag | comment | author
            $table->string('source_id', 100);
            $table->string('source_url')->nullable();
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->foreignId('import_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['import_mappings', 'import_runs', 'redirects', 'comments', 'post_tag', 'category_post', 'post_revisions', 'posts', 'tags', 'categories', 'page_revisions', 'pages', 'media'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
