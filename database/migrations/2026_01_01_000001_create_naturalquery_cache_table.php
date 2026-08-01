<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('naturalquery.cache.table_name', 'naturalquery_cache');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('query_hash', 64)->unique()->index();
            $table->text('original_query');
            $table->text('normalized_query')->index();
            $table->string('scheme', 100)->nullable()->index();
            $table->string('metric', 100)->nullable();
            $table->string('district', 255)->nullable();
            $table->json('intent')->nullable();
            $table->integer('limit_value')->nullable();
            $table->string('order_direction', 10)->nullable();
            $table->string('query_type', 50)->nullable();
            $table->unsignedBigInteger('hit_count')->default(1);
            $table->timestamp('last_hit_at')->nullable()->index();
            $table->timestamps();

            $table->index(['scheme', 'metric']);
        });
    }

    public function down(): void
    {
        $tableName = config('naturalquery.cache.table_name', 'naturalquery_cache');
        Schema::dropIfExists($tableName);
    }
};
