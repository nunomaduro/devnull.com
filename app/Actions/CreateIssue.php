<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Tools\CreateGitHubIssue;
use App\Models\User;
use App\Services\GitHub;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

final readonly class CreateIssue
{
    public function __construct(private CreateCommitImageFiles $createImages, private GitHub $github, private UpdateUserGitHubAuthorization $updateAuthorization)
    {
        //
    }

    /**
     * @param  list<UploadedFile>  $images
     * @param  list<string|null>  $descriptions
     * @return array{number: int, url: string, labeled: bool}
     */
    public function handle(User $user, string $command, string $body, array $images = [], array $descriptions = []): array
    {
        $files = $this->createImages->handle($images, $descriptions);
        $issueBody = $body;

        foreach ($files as $file) {
            $url = URL::signedRoute('issue-image.show', ['image' => basename($file['path'])]);
            $description = htmlspecialchars($file['description'] ?? 'Attached image', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $issueBody .= "\n\n<img src=\"".htmlspecialchars($url, ENT_QUOTES, 'UTF-8')."\" alt=\"{$description}\" />";
        }

        $issueBody .= "\n\n<!-- dev-null-command:".mb_substr($command, 1).' -->';
        $token = $user->github_issue_token;
        assert(is_string($token));

        try {
            $result = (new CreateGitHubIssue($this->github, $token))->handle(new Request([
                'title' => Str::limit(Str::before($body, "\n"), 256, '') ?: ucfirst(mb_substr($command, 1)).' report',
                'body' => $issueBody,
                'label' => $command === '/feature' ? 'enhancement' : 'bug',
            ]));
        } catch (RequestException $exception) {
            if ($exception->response->clientError()) {
                Storage::disk()->delete(array_column($files, 'path'));
            }

            if ($exception->response->unauthorized()) {
                $this->updateAuthorization->handle($user, null);
            }

            throw $exception;
        }

        /** @var array{number: int, url: string, labeled: bool} */
        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }
}
