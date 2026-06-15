<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

class DateFormat
{
    public static function input(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y. H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'd.m.Y. H:i',
            'd.m.Y H:i',
            'd.m.Y. H:i:s',
            'd.m.Y H:i:s',
            'd.m.Y.',
            'd.m.Y',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat('!'.$format, $value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                //
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }

    public static function normalizeRequest(Request $request, array $fields): void
    {
        $values = [];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $values[$field] = self::normalize($request->input($field));
            }
        }

        if ($values !== []) {
            $request->merge($values);
        }
    }
}
