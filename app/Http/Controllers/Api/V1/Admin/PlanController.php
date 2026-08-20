<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    /**
     * Catalogue des plans. Non cloisonné : les plans sont globaux.
     */
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->orderBy('position')->get()
        );
    }
}
