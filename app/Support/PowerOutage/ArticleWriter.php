<?php

namespace App\Support\PowerOutage;

class ArticleWriter
{
    public function __construct(private PlaceMatcher $matcher = new PlaceMatcher()) {}

    /** @param list<MatchedPlace> $places */
    public function title(PowerOutageNotice $notice, array $places): string
    {
        $dateLabel = $this->dateLabel($notice);

        $primaryPlace = $this->matcher->primaryForTitle($notice, $places);
        if ($primaryPlace?->locative) {
            return sprintf(
                'Planirani prekid struje u %s %s',
                $primaryPlace->locative,
                $dateLabel,
            );
        }

        return sprintf('Planirani prekid električne energije %s', $dateLabel);
    }

    /** @param list<MatchedPlace> $places */
    public function excerpt(PowerOutageNotice $notice, array $places): string
    {
        return sprintf(
            'Planirani prekid struje %s od %s do %s časova na lokaciji: %s.',
            $this->dateLabel($notice),
            $notice->startsAt,
            $notice->endsAt,
            $notice->locationsRaw,
        );
    }

    /** @param list<MatchedPlace> $places */
    public function contentHtml(PowerOutageNotice $notice, array $places): string
    {
        $weekday = $this->weekdayLabel($notice);
        $dateLabel = $this->fullDateLabel($notice);
        $fieldUnit = e($notice->fieldUnit);
        $locations = e($notice->locationsRaw);
        $trafo = e($notice->transformerArea);
        $starts = e($notice->startsAt);
        $ends = e($notice->endsAt);

        $paragraphs = [
            "<p>Zbog planiranih radova na elektroenergetskim objektima Terenske jedinice {$fieldUnit}, u {$weekday}, {$dateLabel}, doći će do privremenog prekida u isporuci električne energije.</p>",
            "<p>Bez električne energije će u periodu od {$starts} do {$ends} časova biti {$locations}, koji se napaja sa trafo područja {$trafo}.</p>",
            '<p>Iz Elektro Doboja navode da prekid može trajati kraće ukoliko radovi budu završeni prije planiranog roka.</p>',
        ];

        if ($notice->sourceUrl) {
            $url = e($notice->sourceUrl);
            $paragraphs[] = "<p>Više informacija na <a href=\"{$url}\" rel=\"noopener noreferrer\" target=\"_blank\">stranici Elektro Doboja</a>.</p>";
        }

        $paragraphs[] = '<p><strong>Izvor:</strong> ODS „Elektro Doboj“ a.d. Doboj</p>';

        return implode("\n", $paragraphs);
    }

    private function dateLabel(PowerOutageNotice $notice): string
    {
        $months = [
            1 => 'januara', 2 => 'februara', 3 => 'marta', 4 => 'aprila',
            5 => 'maja', 6 => 'juna', 7 => 'jula', 8 => 'avgusta',
            9 => 'septembra', 10 => 'oktobra', 11 => 'novembra', 12 => 'decembra',
        ];

        $day = (int) $notice->date->format('j');
        $month = (int) $notice->date->format('n');

        return $day.'. '.$months[$month];
    }

    private function fullDateLabel(PowerOutageNotice $notice): string
    {
        return $this->dateLabel($notice).' '.$notice->date->format('Y').'. godine';
    }

    private function weekdayLabel(PowerOutageNotice $notice): string
    {
        $weekdays = [
            1 => 'ponedjeljak', 2 => 'utorak', 3 => 'srijedu', 4 => 'četvrtak',
            5 => 'petak', 6 => 'subotu', 7 => 'nedjelju',
        ];

        return $weekdays[(int) $notice->date->dayOfWeekIso] ?? 'dan';
    }
}
