<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateIssue;
use App\Http\Requests\CreateIssueRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

final readonly class IssueController
{
    public function store(CreateIssueRequest $request, #[CurrentUser] User $user, CreateIssue $action): JsonResponse
    {
        try {
            return response()->json($action->handle(
                $user,
                $request->string('command')->value(),
                $request->string('body')->value(),
                $request->images(),
                $request->imageDescriptions(),
            ), 201);
        } catch (ConnectionException|RequestException) {
            return response()->json([
                'message' => $user->hasGitHubIssueAuthorization()
                    ? 'GitHub did not confirm the issue. Your draft is safe. Check the upstream issues before trying again.'
                    : 'GitHub authorization is no longer valid. Enable issue commands again in settings. Your draft is safe.',
            ], $user->hasGitHubIssueAuthorization() ? 502 : 403);
        }
    }
}
