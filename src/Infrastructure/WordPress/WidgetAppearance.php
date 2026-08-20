<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

/**
 * Central storefront appearance policy.
 *
 * Presets define safe, contrast-aware defaults. Merchants may override the
 * bounded surface colours, while readable foreground colours remain derived
 * by the application rather than accepted as arbitrary input.
 */
final class WidgetAppearance
{
    public const DEFAULT_PRESET = 'yassin';

    /** @return array<string,array<string,string>> */
    public static function presets(): array
    {
        return array(
            'yassin' => array(
                'label' => 'ياسين — خمري دافئ',
                'brand' => '#7f1d1d',
                'brand_strong' => '#450a0a',
                'accent' => '#f59e0b',
                'surface' => '#fffdfa',
                'canvas' => '#f7f2f0',
                'assistant' => '#ffffff',
                'user' => '#7f1d1d',
                'receipt' => '#ecfdf3',
                'border' => '#eadfe0',
            ),
            'ocean' => array(
                'label' => 'محيط — أزرق هادئ',
                'brand' => '#0e7490',
                'brand_strong' => '#164e63',
                'accent' => '#22d3ee',
                'surface' => '#f8fdff',
                'canvas' => '#eef8fb',
                'assistant' => '#ffffff',
                'user' => '#0e7490',
                'receipt' => '#ecfeff',
                'border' => '#d8e9ee',
            ),
            'forest' => array(
                'label' => 'غابة — أخضر طبيعي',
                'brand' => '#2f6f4e',
                'brand_strong' => '#17452f',
                'accent' => '#84cc16',
                'surface' => '#fbfefb',
                'canvas' => '#eef7f0',
                'assistant' => '#ffffff',
                'user' => '#2f6f4e',
                'receipt' => '#ecfdf3',
                'border' => '#dbe9de',
            ),
            'midnight' => array(
                'label' => 'ليل — داكن وذهبي',
                'brand' => '#20243a',
                'brand_strong' => '#0b0d17',
                'accent' => '#d6a94a',
                'surface' => '#171a2b',
                'canvas' => '#10121e',
                'assistant' => '#24283d',
                'user' => '#d6a94a',
                'receipt' => '#14362e',
                'border' => '#34394f',
            ),
        );
    }

    /** @return array<string,string> */
    public static function colorSettings(): array
    {
        return array(
            'widget_brand_color' => 'brand',
            'widget_brand_strong_color' => 'brand_strong',
            'widget_surface_color' => 'surface',
            'widget_canvas_color' => 'canvas',
            'widget_assistant_bubble_color' => 'assistant',
            'widget_user_bubble_color' => 'user',
            'widget_receipt_bubble_color' => 'receipt',
            'widget_border_color' => 'border',
        );
    }

    /** @return array<string,string> */
    public static function palette(string $preset): array
    {
        $presets = self::presets();
        return $presets[$preset] ?? $presets[self::DEFAULT_PRESET];
    }

    public static function normalizePreset(mixed $preset): string
    {
        return is_string($preset) && array_key_exists($preset, self::presets())
            ? $preset
            : self::DEFAULT_PRESET;
    }

