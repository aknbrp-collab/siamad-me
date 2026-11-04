<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discussion rooms (v5 D26): one engine for the bimbingan penelitian dan
 * penulisan groups and the LPPPK channels. A room has a chat (messages with
 * replies and files), pinned notes and, per member, a read marker and a mute.
 * Nothing is deleted: a moderator hides a message and the text stays for the
 * audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index(); // bimbingan | lpppk
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('lead_teacher_id')->nullable()->constrained('teachers')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('lead_teacher_id');
            $table->index('created_by');
        });

        Schema::create('discussion_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 10)->default('member'); // lead | member
            $table->timestamp('muted_at')->nullable();
            // Null while muted means "until lifted".
            $table->timestamp('muted_until')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['discussion_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('discussion_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('discussion_messages')->nullOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['discussion_id', 'id']);
            $table->index('user_id');
            $table->index('reply_to_id');
            $table->index('hidden_by');
        });

        Schema::create('discussion_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discussion_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index('discussion_id');
            $table->index('discussion_message_id');
            $table->index('user_id');
        });

        Schema::create('discussion_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->index('discussion_id');
            $table->index('user_id');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_notes');
        Schema::dropIfExists('discussion_files');
        Schema::dropIfExists('discussion_messages');
        Schema::dropIfExists('discussion_members');
        Schema::dropIfExists('discussions');
    }
};
