<?php

namespace App\Support\PowerOutage;

use Carbon\Carbon;

final readonly class PowerOutageNotice
{
    public function __construct(
        public string $externalId,
        public ?string $sourceUrl,
        public Carbon $date,
        public string $startsAt,
        public string $endsAt,
        public string $fieldUnit,
        public string $locationsRaw,
        public string $transformerArea,
    ) {}

    public function noticeStartsAt(): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->date->format('Y-m-d').' '.$this->startsAt,
            'Europe/Sarajevo'
        );
    }

    public function noticeEndsAt(): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->date->format('Y-m-d').' '.$this->endsAt,
            'Europe/Sarajevo'
        );
    }

    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'sourceUrl' => $this->sourceUrl,
            'date' => $this->date->format('Y-m-d'),
            'startsAt' => $this->startsAt,
            'endsAt' => $this->endsAt,
            'fieldUnit' => $this->fieldUnit,
            'locationsRaw' => $this->locationsRaw,
            'transformerArea' => $this->transformerArea,
        ];
    }
}
