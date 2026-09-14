<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csp_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('effective_directive');
            $table->string('blocked_uri');
            $table->string('source_file')->default('');
            $table->unsignedInteger('line_number')->default(0);
            $table->unsignedInteger('column_number')->default(0);
            $table->string('disposition');
            $table->string('document_uri');
            $table->string('referrer')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('original_policy')->nullable();
            $table->text('script_sample')->nullable();
            $table->json('raw_sample');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(
                ['site_id', 'effective_directive', 'blocked_uri', 'source_file'],
                'csp_violations_dedup_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_violations');
    }
};
