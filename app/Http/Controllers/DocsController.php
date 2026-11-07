<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The application documentation: one set for institutions (shown according to each person's
 * roles and permissions) and one for the platform operator. Written as Markdown files in
 * resources/docs/{institution,platform}; each starts with a small header:
 *
 *   ---
 *   title: Running exam day
 *   section: Exams
 *   order: 80
 *   access: perm:exams.manage|perm:exams.proctor|perm:exams.invigilate   (or: any, staff, student, perm:billing.manage, module:conferencing)
 *   ---
 */
class DocsController extends Controller
{
    public static function load(string $set): array
    {
        $docs = [];
        foreach (glob(resource_path("docs/$set/*.md")) ?: [] as $file) {
            $raw = file_get_contents($file);
            $meta = [];
            if (preg_match('/^---\R(.*?)\R---\R/s', $raw, $m)) {
                foreach (preg_split('/\R/', $m[1]) as $line) {
                    if (str_contains($line, ':')) {
                        [$k, $v] = array_map('trim', explode(':', $line, 2));
                        $meta[$k] = $v;
                    }
                }
                $raw = substr($raw, strlen($m[0]));
            }
            $slug = preg_replace('/^\d+-/', '', basename($file, '.md'));
            $docs[$slug] = ['slug' => $slug, 'title' => $meta['title'] ?? Str::headline($slug), 'section' => $meta['section'] ?? 'General',
                'order' => (int) ($meta['order'] ?? 100), 'access' => $meta['access'] ?? 'any', 'body' => $raw];
        }
        uasort($docs, fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

        return $docs;
    }

    public static function allowed(?User $u, string $access): bool
    {
        if (! $u) {
            return false;
        }
        foreach (explode('|', $access) as $rule) {
            $rule = trim($rule);
            $ok = match (true) {
                $rule === 'any' => true,
                $rule === 'staff' => $u->user_type !== 'student',
                $rule === 'student' => $u->user_type === 'student',
                str_starts_with($rule, 'perm:') => $u->can(substr($rule, 5)),
                str_starts_with($rule, 'role:') => $u->hasRole(substr($rule, 5)),
                str_starts_with($rule, 'module:') => in_array(substr($rule, 7), app(\App\Services\Modules\ModuleRegistry::class)->enabledFor(tenant()), true),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function render(Request $request, string $set, ?string $slug, string $base): Response
    {
        $user = $request->user();
        $docs = array_filter(self::load($set), fn ($d) => $set === 'platform' || self::allowed($user, $d['access']));
        $q = trim((string) $request->query('q'));
        $current = $slug ? ($docs[$slug] ?? abort(404)) : (reset($docs) ?: abort(404));
        $html = Str::markdown($current['body'], ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        // headings get anchors so pages can link to a part
        $html = preg_replace_callback('/<h([23])>(.*?)<\/h\1>/', fn ($m) => '<h'.$m[1].' id="'.Str::slug(strip_tags($m[2])).'">'.$m[2].'</h'.$m[1].'>', $html);

        return Inertia::render('docs', [
            'base' => $base,
            'set' => $set,
            'nav' => collect($docs)->groupBy('section')->map(fn ($g, $s) => ['section' => $s, 'pages' => $g->map(fn ($d) => ['slug' => $d['slug'], 'title' => $d['title']])->values()])->values(),
            'page' => ['slug' => $current['slug'], 'title' => $current['title'], 'section' => $current['section'], 'html' => $html],
            'q' => $q,
            'results' => $q === '' ? [] : collect($docs)->map(function ($d) use ($q) {
                $text = preg_replace('/\s+/', ' ', strip_tags(Str::markdown($d['body'])));
                $pos = mb_stripos($text, $q);
                if ($pos === false && mb_stripos($d['title'], $q) === false) {
                    return null;
                }
                $start = max(0, (int) $pos - 70);
                $snippet = mb_substr($text, $start, 200);
                if ($start > 0) {
                    $snippet = '…'.ltrim(mb_substr($snippet, (int) mb_strpos($snippet, ' ')));
                }

                return ['slug' => $d['slug'], 'title' => $d['title'], 'snippet' => Str::limit(trim($snippet), 180)];
            })->filter()->values(),
        ]);
    }

    public function institution(Request $request, ?string $slug = null): Response
    {
        return $this->render($request, 'institution', $slug, '/docs');
    }

    public function platform(Request $request, ?string $slug = null): Response
    {
        return $this->render($request, 'platform', $slug, '/platform/docs');
    }
}
