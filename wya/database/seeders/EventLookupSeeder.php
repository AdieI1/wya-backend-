<?php

namespace Database\Seeders;

use App\Models\EventStatus;
use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventLookupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Whole day', 'Half day', 'Morning', 'Afternoon', 'Evening'] as $type) {
            EventType::firstOrCreate(['type_name' => $type]);
        }

        foreach (['upcoming', 'cancelled', 'completed'] as $status) {
            EventStatus::firstOrCreate(['status_name' => $status]);
        }
    }
}
