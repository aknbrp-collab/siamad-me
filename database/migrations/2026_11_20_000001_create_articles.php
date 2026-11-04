<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles (v5 D29): simple articles for the public website, written by
 * mahasantri and muhadhir. A mahasantri's article is published after two
 * muhadhir approve it in one review round; a muhadhir's is published
 * directly. Every publication is kept as a version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            // Stable id sent to the CMS, e.g. ART-2026-0042; given on the first publication.
            $table->string('code', 30)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->longText('body_md');
            $table->json('keywords')->nullable();
            // draft | in_review | changes_requested | published | withdrawn (of the working copy)
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('review_round')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('article_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 10); // approved | changes
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'round', 'reviewer_id']);
            $table->index('reviewer_id');
        });

        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->longText('body_md');
            $table->json('keywords')->nullable();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['article_id', 'version']);
            $table->index('published_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('article_reviews');
        Schema::dropIfExists('articles');
    }
};
