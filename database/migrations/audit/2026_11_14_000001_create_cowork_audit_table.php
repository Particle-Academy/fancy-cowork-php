<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OPTIONAL. Loaded only when `fancy-cowork.audit.table` is true. Audit is
 * events first; this table is one place a host may choose to keep them. It
 * never holds an intent's value or an app payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->create('cowork_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 26);
            $table->string('type', 32);
            $table->string('ref', 256);
            $table->string('acting_for', 256)->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('at');
            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->dropIfExists('cowork_audit');
    }
};
