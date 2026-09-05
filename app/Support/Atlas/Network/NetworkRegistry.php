<?php

namespace App\Support\Atlas\Network;

final class NetworkRegistry
{
    public const PROXIMITIES = [
        'municipality_centre' => 'Opštinski centar', 'nearest_pharmacy' => 'Najbliža apoteka',
        'nearest_hospital' => 'Najbliža bolnica', 'nearest_health' => 'Najbliži zdravstveni objekat',
        'nearest_school' => 'Najbliža škola', 'nearest_fuel_station' => 'Najbliža benzinska stanica',
        'nearest_bus_station' => 'Najbliža autobuska stanica', 'nearest_railway_station' => 'Najbliža željeznička stanica',
        'nearest_major_road' => 'Najbliži glavni put', 'nearest_named_watercourse' => 'Najbliži imenovani vodotok',
    ];

    public const METRICS = [
        'mapped_road_length_km' => ['Mapirani putevi', 'km', 'transport', 'mapped_road_length_km'],
        'mapped_road_density_km_per_km2' => ['Gustina mapirane putne mreže', 'km/km²', 'transport', 'mapped_road_density_km_per_km2'],
        'major_road_length_km' => ['Mapirani glavni putevi', 'km', 'transport', 'major_road_length_km'],
        'waterway_length_km' => ['Mapirani vodotoci', 'km', 'hydrography', 'waterway_length_km'],
        'distance_to_municipality_centre_km' => ['Pravolinijski do opštinskog centra', 'km', 'proximity', 'municipality_centre'],
        'distance_to_nearest_major_road_km' => ['Pravolinijski do glavnog puta', 'km', 'proximity', 'nearest_major_road'],
    ];

    public const CAVEAT = 'Prema trenutno dostupnim OSM podacima; nije službeni inventar. Potpunost mapiranja varira. Udaljenosti su pravolinijske od referentne tačke naselja, nisu putne udaljenosti ni vrijeme vožnje. Najbliži objekat znači najbliži u uvezenom skupu podataka.';
}
