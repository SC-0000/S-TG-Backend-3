<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;

abstract class ApiController extends Controller
{
    use ApiResponse;
}
