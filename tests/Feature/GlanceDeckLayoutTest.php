<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The at-a-glance deck's column count.
 *
 * The deck deliberately centres an incomplete final row, so 3, 5 and 7 cards
 * read well rather than leaving a ragged hole. But on a fixed 3-wide grid a
 * 4-card deck wrapped to 3 + 1 and the lone card sat centred beneath the others
 * — three cards in a row and a small stray one under them, which reads as a
 * mistake rather than a layout. A count that divides by four takes four columns
 * instead and fills its row.
 */
final class GlanceDeckLayoutTest extends TestCase
{
    /** @return list<array<string, string>> */
    private function cards(int $count): array
    {
        return array_map(static fn (int $i): array => [
            'title' => 'Card '.$i,
            'pill' => 'Open',
            'color' => 'success',
            'accent' => 'blue',
            'icon' => 'clock',
            'body' => '<p>Body '.$i.'</p>',
        ], range(1, $count));
    }

    public function test_a_four_card_deck_fills_one_row_of_four(): void
    {
        $html = view('public.partials.glance', ['cards' => $this->cards(4)])->render();

        $this->assertStringContainsString('row-cols-lg-4', $html);
        $this->assertStringNotContainsString('row-cols-lg-3', $html);
    }

    public function test_a_three_card_deck_keeps_its_three_columns(): void
    {
        $html = view('public.partials.glance', ['cards' => $this->cards(3)])->render();

        $this->assertStringContainsString('row-cols-lg-3', $html);
    }

    public function test_five_cards_stay_on_the_centred_three_column_deck(): void
    {
        // 3 + 2 centred is the arrangement the deck was designed around.
        $html = view('public.partials.glance', ['cards' => $this->cards(5)])->render();

        $this->assertStringContainsString('row-cols-lg-3', $html);
        $this->assertStringContainsString('justify-content-center', $html);
    }

    public function test_eight_cards_use_two_full_rows_of_four(): void
    {
        $html = view('public.partials.glance', ['cards' => $this->cards(8)])->render();

        $this->assertStringContainsString('row-cols-lg-4', $html);
    }

    public function test_the_stacked_sidebar_variant_is_unaffected(): void
    {
        $html = view('public.partials.glance', ['cards' => $this->cards(4), 'stacked' => true])->render();

        $this->assertStringContainsString('row-cols-1 gy-3', $html);
        $this->assertStringNotContainsString('row-cols-lg-4', $html);
    }
}
