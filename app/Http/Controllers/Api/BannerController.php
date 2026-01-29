<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return BannerResource::collection(Banner::paginate());
    }

    /**
     * Display the specified resource.
     */
    public function show(Banner $banner)
    {
        return new BannerResource($banner);
    }
}
