<?php

namespace Database\Seeders;

use App\Models\ConsultationType;
use Illuminate\Database\Seeder;

/**
 * The starting consultation type. The 30-minute length is a placeholder the owner can change in
 * Admin -> Consultation types (DECISIONS D27). Existing rows are never overwritten.
 */
class ConsultationTypeSeeder extends Seeder
{
    public function run(): void
    {
        ConsultationType::firstOrCreate(['name' => 'Initial consultation'], [
            'description' => 'A short first conversation so the firm can understand your matter and explain the next steps.',
            'duration_minutes' => 30,
            'is_free' => true,
            'fee_minor' => null,
            'currency' => null,
            'is_public' => true,
            'is_active' => true,
            'sort' => 1,
        ]);
    }
}
