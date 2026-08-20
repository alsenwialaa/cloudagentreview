<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\WordPress\WidgetAppearance;

test('Widget appearance exposes four bounded presets and rejects unsupported values', static function (): void {
    $presets = WidgetAppearance::presets();
    assert_same(array('yassin', 'ocean', 'forest', 'midnight'), array_keys($presets));
    assert_same('yassin', WidgetAppearance::normalizePreset('unknown'));
    assert_same('#abcdef', WidgetAppearance::normalizeColor('#ABCDEF', '#000000'));
    assert_same('#123456', WidgetAppearance::normalizeColor('rgb(1,2,3)', '#123456'));
    assert_false(WidgetAppearance::isColor('#fff'));
    assert_false(WidgetAppearance::isColor('var(--merchant-color)'));
});

test('Widget appearance moves inherited colours with a new preset while preserving deliberate overrides', static function (): void {
    $current = array_merge(
        array('widget_theme_preset' => 'yassin'),
        array_combine(
            array_keys(WidgetAppearance::colorSettings()),
            array_values(array_intersect_key(
                WidgetAppearance::palette('yassin'),
                array_flip(array_values(WidgetAppearance::colorSettings()))
            ))
        ) ?: array()
    );
    $current['widget_user_bubble_color'] = '#112233';

    $submitted = $current;
    $result = WidgetAppearance::sanitizeForSave($submitted, $current, 'ocean');
    assert_same('#0e7490', $result['values']['widget_brand_color']);
    assert_same('#112233', $result['values']['widget_user_bubble_color']);
    assert_same(array(), $result['invalid_fields']);
});

test('Widget appearance keeps the last safe colour when submitted colour input is malformed', static function (): void {
    $palette = WidgetAppearance::palette('forest');
    $current = array('widget_theme_preset' => 'forest');
    foreach (WidgetAppearance::colorSettings() as $setting => $paletteKey) {
        $current[$setting] = $palette[$paletteKey];
    }
    $input = $current;
    $input['widget_canvas_color'] = 'url(javascript:alert(1))';

    $result = WidgetAppearance::sanitizeForSave($input, $current, 'forest');
    assert_same($palette['canvas'], $result['values']['widget_canvas_color']);
    assert_same(array('widget_canvas_color'), $result['invalid_fields']);
});

test('Widget appearance derives readable foreground tokens for light and dark palettes', static function (): void {
    foreach (array('yassin', 'midnight') as $preset) {
        $options = array('widget_theme_preset' => $preset);
        $tokens = WidgetAppearance::cssTokens($options);
        assert_same(16, count($tokens));
        assert_true(WidgetAppearance::contrastRatio($tokens['--ysai-panel'], $tokens['--ysai-ink']) >= 4.5);
        assert_true(WidgetAppearance::contrastRatio($tokens['--ysai-assistant'], $tokens['--ysai-assistant-text']) >= 4.5);
        assert_true(WidgetAppearance::contrastRatio($tokens['--ysai-user'], $tokens['--ysai-user-text']) >= 4.5);
        assert_true(WidgetAppearance::contrastRatio($tokens['--ysai-primary'], $tokens['--ysai-on-primary']) >= 4.5);
    }
});
