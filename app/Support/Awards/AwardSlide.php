<?php

declare(strict_types=1);

namespace App\Support\Awards;

/**
 * One deck <section>.
 */
final readonly class AwardSlide
{
    /**
     * @param  list<AwardWinner>  $winners
     */
    public function __construct(
        public string $title,
        public string $subtitle,     // entry-count line below the h1 ('' when none)
        public string $judgesLine,   // "Judges: …" line (table slides, legacy :157-170)
        public string $titleLong,    // alternate sort key (table name / category)
        public int $count,           // entry count for the group line
        public array $winners,
    ) {}
}
