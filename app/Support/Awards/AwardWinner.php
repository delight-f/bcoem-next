<?php

declare(strict_types=1);

namespace App\Support\Awards;

/**
 * One medal-grid entry on a winner slide.
 */
final readonly class AwardWinner
{
    public function __construct(
        public string $place,      // display_place(…,1): 1st/2nd/3rd/4th/HM
        public int $fh,            // place_heirarchy: 5→HM row, 1→1st row (legacy invert)
        public string $name,       // brewer or brewery (Pro)
        public string $club,       // '' when suppressed
        public string $entry,      // brewName (legacy 65-char truncation)
        public string $style,      // style_display per style set
        public string $coBrewer,   // '' when absent (20-char truncation)
    ) {}
}
