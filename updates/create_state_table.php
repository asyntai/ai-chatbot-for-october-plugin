<?php namespace Asyntai\Chatbot\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Private plugin state: the feed signing token and the connect handshake.
 * Kept out of the settings model so it is never rendered into a form.
 * October runs up() on install or update and down() on uninstall.
 */
class CreateStateTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('asyntai_chatbot_state')) {
            return;
        }

        Schema::create('asyntai_chatbot_state', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->text('value');
        });
    }

    public function down()
    {
        Schema::dropIfExists('asyntai_chatbot_state');
    }
}
