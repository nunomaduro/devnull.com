<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\GitHub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class CreateGitHubIssue implements Tool
{
    public function __construct(private GitHub $github, private string $token)
    {
        //
    }

    public function description(): string
    {
        return 'Create a public feature or bug issue in nunomaduro/dev-null.sh from the exact draft and its image links.';
    }

    public function handle(Request $request): string
    {
        $github = $this->github->request($this->token);
        $label = $request->string('label')->value();

        $response = $github->post('repos/nunomaduro/dev-null.sh/issues', [
            'title' => $request->string('title')->value(),
            'body' => $request->string('body')->value(),
            'labels' => [$label],
        ])->throw();

        return json_encode([
            'number' => $response->json('number'),
            'url' => $response->json('html_url'),
            'labeled' => $response->collect('labels')->contains('name', $label),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(256)->required(),
            'body' => $schema->string()->required(),
            'label' => $schema->string()->enum(['enhancement', 'bug'])->required(),
        ];
    }
}
