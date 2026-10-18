<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name', 80);
            $table->text('url');
            $table->text('signing_secret')->nullable();
            $table->string('min_level', 16)->default('error');
            $table->json('environments')->default('["production"]');
            $table->unsignedInteger('cooldown_seconds')->default(300);
            $table->boolean('enabled')->default(true);
            $table->string('last_status', 16)->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_delivered_at', 3)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'enabled']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            // No foreign keys: events are pruned on their own schedule, and a key on issue_id would take a lock on
            // the busiest row in the database for every delivery.
            $table->string('event_id', 26)->nullable();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('dispatch_delay_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at', 3);

            $table->index(['endpoint_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
