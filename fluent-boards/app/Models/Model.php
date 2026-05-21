<?php

namespace FluentBoards\App\Models;

use DateTimeInterface;
use FluentBoards\Framework\Database\Orm\Model as BaseModel;

class Model extends BaseModel
{
    protected $guarded = ['id', 'ID'];

    protected $nullableTimestampAttributes = [];

    public function setAttribute($key, $value)
    {
        if ($this->isNullableTimestampAttribute($key)) {
            $value = $this->normalizeNullableTimestampValue($value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if ($this->isNullableTimestampAttribute($key)) {
            return $this->normalizeNullableTimestampValue($value);
        }

        return $value;
    }

    protected function isNullableTimestampAttribute($key)
    {
        return is_string($key) && in_array($key, $this->nullableTimestampAttributes, true);
    }

    protected function normalizeNullableTimestampValue($value)
    {
        if ($value instanceof DateTimeInterface) {
            return (int) $value->format('Y') < 1900 ? null : $this->fromDateTime($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            $normalizedValue = strtolower($value);

            if (in_array($normalizedValue, ['none', 'null'], true)) {
                return null;
            }

            if (in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
                return null;
            }

            if (preg_match('/^(\d{4})-/', $value, $matches) && (int) $matches[1] < 1900) {
                return null;
            }

            if (strtotime($value) === false) {
                return null;
            }
        }

        return $value;
    }
}
