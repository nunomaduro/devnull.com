<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;

final class ShowIssueImageRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->hasValidSignature() && Storage::disk()->exists('commit-images/'.$this->route('image'))
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
