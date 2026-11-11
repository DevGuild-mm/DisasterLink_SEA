<?php

namespace App\Mail;

use App\Models\Country;
use App\Models\DisasterEvent;
use App\Models\User;
use Illuminate\Mail\Mailables\Headers;

/**
 * The message `php artisan mail:test` sends. It is the real country-level alert email (same subject, template and
 * layout as DisasterAlertMail) filled with sample data, so a mail setup can be judged by how a genuine alert looks.
 *
 * The event, country and user exist only in memory: nothing is saved, no alert log is written and no alert job is
 * queued. A plain header (`X-DisasterLink-Sample`) marks the message for mail filters; the body is unchanged.
 */
class TestMail extends DisasterAlertMail
{
    public function __construct()
    {
        $country = new Country([
            'iso2' => 'TH',
            'iso3' => 'THA',
            'name' => 'Thailand',
            'timezone' => 'Asia/Bangkok',
        ]);

        $event = new DisasterEvent([
            'source' => 'gdacs',
            'external_id' => 'FL:0',
            'event_type' => 'flood',
            'title' => 'Flooding along the Chao Phraya river affecting central Thailand',
            'location_text' => 'Chao Phraya river basin, Central Thailand',
            'alert_level' => 'Orange',
            'latitude' => 14.35,
            'longitude' => 100.57,
            'source_url' => 'https://www.gdacs.org/',
        ]);
        $event->occurred_at = now()->subHours(2);
        $event->setRelation('country', $country);

        parent::__construct($event, new User(['name' => 'DisasterLink SEA subscriber', 'email' => 'subscriber@example.com']));
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-DisasterLink-Sample' => 'alert']);
    }
}
