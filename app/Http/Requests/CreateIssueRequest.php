<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;

final class CreateIssueRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user->hasGitHubIssueAuthorization()
            ? Response::allow()
            : Response::deny('Enable GitHub issue commands in Settings → Profile by granting public_repo permission.');
    }

    /**
     * @return array<string, array<int, string|ValidationRule|File|Dimensions|Enum|In>>
     */
    public function rules(): array
    {
        return [
            ...CreateCommitRequest::attachmentRules(),
            'command' => ['required', Rule::in(['/feature', '/bug'])],
            'body' => $this->hasFile('images') ? CreateCommitRequest::BODY_WITH_IMAGES_RULES : CreateCommitRequest::BODY_RULES,
            'poll' => ['prohibited'],
        ];
    }

    /** @return list<UploadedFile> */
    public function images(): array
    {
        $images = $this->file('images');

        return is_array($images) ? array_values($images) : [];
    }

    /** @return list<string|null> */
    public function imageDescriptions(): array
    {
        /** @var list<string|null> */
        return $this->array('image_descriptions');
    }
}