    public static function isColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    public static function normalizeColor(mixed $value, string $fallback): string
    {
        return self::isColor($value) ? strtolower((string) $value) : strtolower($fallback);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function resolvedColors(array $options): array
    {
        $preset = self::normalizePreset($options['widget_theme_preset'] ?? null);
        $palette = self::palette($preset);
        $resolved = array();
        foreach (self::colorSettings() as $setting => $paletteKey) {
            $resolved[$setting] = self::normalizeColor(
                $options[$setting] ?? null,
                $palette[$paletteKey]
            );
        }
        return $resolved;
    }

    /**
     * Preserve deliberate overrides while allowing untouched preset colours to
     * follow a newly selected preset even when JavaScript is unavailable.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array{values:array<string,string>,invalid_fields:list<string>}
     */
    public static function sanitizeForSave(array $input, array $current, string $submittedPreset): array
    {
        $currentPreset = self::normalizePreset($current['widget_theme_preset'] ?? null);
        $submittedPreset = self::normalizePreset($submittedPreset);
        $currentPalette = self::palette($currentPreset);
        $submittedPalette = self::palette($submittedPreset);
        $presetChanged = $currentPreset !== $submittedPreset;
        $values = array();
        $invalid = array();

        foreach (self::colorSettings() as $setting => $paletteKey) {
            $currentValue = self::normalizeColor($current[$setting] ?? null, $currentPalette[$paletteKey]);
            $inheritsCurrentPreset = hash_equals($currentValue, strtolower($currentPalette[$paletteKey]));
            $candidate = $input[$setting] ?? $currentValue;
            if (!self::isColor($candidate)) {
                $invalid[] = $setting;
                $values[$setting] = $currentValue;
                continue;
            }
            $candidate = strtolower((string) $candidate);
            $values[$setting] = $presetChanged
                && $inheritsCurrentPreset
                && hash_equals($candidate, $currentValue)
                ? strtolower($submittedPalette[$paletteKey])
                : $candidate;
        }

        return array('values' => $values, 'invalid_fields' => $invalid);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function cssTokens(array $options): array
    {
        $colors = self::resolvedColors($options);
        $brand = $colors['widget_brand_color'];
        $brandStrong = $colors['widget_brand_strong_color'];
        $surface = $colors['widget_surface_color'];
        $canvas = $colors['widget_canvas_color'];
        $assistant = $colors['widget_assistant_bubble_color'];
        $user = $colors['widget_user_bubble_color'];
        $receipt = $colors['widget_receipt_bubble_color'];
        $border = $colors['widget_border_color'];

        return array(
            '--ysai-primary' => $brand,
            '--ysai-primary-strong' => $brandStrong,
            '--ysai-accent' => self::palette(self::normalizePreset($options['widget_theme_preset'] ?? null))['accent'],
            '--ysai-panel' => $surface,
            '--ysai-canvas' => $canvas,
            '--ysai-line' => $border,
            '--ysai-assistant' => $assistant,
            '--ysai-user' => $user,
            '--ysai-receipt' => $receipt,
            '--ysai-ink' => self::readableText($surface, '#172033'),
            '--ysai-muted' => self::readableMuted($surface),
            '--ysai-assistant-text' => self::readableText($assistant, '#172033'),
            '--ysai-user-text' => self::readableText($user, '#ffffff'),
            '--ysai-receipt-text' => self::readableText($receipt, '#14532d'),
            '--ysai-on-primary' => self::readableText($brand, '#ffffff'),
            '--ysai-on-primary-strong' => self::readableText($brandStrong, '#ffffff'),
        );
    }

    public static function readableText(string $background, string $preferred): string
    {
        $background = self::normalizeColor($background, '#ffffff');
        $preferred = self::normalizeColor($preferred, '#000000');
        if (self::contrastRatio($background, $preferred) >= 4.5) {
            return $preferred;
        }
        return self::contrastRatio($background, '#000000') >= self::contrastRatio($background, '#ffffff')
            ? '#000000'
            : '#ffffff';
    }

    private static function readableMuted(string $background): string
    {
        $background = self::normalizeColor($background, '#ffffff');
        foreach (array('#667085', '#526071', '#94a3b8', '#d0d5dd', '#e5e7eb') as $candidate) {
            if (self::contrastRatio($background, $candidate) >= 4.5) {
                return $candidate;
            }
        }
        return self::readableText($background, '#667085');
    }

    public static function contrastRatio(string $first, string $second): float
    {
        $left = self::luminance(self::normalizeColor($first, '#000000'));
        $right = self::luminance(self::normalizeColor($second, '#ffffff'));
        $high = max($left, $right);
        $low = min($left, $right);
        return ($high + 0.05) / ($low + 0.05);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $color): array
    {
        return array(
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        );
    }

    private static function luminance(string $color): float
    {
        $linear = array();
        foreach (self::rgb($color) as $channel) {
            $value = $channel / 255;
            $linear[] = $value <= 0.04045
                ? $value / 12.92
                : pow(($value + 0.055) / 1.055, 2.4);
        }
        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
