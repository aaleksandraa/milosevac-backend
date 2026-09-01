<?php

namespace App\Support\Atlas\Poi;

final class OverpassQueryBuilder
{
    public function bboxQuery(float $minLat, float $minLng, float $maxLat, float $maxLng, int $timeoutSeconds): string
    {
        $bbox = $this->format($minLat).','.$this->format($minLng).','.$this->format($maxLat).','.$this->format($maxLng);

        return <<<QL
[out:json][timeout:{$timeoutSeconds}];
(
  nwr["amenity"~"^(kindergarten|school|university|college|pharmacy|clinic|doctors|dentist|hospital|veterinary|place_of_worship|mosque|monastery|library|theatre|theater|museum|arts_centre|cultural_centre|grave_yard|townhall|post_office|police|fire_station|community_centre|bus_station|fuel|parking|swimming_pool)$"]({$bbox});
  nwr["leisure"~"^(pitch|stadium|sports_hall|sports_centre|swimming_pool|fitness_centre|fitness_station|playground|park|nature_reserve)$"]({$bbox});
  nwr["historic"]({$bbox});
  nwr["tourism"~"^(hotel|guest_house|motel|attraction|viewpoint|information|picnic_site|camp_site|museum)$"]({$bbox});
  nwr["highway"="bus_stop"]({$bbox});
  nwr["railway"="station"]({$bbox});
  nwr["building"~"^(church|mosque|chapel|monastery)$"]({$bbox});
  nwr["landuse"="cemetery"]({$bbox});
  nwr["natural"~"^(spring|peak)$"]({$bbox});
  nwr["healthcare"~"^(hospital|clinic|doctor|doctors)$"]({$bbox});
);
out geom;
QL;
    }

    private function format(float $value): string
    {
        return number_format($value, 7, '.', '');
    }
}
