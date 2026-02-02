<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

class ApiQuery
{
    /**
     * Apply whitelisted filters from ?filter[field]=value.
     * Allowed values can be:
     *  - true (use same field name)
     *  - string (column name)
     *  - Closure (custom handler)
     */
    public static function applyFilters(EloquentBuilder|QueryBuilder $query, Request $request, array $allowed): EloquentBuilder|QueryBuilder
    {
        $filters = (array) $request->query('filter', []);

        foreach ($allowed as $key => $handler) {
            $field = is_int($key) ? (string) $handler : (string) $key;

            if (!array_key_exists($field, $filters)) {
                continue;
            }

            $value = $filters[$field];

            if ($handler instanceof Closure) {
                $handler($query, $value, $request);
                continue;
            }

            if ($handler === true || is_int($key)) {
                $query->where($field, $value);
                continue;
            }

            if (is_string($handler)) {
                $query->where($handler, $value);
            }
        }

        return $query;
    }

    /**
     * Apply whitelisted sorts from ?sort=field,-created_at.
     */
    public static function applySort(EloquentBuilder|QueryBuilder $query, Request $request, array $allowed, ?string $default = null): EloquentBuilder|QueryBuilder
    {
        $sortParam = (string) $request->query('sort', $default ?? '');
        if ($sortParam === '') {
            return $query;
        }

        $fields = array_filter(array_map('trim', explode(',', $sortParam)));
        foreach ($fields as $field) {
            $direction = 'asc';
            $column = $field;

            if (str_starts_with($field, '-')) {
                $direction = 'desc';
                $column = substr($field, 1);
            }

            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $query->orderBy($column, $direction);
        }

        return $query;
    }
}
