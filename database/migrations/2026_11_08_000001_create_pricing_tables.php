<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 64)->unique();
            $t->string('name');
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('cost_cents')->nullable();
            $t->string('category', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('rules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 32);
            $t->json('params');
            $t->integer('priority')->default(0);
            $t->boolean('exclusive')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->json('customer_groups')->nullable();
            $t->string('coupon_code', 40)->nullable()->unique();
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('times_used')->default(0);
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('customer_group', 64)->nullable();
            $t->json('coupon_codes');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents');
            $t->unsignedBigInteger('total_cents');
            $t->json('quote');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('products');
    }
};
