<?php

namespace App\Services\Disaster\Sync;

/**
 * The outcome of resolving an event's country (HLD §12).
 */
final readonly class CountryResolution
{
    public const RESOLVED = 'resolved';

    public const UNRESOLVED = 'unresolved';

    public const OUTSIDE_REGION = 'outside_region';

    public const SOURCE_CODE = 'source_code';

    public const ADM0_LOOKUP = 'adm0_lookup';

    public const LINKED_SOURCE = 'linked_source';

    public function __construct(
        public string $status,
        public ?int $countryId = null,
        public ?string $method = null,
        public ?string $note = null,
    ) {}

    public static function resolved(int $countryId, string $method): self
    {
        return new self(self::RESOLVED, $countryId, $method);
    }

    public static function unresolved(string $note): self
    {
        return new self(self::UNRESOLVED, null, null, $note);
    }

    public static function outsideRegion(string $note): self
    {
        return new self(self::OUTSIDE_REGION, null, null, $note);
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }

    /**
     * @return array{country_id: int|null, country_status: string, country_method: string|null, country_note: string|null}
     */
    public function toColumns(): array
    {
        return [
            'country_id' => $this->countryId,
            'country_status' => $this->status,
            'country_method' => $this->method,
            'country_note' => $this->note,
        ];
    }
}
