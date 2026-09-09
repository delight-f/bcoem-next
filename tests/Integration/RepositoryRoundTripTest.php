<?php

declare(strict_types=1);

namespace BCOEM\Tests\Integration;

use App\Domain\BrewerRow;
use App\Domain\BrewingRow;
use App\Domain\JudgingScoresRow;
use App\Domain\PreferencesRow;
use App\Domain\StylesRow;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Domain-core round-trip tests for the typed data layer.
 *
 * Each baseline_ table maps to a readonly Domain Row class. These tests
 * prove insert -> fetch -> update -> delete round-trips preserve typed
 * values, against the CI MySQL service.
 */
final class RepositoryRoundTripTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_brewer_round_trip(): void
    {
        $id = DB::table('brewer')->insertGetId([
            'brewerFirstName' => 'Ada',
            'brewerLastName' => 'Lovelace',
            'brewerBreweryName' => 'Analytical Engines Brewing',
        ]);
        $this->assertIsInt($id);

        $row = BrewerRow::fromArray((array) DB::table('brewer')->where('id', $id)->first());
        $this->assertInstanceOf(BrewerRow::class, $row);
        $this->assertSame('Ada', $row->brewerFirstName);
        $this->assertSame('Lovelace', $row->brewerLastName);
        $this->assertSame('Analytical Engines Brewing', $row->brewerBreweryName);

        $this->assertSame(1, DB::table('brewer')->where('id', $id)->update(['brewerFirstName' => 'Augusta']));
        $updated = BrewerRow::fromArray((array) DB::table('brewer')->where('id', $id)->first());
        self::assertNotNull($updated);
        $this->assertSame('Augusta', $updated->brewerFirstName);

        DB::table('brewer')->where('id', $id)->delete();
        $this->assertNull(DB::table('brewer')->where('id', $id)->first());
    }

    public function test_brewing_round_trip(): void
    {
        $id = DB::table('brewing')->insertGetId([
            'brewBrewerID' => 1,
            'brewCategory' => '1',
            'brewSubCategory' => 'A',
            'brewStyle' => 'American Light Lager',
        ]);
        $this->assertIsInt($id);

        $row = BrewingRow::fromArray((array) DB::table('brewing')->where('id', $id)->first());
        $this->assertInstanceOf(BrewingRow::class, $row);
        $this->assertSame('1', $row->brewCategory);
        $this->assertSame('A', $row->brewSubCategory);

        $this->assertSame(1, DB::table('brewing')->where('id', $id)->update(['brewStyle' => 'International Pale Lager']));
        $row = BrewingRow::fromArray((array) DB::table('brewing')->where('id', $id)->first());
        self::assertNotNull($row);
        $this->assertSame('International Pale Lager', $row->brewStyle);

        DB::table('brewing')->where('id', $id)->delete();
        $this->assertNull(DB::table('brewing')->where('id', $id)->first());
    }

    public function test_styles_round_trip(): void
    {
        $id = DB::table('styles')->insertGetId([
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'A',
            'brewStyle' => 'American Light Lager',
            'brewStyleVersion' => 'BJCP2015',
        ]);
        $this->assertIsInt($id);

        $row = StylesRow::fromArray((array) DB::table('styles')->where('id', $id)->first());
        $this->assertInstanceOf(StylesRow::class, $row);
        $this->assertSame('American Light Lager', $row->brewStyle);

        $this->assertSame(1, DB::table('styles')->where('id', $id)->update(['brewStyleNum' => 'B']));
        $row = StylesRow::fromArray((array) DB::table('styles')->where('id', $id)->first());
        self::assertNotNull($row);
        $this->assertSame('B', $row->brewStyleNum);

        DB::table('styles')->where('id', $id)->delete();
        $this->assertNull(DB::table('styles')->where('id', $id)->first());
    }

    public function test_judging_scores_round_trip(): void
    {
        $id = DB::table('judging_scores')->insertGetId([
            'eid' => 1,
            'scoreEntry' => 38.5,
            'scorePlace' => 1,
        ]);
        $this->assertIsInt($id);

        $row = JudgingScoresRow::fromArray((array) DB::table('judging_scores')->where('id', $id)->first());
        $this->assertInstanceOf(JudgingScoresRow::class, $row);
        $this->assertSame(1.0, $row->scorePlace);
        $this->assertSame(38.5, $row->scoreEntry);

        $this->assertSame(1, DB::table('judging_scores')->where('id', $id)->update(['scorePlace' => 2]));
        $row = JudgingScoresRow::fromArray((array) DB::table('judging_scores')->where('id', $id)->first());
        self::assertNotNull($row);
        $this->assertSame(2.0, $row->scorePlace);

        DB::table('judging_scores')->where('id', $id)->delete();
        $this->assertNull(DB::table('judging_scores')->where('id', $id)->first());
    }

    public function test_preferences_row_hydration(): void
    {
        // The preferences table is a single-row config; hydrate a typed row
        // directly from an assoc array to assert the typed field mapping.
        $row = PreferencesRow::fromArray([
            'id' => 1,
            'prefsEntryLimit' => '100',
            'prefsTimeZone' => '-5.000',
            'prefsEmailHost' => 'smtp.example.com',
            'prefsSEF' => 'Y',
        ]);
        $this->assertInstanceOf(PreferencesRow::class, $row);
        $this->assertSame(100, $row->prefsEntryLimit);
        $this->assertSame(-5.0, $row->prefsTimeZone);
        $this->assertSame('smtp.example.com', $row->prefsEmailHost);
        $this->assertSame('Y', $row->prefsSEF);
    }
}
