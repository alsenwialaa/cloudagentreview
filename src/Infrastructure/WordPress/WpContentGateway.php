<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Contract\ContentGateway;
use YassinStore\AiAssistant\Application\Support\Text;

final class WpContentGateway implements ContentGateway
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SameOriginUrl $urls
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $wpQuery = new \WP_Query(array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => max(1, min(10, $limit)),
            'no_found_rows' => true,
            'orderby' => 'relevance',
            'has_password' => false,
        ));
        $results = array();
        foreach ($wpQuery->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            if ($this->isProtected($post)) {
                continue;
            }
            $results[] = array(
                'id' => (int) $post->ID,
                'title' => Text::plain((string) get_the_title($post), 300),
                'excerpt' => $this->slice(wp_strip_all_tags(get_the_excerpt($post)), 500),
                'url' => $this->urls->sanitize(get_permalink($post)),
            );
        }
        return $results;
    }

    public function get(int $id): ?array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post
            || $post->post_status !== 'publish'
            || !in_array($post->post_type, array('page', 'post'), true)
            || $this->isProtected($post)) {
            return null;
        }
        return array(
            'id' => $id,
            'title' => Text::plain((string) get_the_title($post), 300),
            'content' => $this->slice(wp_strip_all_tags(strip_shortcodes($post->post_content)), 8000),
            'url' => $this->urls->sanitize(get_permalink($post)),
        );
    }

    public function storeInfo(): array
    {
        return array(
            'name' => Text::plain((string) get_bloginfo('name'), 200),
            'description' => Text::plain((string) get_bloginfo('description'), 500),
            'home_url' => $this->urls->sanitize(home_url('/')),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'contact_url' => $this->configuredUrl('contact_url'),
            'about_url' => $this->configuredUrl('about_url'),
            'account_url' => $this->configuredUrl('account_url'),
        );
    }

    public function policies(): array
    {
        return array(
            'shipping_url' => $this->configuredUrl('shipping_url'),
            'returns_url' => $this->configuredUrl('returns_url'),
            'terms_url' => $this->configuredUrl('terms_url'),
            'contact_url' => $this->configuredUrl('contact_url'),
        );
    }

    private function slice(string $value, int $length): string
    {
        return Text::slice($value, 0, $length);
    }

    private function configuredUrl(string $key): string
    {
        return $this->urls->sanitize((string) $this->settings->get($key, ''));
    }

    private function isProtected(\WP_Post $post): bool
    {
        if (trim((string) $post->post_password) !== '') {
            return true;
        }
        return function_exists('post_password_required') && post_password_required($post);
    }
}
