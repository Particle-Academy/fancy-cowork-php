<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Why a session ended: 'ended' (an explicit end), 'starter-left' or 'empty'. The
 * re-sync returns it as session.endReason. Nullable and additive: a session that
 * ended before this column existed reads as 'ended'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->table('cowork_sessions', function (Blueprint $table): void {
            $table->string('end_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('fancy-cowork.connection'))->table('cowork_sessions', function (Blueprint $table): void {
            $table->dropColumn('end_reason');
        });
    }
};
