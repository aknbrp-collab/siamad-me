<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1: in-app notifications (Laravel's database channel) and a record of the
 * scheduled reminders already sent, so each one goes out once per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell counts unread notifications of one user.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // e.g. "assignment-due:12", "bill-due:7:7" (bill 7, seven days before).
            $table->string('key', 100);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
        Schema::dropIfExists('notifications');
    }
};
