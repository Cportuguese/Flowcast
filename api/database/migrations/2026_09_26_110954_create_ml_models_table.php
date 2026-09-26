<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every training run stores a new immutable version rather than mutating
     * the previous one, so a regression can be rolled back and the accuracy
     * history stays auditable.
     */
    public function up(): void
    {
        Schema::create('ml_models', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->unsignedInteger('version');
            $table->string('algorithm', 40)->default('ridge_regression');
            $table->json('feature_names');
            $table->json('coefficients');
            $table->double('intercept')->default(0);
            $table->json('feature_means');
            $table->json('feature_stds');
            $table->double('lambda')->default(0);
            $table->unsignedInteger('training_rows')->default(0);
            $table->unsignedInteger('holdout_rows')->default(0);
            $table->json('metrics')->nullable();
            $table->double('residual_std')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('trained_at');
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_models');
    }
};
