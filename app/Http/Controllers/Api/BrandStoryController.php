<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandStoryResource;
use App\Models\BrandStory;

class BrandStoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return BrandStoryResource::collection(BrandStory::paginate());
    }

    /**
     * Display the specified resource.
     */
    public function show(BrandStory $brandStory)
    {
        return new BrandStoryResource($brandStory);
    }
}
