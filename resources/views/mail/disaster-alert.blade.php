<x-mail::message>
# {{ ucfirst($event->event_type) }} reported in {{ $country?->name ?? 'your country' }}

@if ($delayed)
**Delayed delivery:** this alert is being sent later than usual because our daily email limit was reached.

@endif
**{{ $event->title }}**

- **Event type:** {{ ucfirst($event->event_type) }}
@if ($event->location_text)
- **Location:** {{ $event->location_text }}
@endif
- **Country:** {{ $country?->name ?? 'Unknown' }}
@if ($coordinates)
- **Coordinates:** {{ $coordinates }}
@endif
@if ($event->source === 'usgs' && $event->magnitude !== null)
- **Magnitude:** M {{ number_format((float) $event->magnitude, 1) }}
@elseif ($event->alert_level)
- **Alert level:** {{ $event->alert_level }}
@endif
- **Occurred:** {{ $localTime }} / {{ $utcTime }}
- **Source:** {{ $sourceName }}
@if ($event->source_url)
- **Original report:** {{ $event->source_url }}
@endif

This alert is sent because the event was reported in {{ $country?->name ?? 'your country' }}, your registered country. It does not mean your city or area is directly affected. Please check the event location and official sources, and follow local authorities.

<x-mail::button :url="$eventsUrl">
View on DisasterLink SEA
</x-mail::button>

- [Emergency contacts]({{ $contactsUrl }})
- [Safety guides]({{ $guidesUrl }})

You receive this because alert emails are on for your account. [Manage alert emails in your account settings]({{ $settingsUrl }}).
</x-mail::message>
