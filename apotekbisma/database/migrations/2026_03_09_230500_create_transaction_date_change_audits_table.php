<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionDateChangeAuditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_date_change_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('transaction_type', 20);
            $table->unsignedInteger('transaction_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name_snapshot')->nullable();
            $table->dateTime('old_waktu')->nullable();
            $table->dateTime('new_waktu');
            $table->string('reference_label')->nullable();
            $table->text('affected_product_ids');
            $table->unsignedInteger('affected_product_count')->default(0);
            $table->string('reflow_strategy', 50)->default('baseline_rebuild');
            $table->string('reflow_status', 20)->default('applied');
            $table->unsignedInteger('negative_event_products')->default(0);
            $table->unsignedInteger('negative_event_count')->default(0);
            $table->longText('metadata')->nullable();
            $table->timestamps();

            $table->index(['transaction_type', 'transaction_id'], 'transaction_date_change_audits_type_id_idx');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transaction_date_change_audits');
    }
}