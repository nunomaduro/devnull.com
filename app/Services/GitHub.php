<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class GitHub
{
    private const int PerPage = 100;

    public function request(?string $token = null): PendingRequest
    {
        $request = Http::baseUrl('https://api.github.com')
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout(3)
            ->timeout(5);

        if ($token !== null) {
            return $request->withToken($token);
        }

        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');

        // Authenticating as the OAuth app raises the rate limit from 60 to 5,000 requests per hour.
        if (is_string($clientId) && is_string($clientSecret)) {
            $request->withBasicAuth($clientId, $clientSecret);
        }

        return $request;
    }

    /**
     * Looks the account up by its immutable ID: a username can be renamed and then claimed by someone else.
     * Null when the account no longer exists.
     */
    public function login(string $githubId): ?string
    {
        $response = $this->request()->get("user/{$githubId}");

        if ($response->notFound()) {
            return null;
        }

        $login = $response->throw()->json('login');
        assert(is_string($login));

        return $login;
    }

    /**
     * The IDs of the accounts in a paginated list, such as the followers of a user, up to the given number of pages.
     *
     * @return list<string>
     */
    public function accountIds(string $path, int $maxPages): array
    {
        $ids = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            /** @var list<array{id: int}> $accounts */
            $accounts = $this->request()->get($path, ['per_page' => self::PerPage, 'page' => $page])->throw()->json();

            foreach ($accounts as $account) {
                $ids[] = (string) $account['id'];
            }

            if (count($accounts) < self::PerPage) {
                break;
            }
        }

        return $ids;
    }
}
