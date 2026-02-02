<?php

namespace App\Support;

use Illuminate\Http\Request;

class ApiPagination
{
    public static function perPage(Request $request, int $default = 15): int
    {
        $maxPerPage = (int) config('api.pagination.max_per_page', 100);
        $defaultPerPage = (int) config('api.pagination.default_per_page', $default);
        $perPage = (int) $request->query('per_page', $defaultPerPage);

        if ($perPage <= 0) {
            $perPage = $defaultPerPage;
        }

        return min($perPage, $maxPerPage);
    }
}
