<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonInterface;

final readonly class UpdateUserGitHubAuthorization
{
    public function handle(User $user, ?string $token, ?CarbonInterface $expiresAt = null): void
    {
        $user->update([
            'github_issue_token' => $token,
            'github_issue_token_expires_at' => $expiresAt,
        ]);
    }
}
