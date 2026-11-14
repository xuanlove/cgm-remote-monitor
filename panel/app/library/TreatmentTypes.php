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
    /** Field name => [kind, label key, unit, min, max, step] */
    public static function fields(): array
    {
        return [
            'glucose' => ['number', 'field.glucose', 'mg/dL', 10, 600, '1'],
            'carbs' => ['number', 'field.carbs', 'g', 0, 500, '1'],
            'insulin' => ['number', 'field.insulin', 'U', 0, 100, '0.05'],
            'duration' => ['number', 'field.duration', 'min', 0, 1440, '1'],
            'absolute' => ['number', 'field.absolute', 'U/h', 0, 15, '0.05'],
            'percent' => ['number', 'field.percent', '%', -100, 500, '1'],
            'targetTop' => ['number', 'field.targetTop', 'mg/dL', 40, 400, '1'],
            'targetBottom' => ['number', 'field.targetBottom', 'mg/dL', 40, 400, '1'],
            'profile' => ['text', 'field.profile', '', 0, 0, ''],
            'notes' => ['text', 'field.notes', '', 0, 0, ''],
        ];
    }

    /** eventType => [label key, fields used, group] */
    public static function types(): array
    {
        return [
            'Meal Bolus' => ['type.mealBolus', ['glucose', 'carbs', 'insulin', 'notes'], 'bolus'],
            'Snack Bolus' => ['type.snackBolus', ['glucose', 'carbs', 'insulin', 'notes'], 'bolus'],
            'Correction Bolus' => ['type.correctionBolus', ['glucose', 'insulin', 'notes'], 'bolus'],
            'Combo Bolus' => ['type.comboBolus', ['glucose', 'carbs', 'insulin', 'duration', 'notes'], 'bolus'],
            'Carb Correction' => ['type.carbCorrection', ['glucose', 'carbs', 'notes'], 'bolus'],
            'BG Check' => ['type.bgCheck', ['glucose', 'notes'], 'monitor'],
            'Note' => ['type.note', ['notes'], 'monitor'],
            'Announcement' => ['type.announcement', ['notes'], 'monitor'],
            'Question' => ['type.question', ['notes'], 'monitor'],
            'Exercise' => ['type.exercise', ['duration', 'notes'], 'monitor'],
            'Temp Basal' => ['type.tempBasal', ['absolute', 'percent', 'duration', 'notes'], 'temp'],
            'Temp Target' => ['type.tempTarget', ['targetTop', 'targetBottom', 'duration', 'notes'], 'temp'],
            'Profile Switch' => ['type.profileSwitch', ['profile', 'duration', 'notes'], 'temp'],
            'Site Change' => ['type.siteChange', ['notes'], 'care'],
            'Sensor Start' => ['type.sensorStart', ['notes'], 'care'],
            'Sensor Change' => ['type.sensorChange', ['notes'], 'care'],
            'Insulin Change' => ['type.insulinChange', ['notes'], 'care'],
            'Pump Battery Change' => ['type.pumpBatteryChange', ['notes'], 'care'],
            'Pump Suspend' => ['type.pumpSuspend', ['notes'], 'care'],
            'Pump Resume' => ['type.pumpResume', ['notes'], 'care'],
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
                    throw new \InvalidArgumentException(Lang::t('val.number', ['field' => Lang::t($def[1])]));
                }
                $value = (float)$raw;
                if ($value < $def[3] || $value > $def[4]) {
                    throw new \InvalidArgumentException(Lang::t('val.range', [
                        'field' => Lang::t($def[1]),
                        'min' => $def[3],
                        'max' => $def[4],
                    ]));
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
