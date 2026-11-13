<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Catalogue of the Nightscout treatment event types the panel can write,
 * and the fields each one uses. The entry form is built from this registry
 * in JavaScript, and the controller validates against it, so a type the
 * panel does not know can never reach Nightscout through the panel.
 */
final class TreatmentTypes
{
    /** Field name => [kind, label, unit, min, max, step] */
    public static function fields(): array
    {
        return [
            'glucose' => ['number', '血糖', 'mg/dL', 10, 600, '1'],
            'carbs' => ['number', '碳水', 'g', 0, 500, '1'],
            'insulin' => ['number', '胰岛素', 'U', 0, 100, '0.05'],
            'duration' => ['number', '时长', '分钟', 0, 1440, '1'],
            'absolute' => ['number', '临时基础率', 'U/h', 0, 15, '0.05'],
            'percent' => ['number', '临时基础率百分比', '%', -100, 500, '1'],
            'targetTop' => ['number', '目标上限', 'mg/dL', 40, 400, '1'],
            'targetBottom' => ['number', '目标下限', 'mg/dL', 40, 400, '1'],
            'profile' => ['text', '目标方案名', '', 0, 0, ''],
            'notes' => ['text', '备注', '', 0, 0, ''],
        ];
    }

    /** eventType => [label, fields used, group] */
    public static function types(): array
    {
        return [
            'Meal Bolus' => ['正餐大剂量', ['glucose', 'carbs', 'insulin', 'notes'], 'bolus'],
            'Snack Bolus' => ['加餐大剂量', ['glucose', 'carbs', 'insulin', 'notes'], 'bolus'],
            'Correction Bolus' => ['纠正大剂量', ['glucose', 'insulin', 'notes'], 'bolus'],
            'Combo Bolus' => ['双波大剂量', ['glucose', 'carbs', 'insulin', 'duration', 'notes'], 'bolus'],
            'Carb Correction' => ['碳水修正', ['glucose', 'carbs', 'notes'], 'bolus'],
            'BG Check' => ['测量血糖', ['glucose', 'notes'], 'monitor'],
            'Note' => ['备注', ['notes'], 'monitor'],
            'Announcement' => ['公告', ['notes'], 'monitor'],
            'Question' => ['提问', ['notes'], 'monitor'],
            'Exercise' => ['运动', ['duration', 'notes'], 'monitor'],
            'Temp Basal' => ['临时基础率', ['absolute', 'percent', 'duration', 'notes'], 'temp'],
            'Temp Target' => ['临时目标', ['targetTop', 'targetBottom', 'duration', 'notes'], 'temp'],
            'Profile Switch' => ['切换目标方案', ['profile', 'duration', 'notes'], 'temp'],
            'Site Change' => ['更换输注部位', ['notes'], 'care'],
            'Sensor Start' => ['传感器启动', ['notes'], 'care'],
            'Sensor Change' => ['更换传感器', ['notes'], 'care'],
            'Insulin Change' => ['更换胰岛素', ['notes'], 'care'],
            'Pump Battery Change' => ['更换泵电池', ['notes'], 'care'],
            'Pump Suspend' => ['暂停泵', ['notes'], 'care'],
            'Pump Resume' => ['恢复泵', ['notes'], 'care'],
        ];
    }

    public static function has(string $eventType): bool
    {
        return isset(self::types()[$eventType]);
    }

    public static function fieldsFor(string $eventType): array
    {
        return self::types()[$eventType][1] ?? [];
    }

    /**
     * Build a Nightscout treatment document from submitted form values,
     * keeping only the fields that belong to the chosen event type.
     *
     * @param array<string,mixed> $input Raw POST fields
     * @return array<string,mixed>
     */
    public static function build(string $eventType, array $input): array
    {
        $record = ['eventType' => $eventType];
        foreach (self::fieldsFor($eventType) as $name) {
            $def = self::fields()[$name] ?? null;
            if ($def === null) {
                continue;
            }
            $raw = $input[$name] ?? '';
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if ($raw === '' || $raw === null) {
                continue;
            }
            if ($def[0] === 'number') {
                if (!is_numeric($raw)) {
                    throw new \InvalidArgumentException('「' . $def[1] . '」必须是数字');
                }
                $value = (float)$raw;
                if ($value < $def[3] || $value > $def[4]) {
                    throw new \InvalidArgumentException('「' . $def[1] . '」需在 ' . $def[3] . ' ~ ' . $def[4] . ' 之间');
                }
                $record[$name] = $value;
            } else {
                $record[$name] = mb_substr((string)$raw, 0, 500);
            }
        }

        // A finger-stick glucose recorded through a treatment carries its source.
        if (isset($record['glucose'])) {
            $record['glucoseType'] = 'Finger';
        }

        return $record;
    }
}
