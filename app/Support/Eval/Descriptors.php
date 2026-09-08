<?php

declare(strict_types=1);

namespace App\Support\Eval;

/**
 * Scoresheet section points, descriptors and checklist ticks per style
 * type — port of eval/descriptors.eval.php. Style types follow the
 * `styles.brewStyleType` column: 1 beer, 2 cider, 3 mead.
 *
 * Labels are condensed plain-English renderings of the legacy i18n
 * strings; descriptor values are stored comma-joined in the evaluation
 * row's `evalDescriptors` / `eval*Checklist` columns.
 */
final class Descriptors
{
    /** @var array<string, string> descriptors shared by every style type */
    private const COMMON = [
        'Alcoholic' => 'Warming or hot from alcohol',
        'Metallic' => 'Blood-like or tinny character',
        'Oxidized' => 'Stale, cardboard, sherry-like',
        'Phenolic' => 'Clove-like, medicinal, smoky',
        'Vegetal' => 'Cooked, canned or rotten vegetables',
    ];

    /** @var array<int, array<string, string>> */
    private const SPECIFIC = [
        1 => [ // beer
            'Astringent' => 'Drying, puckering, husky',
            'Acetaldehyde' => 'Green apples, latex',
            'Diacetyl' => 'Buttery, butterscotch',
            'DMS' => 'Creamed corn',
            'Estery' => 'Fruity: banana, pear, apple',
            'Grassy' => 'Cut grass, hay',
            'Light-struck' => 'Skunky',
            'Musty' => 'Damp, stale, moldy',
            'Solvent' => 'Nail polish remover, lacquer',
            'Sour/Acidic' => 'Vinegar, lemon, sour milk',
            'Sulfur' => 'Rotten eggs, burnt matches',
            'Yeasty' => 'Bready, yeast cake',
        ],
        2 => [ // cider
            'Acetaldehyde' => 'Green apples, latex',
            'Acetified' => 'Vinegary, acetic',
            'Acidic' => 'Sharp, tart, sour',
            'Astringent' => 'Drying, puckering, tannic',
            'Bitter' => 'Sharp on the back of the tongue',
            'Diacetyl' => 'Buttery, film on tongue',
            'Farmyard' => 'Barnyard, horsey, earthy',
            'Fruity' => 'Apple, pear, berry character',
            'Mousy' => 'Urine-like, caged animal',
            'Oaky' => 'Wood, vanilla, toast',
            'Oily/Ropy' => 'Oily film, viscous strands',
            'Oxidized' => 'Bruised apple, caramel, sherry',
            'Spicy/Smoky' => 'Clove, pepper, smoked',
            'Sulfide' => 'Rotten egg, onion',
            'Sulfite' => 'Burnt match, shrimp-like',
            'Sweet' => 'Residual sugar, sweet taste',
            'Thin' => 'Watery, lacking body',
        ],
        3 => [ // mead
            'Acetic' => 'Vinegar, salad dressing',
            'Acidic' => 'Sharp, tart, sour',
            'Alcoholic' => 'Hot, harsh, solvent',
            'Chemical' => 'Medicinal, plastic, chlorine',
            'Cloying' => 'Overly sweet, sticky',
            'Floral' => 'Honey blossom, perfumed',
            'Fruity' => 'Berry, citrus, tropical',
            'Moldy' => 'Damp basement, mildew',
            'Solvent' => 'Nail polish remover, lacquer',
            'Sulfury' => 'Rotten eggs, burnt match',
            'Tannic' => 'Puckering, tea-like astringency',
            'Waxy' => 'Lipstick, crayon, candle',
            'Yeasty' => 'Bready, yeast cake',
        ],
    ];

    /**
     * Section point ceilings; beer defaults differ from cider/mead
     * (descriptors.eval.php:172-200).
     *
     * @return array{aroma: int, appearance: int, flavor: int, mouthfeel: int, overall: int}
     */
    public static function points(int $styleType): array
    {
        return $styleType === 2 || $styleType === 3
            ? ['aroma' => 10, 'appearance' => 6, 'flavor' => 24, 'mouthfeel' => 5, 'overall' => 10]
            : ['aroma' => 12, 'appearance' => 3, 'flavor' => 20, 'mouthfeel' => 5, 'overall' => 10];
    }

