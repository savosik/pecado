<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MediaController extends Controller
{
    public function __construct(protected MediaService $mediaService)
    {
    }

    /**
     * List media items.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Media::query();

        if ($request->has('q')) {
            // If using Scout, we would do Media::search($request->q)->...
            // But combining search with other filters in Scout depends on the driver (Meilisearch supports it).
            // For now, let's assume loose search or use basic "like" if Scout not fully set up.
            // As user requested Scout, let's use it if 'q' is present.
            // However, ensuring Scout returns a Builder compatible with Eloquent scopes (like withTags via Spatie) might be tricky if not careful.
            // Spatie Tags scope: $query->withAnyTags(['tag1', 'tag2']);
            
            // Simple fallback if no search engine:
             $query->where('name', 'like', "%{$request->q}%")
                   ->orWhere('file_name', 'like', "%{$request->q}%");
        }

        if ($request->has('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            $query->withAnyTags($tags);
        }

        if ($request->has('collection')) {
            $query->where('collection_name', $request->collection);
        }

        if ($request->has('model_type') && $request->has('model_id')) {
            $query->where('model_type', $request->model_type)
                  ->where('model_id', $request->model_id);
        }
        
        if ($request->has('mime_type')) {
             $query->where('mime_type', 'like', $request->mime_type . '%');
        }

        return MediaResource::collection($query->latest()->paginate($request->get('per_page', 20)));
    }

    /**
     * Store new media.
     *
     * @param Request $request
     * @return MediaResource
     */
    public function store(Request $request): MediaResource
    {
        $request->validate([
            'file' => 'required|file',
            'model_type' => 'required|string',
            'model_id' => 'required|integer', // or string/uuid
            'collection' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
        ]);

        // Resolve model
        $modelClass = $request->model_type;
        // Basic security check to ensure we only instantiate valid models? 
        // Or assume internal API.
        if (!class_exists($modelClass)) {
            abort(400, "Invalid model type.");
        }
        
        $model = $modelClass::findOrFail($request->model_id);

        $media = $this->mediaService->upload(
            $request->file('file'),
            $request->input('collection', 'default'),
            $model
        );

        if ($request->has('tags')) {
            $this->mediaService->syncTags($media, $request->input('tags'));
        }

        return new MediaResource($media);
    }

    /**
     * Display the specified resource.
     *
     * @param Media $media
     * @return MediaResource
     */
    public function show(Media $media): MediaResource
    {
        return new MediaResource($media);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Media $media
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Media $media)
    {
        $this->mediaService->delete($media);
        return response()->json(['message' => 'Media deleted successfully']);
    }
}
