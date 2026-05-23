<?php

namespace Database\Seeders;

use App\Models\AttendanceStatus;
use Illuminate\Database\Seeder;

class AttendanceLookupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['registered', 'present', 'absent', 'late'] as $status) {
            AttendanceStatus::firstOrCreate(['status_name' => $status]);
        }
    }
}
