<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('channel', 10);
            $table->string('priority', 10);
            $table->string('recipient');
            $table->text('content');
            $table->string('status', 20);
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('correlation_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('scheduled_at', 3)->nullable();
            $table->timestamp('dispatched_at', 3)->nullable()->index();
            $table->timestamp('sent_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['status', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
