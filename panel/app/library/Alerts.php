<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Per-user alert thresholds. They live in the panel database (not in the
 * Nightscout profile) because they are a notification preference of this UI,
 * not a treatment parameter other Nightscout clients should act on.
 */
final class Alerts
{
    public const MIN_LOW = 2.0;
    public const MAX_LOW = 10.0;
    public const MIN_HIGH = 5.0;
    public const MAX_HIGH = 25.0;

    /** @return array{low:float,high:float,enabled:bool,browser:bool} */
    public static function defaults(): array
    {
        return ['low' => 3.9, 'high' => 10.0, 'enabled' => false, 'browser' => false];
    }

    /** @return array{low:float,high:float,enabled:bool,browser:bool} */
    public static function load(int $userId): array
    {
        $stmt = Db::pdo()->prepare('SELECT low_mmol, high_mmol, enabled, browser FROM panel_alerts WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return self::defaults();
        }
        return [
            'low' => (float)$row['low_mmol'],
            'high' => (float)$row['high_mmol'],
            'enabled' => (bool)$row['enabled'],
            'browser' => (bool)$row['browser'],
        ];
    }

    /**
     * Validate and persist submitted settings.
     *
     * @param array<string,mixed> $input
     * @return array{0:array{low:float,high:float,enabled:bool,browser:bool},1:?string} settings and error
     */
    public static function save(int $userId, array $input): array
    {
        $low = filter_var($input['low'] ?? null, FILTER_VALIDATE_FLOAT);
        $high = filter_var($input['high'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($low === false || $high === false) {
            return [self::load($userId), Lang::t('alert.err.number')];
        }
        if ($low < self::MIN_LOW || $low > self::MAX_LOW) {
            return [self::load($userId), Lang::t('alert.err.lowRange', ['min' => self::MIN_LOW, 'max' => self::MAX_LOW])];
        }
        if ($high < self::MIN_HIGH || $high > self::MAX_HIGH) {
            return [self::load($userId), Lang::t('alert.err.highRange', ['min' => self::MIN_HIGH, 'max' => self::MAX_HIGH])];
        }
        if ($low >= $high) {
            return [self::load($userId), Lang::t('alert.err.order')];
        }

        $settings = [
            'low' => round($low, 2),
            'high' => round($high, 2),
            'enabled' => !empty($input['enabled']),
            'browser' => !empty($input['browser']),
        ];

        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_alerts (user_id, low_mmol, high_mmol, enabled, browser, updated_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(user_id) DO UPDATE SET
                low_mmol = excluded.low_mmol,
                high_mmol = excluded.high_mmol,
                enabled = excluded.enabled,
                browser = excluded.browser,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $userId,
            $settings['low'],
            $settings['high'],
            $settings['enabled'] ? 1 : 0,
            $settings['browser'] ? 1 : 0,
        ]);

        return [$settings, null];
    }

    /**
     * Which side of the configured band a reading falls on, if any.
     *
     * @param array{low:float,high:float,enabled:bool,browser:bool} $settings
     * @return string|null 'low', 'high' or null when inside the band/disabled
     */
    public static function state(float $value, array $settings): ?string
    {
        if (empty($settings['enabled'])) {
            return null;
        }
        if ($value < (float)$settings['low']) {
            return 'low';
        }
        if ($value > (float)$settings['high']) {
            return 'high';
        }
        return null;
    }
}
