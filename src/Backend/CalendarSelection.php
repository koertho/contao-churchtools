<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Backend;

/** The database contract is JSON, never Contao's widget serialization. */
final class CalendarSelection
{
    public static function decode(mixed $value): array
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        $ids = $value === null ? [] : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \InvalidArgumentException('Invalid stored calendar selection.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 4294967295) {
                throw new \InvalidArgumentException('Invalid stored calendar ID.');
            }
        }

        return array_values(array_unique($ids));
    }

    public static function fromWidget(mixed $value): string
    {
        // DC_Table serializes multiple widgets before invoking fields.*.save.
        if (is_string($value) && str_starts_with($value, 'a:')) {
            $value = unserialize($value, ['allowed_classes' => false]);
        }
        if ($value === null || $value === '') {
            $value = [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Invalid calendar selection.');
        }
        $ids = [];
        foreach ($value as $id) {
            if ((!is_int($id) && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id))) || (int) $id < 1 || (int) $id > 4294967295) {
                throw new \InvalidArgumentException('Invalid calendar ID.');
            }
            $ids[] = (int) $id;
        }

        return json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR);
    }
}
