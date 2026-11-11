<?php

namespace App\Mail;

use App\Models\DisasterEvent;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Country-level alert (HLD §13.8). It says the event was reported in the user's country and never that the
 * user's own city or area is affected.
 */
class DisasterAlertMail extends Mailable
{
    public function __construct(
        public DisasterEvent $event,
        public User $user,
        public bool $delayed = false,
    ) {}

    public function envelope(): Envelope
    {
        $country = $this->event->country?->name ?? 'your country';
        $severity = $this->event->magnitude !== null && $this->event->source === 'usgs'
            ? 'M '.number_format((float) $this->event->magnitude, 1)
            : (string) $this->event->alert_level;

        return new Envelope(
            subject: '[DisasterLink SEA] '.ucfirst($this->event->event_type)." reported in {$country}".($severity !== '' ? ": {$severity}" : ''),
        );
    }

    public function content(): Content
    {
        $country = $this->event->country;
        $timezone = $country?->timezone ?? 'UTC';
        $occurred = $this->event->occurred_at;

        return new Content(
            markdown: 'mail.disaster-alert',
            with: [
                'event' => $this->event,
                'country' => $country,
                'delayed' => $this->delayed,
                'sourceName' => strtoupper($this->event->source),
                'localTime' => $occurred->copy()->timezone($timezone)->format('Y-m-d H:i').' ('.$timezone.')',
                'utcTime' => $occurred->copy()->utc()->format('Y-m-d H:i').' UTC',
                'coordinates' => $this->event->latitude !== null && $this->event->longitude !== null
                    ? number_format((float) $this->event->latitude, 3).', '.number_format((float) $this->event->longitude, 3)
                    : null,
                'eventsUrl' => route('disaster.events'),
                'contactsUrl' => route('preparedness.index', $country ? ['country' => $country->iso2] : []).'#contacts',
                'guidesUrl' => route('preparedness.index').'#guides',
                'settingsUrl' => route('profile.edit'),
            ],
        );
    }
}
