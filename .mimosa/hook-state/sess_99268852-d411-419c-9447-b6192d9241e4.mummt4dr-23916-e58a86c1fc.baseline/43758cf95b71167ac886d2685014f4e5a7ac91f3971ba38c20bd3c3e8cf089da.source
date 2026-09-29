<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $metadataTable = config('redis_sharding.metadata_table', 'shard_metadata');

        Schema::create($metadataTable, function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('connection');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('record_count')->default(0);
            $table->timestamp('last_rebalanced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $metadataTable = config('redis_sharding.metadata_table', 'shard_metadata');

        Schema::dropIfExists($metadataTable);
    }
};
