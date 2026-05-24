<?php

use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Event::whereNull('event_code')->each(function (Event $event) {
            do {
                $code = strtoupper(Str::random(6));
            } while (Event::where('event_code', $code)->exists());

            $event->update(['event_code' => $code]);
        });
    }

    public function down(): void
    {
        //
    }
};
