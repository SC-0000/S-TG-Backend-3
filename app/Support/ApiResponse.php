<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge($this->defaultMeta($status), $meta),
            'errors' => [],
        ], $status);
    }

    protected function error(string $message, array $errors = [], int $status = 400, array $meta = []): JsonResponse
    {
        $errorItems = [];

        if ($message !== '') {
            $errorItems[] = ['message' => $message];
        }

        foreach ($errors as $error) {
            if (is_string($error)) {
                $errorItems[] = ['message' => $error];
                continue;
            }

            if (is_array($error)) {
                $errorItems[] = $error;
            }
        }

        return response()->json([
            'data' => null,
            'meta' => array_merge($this->defaultMeta($status), $meta),
            'errors' => $errorItems,
        ], $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, mixed $data, array $meta = []): JsonResponse
    {
        $pagination = [
            'total' => $paginator->total(),
            'count' => $paginator->count(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'total_pages' => $paginator->lastPage(),
        ];

        return response()->json([
            'data' => $data,
            'meta' => array_merge($this->defaultMeta(200), ['pagination' => $pagination], $meta),
            'errors' => [],
        ]);
    }

    protected function defaultMeta(int $status): array
    {
        return [
            'status' => $status,
            'request_id' => request()->attributes->get('request_id'),
        ];
    }
}
