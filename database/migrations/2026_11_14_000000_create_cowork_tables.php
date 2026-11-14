<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sessions and participants. No foreign key points at any host table: a
 * participant is an opaque ref string, never a row the host owns. Times the
 * engine reasons about are integer milliseconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('fancy-cowork.connection'));

        $schema->create('cowork_sessions', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('state', 16);
            $table->string('starter', 256);
            $table->boolean('consented')->default(false);
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('config')->nullable();
            $table->json('control');
            $table->json('last');
            $table->json('rate');
            $table->unsignedBigInteger('created_ms');
            $table->unsignedBigInteger('ended_ms')->nullable();
            $table->timestamps();
            $table->index(['starter', 'created_ms']);
        });

        $schema->create('cowork_participants', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 26);
            $table->string('ref', 256);
            $table->string('acting_for', 256)->nullable();
            $table->string('label', 256)->nullable();
            $table->json('attributes')->nullable();
            $table->string('view_route', 2048)->default('/');
            $table->unsignedBigInteger('view_revision')->default(0);
            $table->string('follows', 256)->nullable();
            $table->unsignedBigInteger('joined_at');
            $table->unsignedBigInteger('left_at')->nullable();
            $table->index(['session_id', 'left_at']);
            $table->index('ref');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('fancy-cowork.connection'));
        $schema->dropIfExists('cowork_participants');
        $schema->dropIfExists('cowork_sessions');
    }
};
