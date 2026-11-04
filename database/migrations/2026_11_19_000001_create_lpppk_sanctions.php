<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LPPPK moderation (v5 D28): a person muted in all LPPPK channels (they can
 * read but not post), or restricted (no access to LPPPK at all). One row per
 * person; lifting clears it, and the audit log keeps the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lpppk_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('muted_at')->nullable();
            // Null while muted means "until lifted".
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lpppk_sanctions');
    }
};
