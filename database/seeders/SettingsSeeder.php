<?php

namespace Database\Seeders;



use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $settings = [
            'maintenance_mode' => false,
            'allow_registrations' => true,
            'auto_approve_companies' => false,
            'email_notifications_new_user' => true,
            'email_notifications_new_offer' => true,
            'email_notifications_new_application' => true,
            'max_file_size' => 5, // MB
            'max_offers_per_company' => 20,
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}