<?php

namespace App\Support\PowerOutage;

use Carbon\Carbon;
use InvalidArgumentException;

class NoticeParser
{
    public function __construct(
        private CyrillicLatin $transliterator = new CyrillicLatin(),
        private SourceUrlValidator $sourceUrlValidator = new SourceUrlValidator(),
    ) {}

    public function parse(string $body): PowerOutageNotice
    {
        $latin = $this->transliterator->transliterate($body);
        $latin = preg_replace('/\s+/u', ' ', $latin) ?? $latin;

        if (! preg_match('/dana\s+(\d{2}\.\d{2}\.\d{4})/iu', $latin, $dateMatch)) {
            throw new InvalidArgumentException('Datum prekida nije prepoznat.');
        }

        if (! preg_match('/u\s+vremenu\s+od\s+(\d{2}:\d{2})\s+do\s+(\d{2}:\d{2})/iu', $latin, $timeMatch)) {
            throw new InvalidArgumentException('Vrijeme prekida nije prepoznato.');
        }

        if (! preg_match('/terenske\s+jedinice\s+([^,\.]+?)(?:\s+tokom|\s+tijekom)/iu', $latin, $unitMatch)) {
            throw new InvalidArgumentException('Terenska jedinica nije prepoznata.');
        }

        if (! preg_match('/na\s+slede.im\s+lokacijama\s+(.+?)\s*,\s*koje\s+se\s+napajaju\s+sa\s+trafo\s+podru.ja\s*:\s*(.+?)\./iu', $latin, $locationMatch)) {
            throw new InvalidArgumentException('Lokacije ili trafo područje nisu prepoznati.');
        }

        if (! preg_match('/PrekidNapajanja\?id=(\d+)/i', $latin, $idMatch)) {
            throw new InvalidArgumentException('Elektro Doboj ID nije prepoznat.');
        }

        $sourceUrl = null;
        if (preg_match('#https?://[^\s<>"]+PrekidNapajanja\?id=\d+#i', $latin, $urlMatch)) {
            $sourceUrl = $this->sourceUrlValidator->validate(rtrim($urlMatch[0], '.,);'));
        }

        $date = Carbon::createFromFormat('d.m.Y', $dateMatch[1], 'Europe/Sarajevo')
            ->startOfDay();

        return new PowerOutageNotice(
            externalId: $idMatch[1],
            sourceUrl: $sourceUrl,
            date: $date,
            startsAt: $timeMatch[1],
            endsAt: $timeMatch[2],
            fieldUnit: trim($unitMatch[1]),
            locationsRaw: trim($locationMatch[1]),
            transformerArea: trim($locationMatch[2]),
        );
    }
}
