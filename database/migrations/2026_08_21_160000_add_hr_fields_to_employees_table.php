<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fills out the employee record to match the HR screen. Everything is
     * nullable: HR registers a worker from a fingerprint enrolment long before
     * the paperwork (contract, CV, ID card) catches up, and a half-filled
     * record is more useful than no record.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('position')->nullable()->after('department');
            $table->string('shift')->nullable()->after('position');
            $table->decimal('vacation_balance', 5, 1)->default(0)->after('shift');
            $table->string('address')->nullable()->after('vacation_balance');
            $table->date('start_date')->nullable()->after('address');
            $table->string('id_card_image')->nullable()->after('documents');
            $table->string('cv_file')->nullable()->after('id_card_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn([
                'email', 'phone', 'position', 'shift', 'vacation_balance',
                'address', 'start_date', 'id_card_image', 'cv_file',
            ]);
        });
    }
};
