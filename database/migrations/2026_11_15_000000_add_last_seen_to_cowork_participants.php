<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live presence: when each participant was last seen (a heartbeat or any request
 * of theirs), in integer milliseconds like the other *_ms columns. Nullable and
 * additive: a row without it counts from joined_at, so existing sessions keep
 * working and expire on the same rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->table('cowork_participants', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_seen_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->table('cowork_participants', function (Blueprint $table): void {
            $table->dropColumn('last_seen_ms');
        });
    }
};
