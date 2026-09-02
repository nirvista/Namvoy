<?php

declare(strict_types=1);

namespace App;

/**
 * Shared read helpers for experiences. Callers are responsible for the
 * authorization scope (ownership / status) of the WHERE clause they pass.
 */
final class Experiences
{
    public const STATUSES = ['draft', 'pending_review', 'published', 'suspended'];
    public const CURRENCIES = ['USD', 'VND', 'EUR', 'GBP', 'AUD', 'INR'];

    private const SELECT = 'SELECT e.id, e.business_id, e.title, e.slug, e.description, e.duration_minutes, e.max_group_size,
                e.price_amount, e.price_currency, e.languages, e.included_items, e.cancellation_policy,
                e.status, e.created_at,
                d.id AS destination_id, d.slug AS destination_slug, d.name AS destination_name,
                c.id AS category_id, c.slug AS category_slug, c.name AS category_name,
                b.business_name
         FROM experiences e
         JOIN destinations d ON d.id = e.destination_id
         JOIN categories c ON c.id = e.category_id
         JOIN businesses b ON b.id = e.business_id';

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $where, string $types, array $params, string $orderBy = 'e.created_at DESC'): array
    {
        $rows = Database::fetchAll(self::SELECT . ' WHERE ' . $where . ' ORDER BY ' . $orderBy, $types, $params);
        return array_map([self::class, 'present'], self::attachImages($rows));
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $where, string $types, array $params): ?array
    {
        $row = Database::fetchOne(self::SELECT . ' WHERE ' . $where . ' LIMIT 1', $types, $params);
        if ($row === null) {
            return null;
        }
        return self::present(self::attachImages([$row])[0]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function attachImages(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $images = Database::fetchAll(
            "SELECT id, experience_id, image_url, display_order FROM experience_images
             WHERE experience_id IN ({$placeholders}) ORDER BY display_order ASC, id ASC",
            str_repeat('s', count($ids)),
            $ids
        );
        $byExp = [];
        foreach ($images as $img) {
            $byExp[$img['experience_id']][] = ['id' => $img['id'], 'url' => $img['image_url'], 'display_order' => (int) $img['display_order']];
        }
        foreach ($rows as &$row) {
            $row['images'] = $byExp[$row['id']] ?? [];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => $row['id'],
            'business_id' => $row['business_id'],
            'business_name' => $row['business_name'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'max_group_size' => (int) $row['max_group_size'],
            'price_amount' => $row['price_amount'],
            'price_currency' => $row['price_currency'],
            'languages' => json_decode((string) $row['languages'], true) ?: [],
            'included_items' => json_decode((string) $row['included_items'], true) ?: [],
            'cancellation_policy' => $row['cancellation_policy'],
            'status' => $row['status'],
            'destination' => ['id' => $row['destination_id'], 'slug' => $row['destination_slug'], 'name' => $row['destination_name']],
            'category' => ['id' => $row['category_id'], 'slug' => $row['category_slug'], 'name' => $row['category_name']],
            'images' => $row['images'],
            'created_at' => $row['created_at'],
        ];
    }

    /** URL-safe slug from a title plus a short random suffix for uniqueness. */
    public static function makeSlug(string $title): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title), '-'));
        $base = substr($base !== '' ? $base : 'experience', 0, 200);
        return $base . '-' . bin2hex(random_bytes(3));
    }
}
