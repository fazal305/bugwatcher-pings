<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('title');
            $table->string('culprit')->nullable();
            $table->string('level', 16);
            $table->string('environment', 16);
            $table->string('status', 16)->default('open');
            $table->timestamp('first_seen_at', 3);
            $table->timestamp('last_seen_at', 3);
            $table->unsignedBigInteger('event_count')->default(1);
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'last_seen_at']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16);
            $table->string('environment', 16);
            $table->text('message');
            $table->string('exception_type')->nullable();
            $table->string('file', 1024)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->jsonb('stacktrace')->nullable();
            $table->jsonb('request')->nullable();
            $table->jsonb('user_context')->nullable();
            $table->string('release', 200)->nullable();
            $table->string('sdk', 100)->nullable();
            $table->boolean('is_regression')->default(false);
            $table->timestamp('occurred_at', 3);
            $table->timestamp('received_at', 3);
            $table->timestamp('processed_at', 3);

            $table->rawIndex('project_id, received_at desc', 'events_project_id_received_at_index');
            $table->index(['issue_id', 'received_at']);
            // Retention pruning deletes by age across all projects.
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('issues');
    }
};
