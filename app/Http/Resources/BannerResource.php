<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $desktopMedia = $this->getFirstMedia('desktop');
        $mobileMedia = $this->getFirstMedia('mobile');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'link' => $this->link,
            'desktop_media' => $desktopMedia ? [
                'url' => $desktopMedia->getUrl(),
                'mime_type' => $desktopMedia->mime_type,
            ] : null,
            'mobile_media' => $mobileMedia ? [
                'url' => $mobileMedia->getUrl(),
                'mime_type' => $mobileMedia->mime_type,
            ] : null,
        ];
    }
}
