<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS integration (v5 D31): what the CMS last said about each article, and a
 * log of every call between SIAKAD and the CMS in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('cms_id', 100)->nullable()->after('withdrawn_at');
            // draft | under_review | approved | rejected | withdrawn, as the CMS reports it
            $table->string('cms_status', 20)->nullable()->after('cms_id');
            $table->text('cms_review_note')->nullable()->after('cms_status');
            $table->string('cms_public_url', 2048)->nullable()->after('cms_review_note');
            $table->boolean('cms_changes_pending')->default(false)->after('cms_public_url');
            $table->unsignedInteger('cms_sent_version')->nullable()->after('cms_changes_pending');
            $table->timestamp('cms_sent_at')->nullable()->after('cms_sent_version');
            $table->timestamp('cms_checked_at')->nullable()->after('cms_sent_at');
            // The last refusal (4xx) or the error after the last retry; cleared by a success.
            $table->text('cms_error')->nullable()->after('cms_checked_at');
        });

        Schema::create('cms_requests', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3); // out | in
            $table->string('method', 10);
            $table->string('endpoint', 255);
            // The article code or question id the call was about.
            $table->string('subject', 100)->nullable()->index();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->text('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_requests');
        Schema::table('articles', fn (Blueprint $table) => $table->dropColumn([
            'cms_id', 'cms_status', 'cms_review_note', 'cms_public_url', 'cms_changes_pending', 'cms_sent_version', 'cms_sent_at', 'cms_checked_at', 'cms_error',
        ]));
    }
};
