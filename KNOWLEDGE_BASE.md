# /dev/null knowledge base

/dev/null is a social network for developers, built in public during a livestream. Developers sign in with GitHub, share posts called **commits**, follow one another, and read timelines called **logs**.

This file records the stack and the stories behind the platform. Technology names intentionally have no version numbers.

## Contents

- [Repository](#repository)
- [Stack and hosting](#stack-and-hosting)
- [Lore](#lore)
  - [Built on a livestream](#built-on-a-livestream)
  - [Tom](#tom)
  - [Pastéis de nata](#pastéis-de-nata)

## Repository

- **Root / upstream repository:** [nunomaduro/dev-null.sh](https://github.com/nunomaduro/dev-null.sh).
- **This fork:** [paulinevos/dev-null.sh](https://github.com/paulinevos/dev-null.sh).
- **Setup and development:** [README](README.md).
- **Product terminology and behavior:** [Product guide](PRODUCT.md).

## Stack and hosting

| Area | Technology and role |
| --- | --- |
| Hosting | **Laravel Cloud** hosts the application. |
| Backend | **PHP** and **Laravel** handle the application, routes, database access, and background jobs. |
| Frontend | **React** and **TypeScript**, connected to Laravel through **Inertia**. |
| Styling and components | **Tailwind CSS**, **Radix UI**, and **Headless UI**, with **Lucide** icons. |
| Database | **PostgreSQL**, with **pgvector** for vector data and **pg_trgm** for text matching. |
| Authentication | **GitHub OAuth** through **Laravel Socialite**. GitHub is the source of truth for user identity and profile information. |
| Frontend routes | **Laravel Wayfinder** generates typed route helpers. |
| Asset tooling | **Vite+ / Vite** builds frontend assets; **Bun** manages frontend dependencies and runs scripts. |
| PHP dependencies | **Composer** manages backend dependencies. |
| Testing and quality | **Pest**, **PHPStan / Larastan**, **TypeScript**, **Laravel Pint**, **Rector**, and frontend formatting and linting through **Vite+**. |
| Existing AI services | **Laravel AI SDK** generates and caches semantic search query embeddings through **OpenAI**. The existing OpenAI integration handles batch embeddings, commit relevance checks, and moderation. This is separate from Tom's planned DeepSeek role. |

The repository defaults to database-backed queues, cache, and sessions. File storage is configurable, with local and S3-compatible storage supported. These are repository defaults and supported options, not a claim about every production environment setting.

The knowledge base is split into Markdown sections by heading and stored with vector embeddings in PostgreSQL. The repository includes a GitHub Actions workflow to refresh new or changed sections after knowledge-base changes reach production on Laravel Cloud, once its [CI credentials are configured](README.md#automatic-production-updates). Unchanged sections reuse their embeddings.

Stack references: [PHP dependencies](composer.json), [frontend dependencies](package.json), [database configuration](config/database.php), [setup requirements](README.md#getting-started), [queue configuration](config/queue.php), [cache configuration](config/cache.php), [session configuration](config/session.php), [storage configuration](config/filesystems.php), and [existing AI integration](app/Services/OpenAI.php). Laravel Cloud hosting is confirmed by the project team.

## Lore

### Built on a livestream

/dev/null began with the challenge **“Building a Social Network in PHP in 48 Hours.”** Building the platform together on the livestream was the point of the project, not a broadcast added after it was built. Viewers could watch it take shape and contribute along the way.

The livestream team included:

- **Nuno Maduro** — [@nunomaduro](https://github.com/nunomaduro).
- **Brent Roose** — [@brendt](https://github.com/brendt).
- **Matthieu Napoli** — [@mnapoli](https://github.com/mnapoli).
- **Pauline Vos** — [@paulinevos](https://github.com/paulinevos).

They built live from the JetBrains office, with community contributions becoming part of the project.

Watch the livestreams:

- [Day one — Building a Social Network in PHP in 48 Hours](https://www.youtube.com/watch?v=6zCNQa3hNm0).
- [Day two — Building a Social Network in PHP in 48 Hours](https://www.youtube.com/watch?v=nfmVdXZfzUE).

Sources: [Laravel News's livestream announcement](https://laravel-news.com/social-network-php-48-hours) names Nuno, Brent, and Matthieu and links both days; Pauline confirms her participation as part of the livestream team. The repository's [livestream component](resources/js/components/live-stream.tsx) links day two.

### Tom

Tom is a fictional profile inspired by **MySpace Tom**. He is everyone's mutual at the beginning: the familiar face who is already there when you arrive.

Originally, Tom had no particular purpose beyond that nod to MySpace. The plan now is to give him one: **Tom will become a bot powered by DeepSeek, using this knowledge base to answer questions about /dev/null, its stack, and its lore.**

That bot role is planned, not a claim that the DeepSeek integration is already implemented. Tom is a fictional platform character, not the real MySpace Tom.

Source: the project team's account of Tom's origin and intended role.

### Pastéis de nata

During the livestream, **Fernando Andrade — [@fernandofreamunde](https://dev-null.sh/@fernandofreamunde)** — brought **pastéis de nata**, Portuguese custard pastries, for the team.

The **pastel emoji** and **Pastéis mode** are a tribute to Fernando and that delivery. A real-world act of feeding the team became part of the platform's shared lore.

The singular is **pastel de nata**; the plural is **pastéis de nata**. The mode is called **Pastéis mode**.

Sources:

- [Fernando's post arranging delivery to the crew](https://dev-null.sh/@fernandofreamunde/commits/01a144fe-ed29-7356-af93-03b9724e8af6).
- [Matthieu's reply thanking him for the pastéis de nata](https://dev-null.sh/@mnapoli/commits/01a1453f-ac59-7077-b99e-98d72547ae8f).
- [Pastéis mode contribution](https://github.com/nunomaduro/dev-null.sh/pull/286).
- The project team's account confirms that the emoji and mode are a tribute to Fernando.
