<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('tags')->nullable();
            $table->string('vendor')->nullable();
            $table->string('type')->nullable();
            $table->integer('inventory_quantity')->nullable();
            $table->integer('days_since_last_sale')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products');
    }
}
