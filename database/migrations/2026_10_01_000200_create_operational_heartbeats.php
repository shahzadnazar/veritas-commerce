<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for background machinery to say it is still alive.
 *
 * The M9 failure drills asked what happens if the scheduler stops, and
 * the honest answer was: earnings stay in `clearing`, expired holds keep
 * their stock, and nobody finds out until a seller asks why their money
 * has not moved. Degrading safely is not the same as degrading visibly,
 * and only the first of those was true.
 *
 * A table rather than the cache, deliberately. A cache-backed heartbeat
 * is read through Redis, and the outage most likely to stop the
 * scheduler is Redis being away — so the signal would disappear at
 * exactly the moment it was needed, and its absence would be
 * indistinguishable from the thing it was meant to report.
 *
 * Deliberately not an events table. It holds one row per named piece of
 * machinery, overwritten in place, because the question is only ever
 * "when did this last run" and a log of every tick would be a second
 * table to prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_heartbeats', function (Blueprint $table): void {
            // The name is the key: one row per thing that beats.
            $table->string('name')->primary();
            $table->timestamp('ran_at');
            // What ran, for an operator reading the row cold — the last
            // scheduled task's name says more than a timestamp alone.
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_heartbeats');
    }
};
