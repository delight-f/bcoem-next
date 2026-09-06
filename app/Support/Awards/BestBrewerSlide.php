<?php

declare(strict_types=1);

namespace App\Support\Awards;

/**
 * Best Brewer / Best Club ranked table slide.
 */
final readonly class BestBrewerSlide
{
    /** @param  list<object{name:string,club:string|null,points:float,places:list<int>}>  $rows */
    public function __construct(
        public string $title,         // prefsBestBrewerTitle / prefsBestClubTitle
        public string $participantLine, // "N participating brewers|clubs"
        public array $rows,
        public bool $show4th,
        public bool $showHm,
        public bool $showClub,
        public bool $proEdition,
    ) {}
}