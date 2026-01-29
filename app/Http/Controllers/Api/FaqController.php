<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return FaqResource::collection(Faq::paginate());
    }

    /**
     * Display the specified resource.
     */
    public function show(Faq $faq)
    {
        return new FaqResource($faq);
    }
}
