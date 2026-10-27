<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3: maintenance mode for entering previous-year data. Each time Superadmin
 * turns it on is one session (who, when, why, until when). Every audited
 * change made while a session is open is tagged with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('started_by')->index()->constrained('users');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->foreignId('ended_by')->nullable()->index()->constrained('users');
            $table->timestamp('ended_at')->nullable()->index();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('maintenance_session_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_session_id');
        });

        Schema::dropIfExists('maintenance_sessions');
    }
};
