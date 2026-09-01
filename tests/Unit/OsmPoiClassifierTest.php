<?php

namespace Tests\Unit;

use App\Enums\GeoPlaceClassificationStatus;
use App\Support\Atlas\Poi\OsmPoiClassifier;
use Tests\TestCase;

class OsmPoiClassifierTest extends TestCase
{
    private OsmPoiClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new OsmPoiClassifier;
    }

    public function test_school_subtypes_and_generic_school(): void
    {
        $this->assertType(['amenity' => 'kindergarten'], 'kindergarten', GeoPlaceClassificationStatus::ExactOsmMapping);
        $this->assertType(['amenity' => 'school', 'isced:level' => '1'], 'primary_school', GeoPlaceClassificationStatus::InferredOsmMapping);
        $this->assertType(['amenity' => 'school', 'school' => 'secondary'], 'secondary_school', GeoPlaceClassificationStatus::InferredOsmMapping);
        $this->assertType(['amenity' => 'university'], 'higher_education', GeoPlaceClassificationStatus::ExactOsmMapping);
        $this->assertType(['amenity' => 'school'], 'school_other', GeoPlaceClassificationStatus::ExactOsmMapping);
    }

    public function test_health_mappings(): void
    {
        $this->assertType(['amenity' => 'pharmacy'], 'pharmacy');
        $this->assertType(['amenity' => 'clinic'], 'clinic');
        $this->assertType(['amenity' => 'doctors'], 'doctors');
        $this->assertType(['healthcare' => 'hospital'], 'hospital');
        $this->assertType(['amenity' => 'hospital'], 'hospital');
        $this->assertType(['amenity' => 'veterinary'], 'veterinary');
    }

    public function test_sport_mappings_prefer_specific_over_generic_pitch(): void
    {
        $this->assertType(['leisure' => 'pitch', 'sport' => 'soccer'], 'football_pitch');
        $this->assertType(['leisure' => 'pitch', 'sport' => 'football;basketball'], 'football_pitch');
        $this->assertType(['leisure' => 'stadium'], 'stadium');
        $this->assertType(['leisure' => 'sports_hall'], 'sports_hall');
        $this->assertType(['leisure' => 'pitch', 'sport' => 'basketball'], 'basketball_court');
        $this->assertType(['leisure' => 'pitch'], 'sport_other', GeoPlaceClassificationStatus::InferredOsmMapping);
    }

    public function test_religion_requires_building_or_precise_type_not_religion_tag_alone(): void
    {
        $this->assertType(['building' => 'church', 'amenity' => 'place_of_worship', 'religion' => 'christian'], 'church');
        $this->assertType(['building' => 'mosque', 'amenity' => 'place_of_worship', 'religion' => 'muslim'], 'mosque');
        $this->assertType(['amenity' => 'monastery'], 'monastery');
        $this->assertType(['amenity' => 'place_of_worship', 'religion' => 'christian'], 'place_of_worship_other', GeoPlaceClassificationStatus::InferredOsmMapping);
        $this->assertType(['amenity' => 'place_of_worship', 'religion' => 'muslim'], 'place_of_worship_other', GeoPlaceClassificationStatus::InferredOsmMapping);
        $this->assertNull($this->classifier->classify(['name' => 'Crkva Svetog Save']));
        $this->assertNull($this->classifier->classify(['name' => 'Džamija', 'religion' => 'muslim']));
    }

    public function test_historic_tourism_transport_government_and_cemetery(): void
    {
        $this->assertType(['historic' => 'memorial'], 'memorial');
        $this->assertType(['historic' => 'monument'], 'monument');
        $this->assertType(['historic' => 'castle'], 'historic_site', GeoPlaceClassificationStatus::InferredOsmMapping);
        $this->assertType(['amenity' => 'grave_yard'], 'cemetery');
        $this->assertType(['tourism' => 'attraction'], 'attraction');
        $this->assertType(['tourism' => 'hotel'], 'hotel');
        $this->assertType(['highway' => 'bus_stop'], 'bus_stop');
        $this->assertType(['amenity' => 'fire_station'], 'fire_station');
    }

    public function test_unknown_combination_is_unclassified(): void
    {
        $this->assertNull($this->classifier->classify(['amenity' => 'bench']));
        $this->assertNull($this->classifier->classify(['shop' => 'convenience']));
        $this->assertNull($this->classifier->classify([]));
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function assertType(array $tags, string $code, GeoPlaceClassificationStatus $status = GeoPlaceClassificationStatus::ExactOsmMapping): void
    {
        $result = $this->classifier->classify($tags);
        $this->assertNotNull($result, 'Expected '.$code.' for '.json_encode($tags));
        $this->assertSame($code, $result->typeCode);
        $this->assertSame($status, $result->status);
    }
}
