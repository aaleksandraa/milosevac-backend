<?php

namespace Tests\Unit;

use App\Enums\DataSourceClass;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Support\Atlas\SourceAuthority;
use App\Support\Atlas\SourcePriorityComparator;
use Tests\TestCase;

class SourcePriorityComparatorTest extends TestCase
{
    public function test_higher_authority_rank_wins(): void
    {
        $official = DataSource::factory()->create([
            'authority_rank' => SourceAuthority::OFFICIAL_STATE,
            'source_class' => DataSourceClass::Official,
        ]);
        $osm = DataSource::factory()->create([
            'authority_rank' => SourceAuthority::OPENSTREETMAP,
            'source_class' => DataSourceClass::CommunityGeographic,
        ]);

        $comparator = new SourcePriorityComparator;

        $this->assertTrue($comparator->prefers($official, $osm));
        $this->assertFalse($comparator->prefers($osm, $official));
        $this->assertSame(-1, $comparator->compare($official, $osm));
    }

    public function test_verification_status_breaks_equal_rank(): void
    {
        $left = DataSource::factory()->create(['authority_rank' => SourceAuthority::OPENSTREETMAP]);
        $right = DataSource::factory()->create(['authority_rank' => SourceAuthority::OPENSTREETMAP]);

        $comparator = new SourcePriorityComparator;

        $this->assertTrue($comparator->prefers(
            $left,
            $right,
            VerificationStatus::ManuallyVerified,
            VerificationStatus::OsmImported,
        ));
        $this->assertFalse($comparator->prefers(
            $left,
            $right,
            VerificationStatus::Unverified,
            VerificationStatus::OfficialSourceVerified,
        ));
        $this->assertSame(0, $comparator->compare(
            $left,
            $right,
            VerificationStatus::OsmImported,
            VerificationStatus::OsmImported,
        ));
    }

    public function test_dataset_version_is_used_only_after_rank_and_verification(): void
    {
        $left = DataSource::factory()->create(['authority_rank' => SourceAuthority::OFFICIAL_STATE]);
        $right = DataSource::factory()->create(['authority_rank' => SourceAuthority::OFFICIAL_STATE]);

        $comparator = new SourcePriorityComparator;

        $this->assertLessThan(0, $comparator->compare(
            $left,
            $right,
            VerificationStatus::OfficialSourceVerified,
            VerificationStatus::OfficialSourceVerified,
            '2013',
            '1991',
        ));
    }

    public function test_import_timestamps_are_not_consulted(): void
    {
        $older = DataSource::factory()->create([
            'authority_rank' => SourceAuthority::OFFICIAL_STATE,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        $newer = DataSource::factory()->create([
            'authority_rank' => SourceAuthority::SECONDARY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue((new SourcePriorityComparator)->prefers($older, $newer));
    }

    public function test_verification_status_strength_order(): void
    {
        $this->assertGreaterThan(VerificationStatus::ManuallyVerified->strength(), VerificationStatus::OfficialSourceVerified->strength());
        $this->assertGreaterThan(VerificationStatus::OsmImported->strength(), VerificationStatus::ManuallyVerified->strength());
        $this->assertGreaterThan(VerificationStatus::Unverified->strength(), VerificationStatus::OsmImported->strength());
        $this->assertGreaterThan(VerificationStatus::Disputed->strength(), VerificationStatus::Unverified->strength());
    }
}
