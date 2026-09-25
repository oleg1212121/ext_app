<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Stable dotted identifier, e.g. simulator.question.format');
            $table->text('text')->comment('Template body; :base/:learning are substituted with the current column language names');
            $table->timestamps();
            $table->comment('Admin-editable AI prompt templates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