    /**
     * Descriptor checkbox set ("mark all that apply"), common + specific,
     * sorted by label like the legacy ksort().
     *
     * @return array<string, string>
     */
    public static function descriptors(int $styleType): array
    {
        $all = array_merge(self::COMMON, self::SPECIFIC[$styleType] ?? self::SPECIFIC[1]);
        ksort($all);

        return $all;
    }

    /**
     * Structured-scoresheet tick lists per section (label => POST field),
     * keyed by style type; beer list is the fallback.
     *
     * @return array<string, array<string, string>>
     */
    public static function ticks(int $styleType): array
    {
        return [
            'aroma' => match ($styleType) {
                2 => ['Fruit' => 'evalAromaFruit', 'Alcohol' => 'evalAromaAlcohol', 'Fermentation characteristics' => 'evalAromaFerm'],
                3 => ['Honey' => 'evalAromaHoney', 'Alcohol' => 'evalAromaAlcohol', 'Fermentation characteristics' => 'evalAromaFerm', 'Complexity' => 'evalAromaComplexity'],
                default => ['Malt' => 'evalAromaMalt', 'Hops' => 'evalAromaHops', 'Fermentation characteristics' => 'evalAromaFerm'],
            },
            'flavor' => match ($styleType) {
                2, 3 => [
                    ($styleType === 2 ? 'Fruit' : 'Honey') => $styleType === 2 ? 'evalFlavorFruit' : 'evalFlavorHoney',
                    'Sweetness' => 'evalFlavorSweetness',
                    'Acidity' => 'evalFlavorAcidity',
                    'Tannin' => 'evalFlavorTannin',
                    'Alcohol' => 'evalFlavorAlcohol',
                    'Carbonation' => 'evalFlavorCarb',
                ],
                default => ['Malt' => 'evalFlavorMalt', 'Hops' => 'evalFlavorHops', 'Bitterness' => 'evalFlavorBitter', 'Fermentation characteristics' => 'evalFlavorFerm'],
            },
            'mouthfeel' => [
                'Body' => 'evalMouthfeelBody',
                'Carbonation' => 'evalMouthfeelCarb',
                'Warmth' => 'evalMouthfeelWarmth',
                'Creaminess' => 'evalMouthfeelCream',
                'Astringency' => 'evalMouthfeelAstr',
            ],
        ];
    }

    /**
     * Flaw checkboxes for the structured sheet
     * (flaws_structured_* arrays, descriptors.eval.php:100-170).
     *
     * @return list<string>
     */
    public static function flaws(int $styleType): array
    {
        return match ($styleType) {
            2 => ['Acetaldehyde', 'Acetified', 'Acidic', 'Alcoholic', 'Astringent', 'Bitter', 'Diacetyl', 'Farmyard', 'Fruity', 'Metallic', 'Mousy', 'Oaky', 'Oily/Ropy', 'Oxidized', 'Phenolic', 'Spicy/Smoky', 'Sulfide', 'Sulfite', 'Sweet', 'Thin', 'Vegetal'],
            3 => ['Acetic', 'Acidic', 'Alcoholic', 'Cardboard', 'Chemical', 'Cloudy', 'Cloying', 'Floral', 'Fruity', 'Harsh', 'Metallic', 'Moldy', 'Phenolic', 'Sherry', 'Solvent', 'Sulfury', 'Sweet', 'Tannic', 'Thin', 'Vegetal', 'Waxy', 'Yeasty'],
            default => ['Acetaldehyde', 'Alcoholic', 'Astringent', 'Brettanomyces', 'Diacetyl', 'DMS', 'Estery', 'Grassy', 'Light-struck', 'Medicinal', 'Metallic', 'Musty', 'Oxidized', 'Plastic', 'Solvent', 'Sour/Acidic', 'Smoky', 'Spicy', 'Sulfury', 'Vegetal'],
        };
    }
}
