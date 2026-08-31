<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // Set at the start of enrolment and only trusted once
            // two_factor_confirmed_at is set — an unconfirmed secret can
            // never satisfy a login.
            $table->timestamp('two_factor_enrolled_at')->nullable()->after('two_factor_secret');
            $table->timestamp('last_login_at')->nullable()->after('last_active_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        /*
         * Recovery codes are hashed, exactly like passwords: a leaked table
         * must not yield a working second factor. They are shown once, at
         * generation, and never again.
         */
        Schema::create('admin_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['admin_user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_recovery_codes');

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_enrolled_at', 'last_login_at', 'last_login_ip']);
        });
    }
};
