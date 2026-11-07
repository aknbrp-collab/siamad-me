<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kritik & saran (v6 D34): a review or suggestion from any user, read and
 * answered by Admin and Superadmin. The sender is always recorded; an
 * anonymous message hides the name from the Admin (Superadmin can see it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // The role the sender was acting in (mahasantri, wali, muhadhir, staff).
            $table->string('role', 30);
            $table->string('category', 20);
            $table->string('subject', 150);
            $table->text('message');
            $table->boolean('anonymous')->default(false);
            // new | in_progress | done
            $table->string('status', 12)->default('new');
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('replied_by');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
