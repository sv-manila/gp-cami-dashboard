<?php

namespace App\Http\Controllers;

use App\Models\GpProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** One page: Golden Profile Stats + name search (results list only). */
    public function index(Request $request)
    {
        $conn = config('gpcami.connection');
        $ttl  = (int) config('gpcami.cache_ttl', 60);

        $stats = Cache::remember('gpcami.stats', $ttl, function () use ($conn) {
            $out = [];
            foreach (config('gpcami.stats_tables') as $label => $table) {
                try {
                    // Exact count, but capped at 800ms so a busy hub (e.g. mid-backfill)
                    // can't hang the page. COUNT(*) over a 13M-row table queued behind
                    // heavy writes would otherwise block for tens of seconds.
                    $r = DB::connection($conn)->selectOne("SELECT /*+ MAX_EXECUTION_TIME(800) */ COUNT(*) c FROM `$table`");
                    $out[$label] = ['count' => (int) $r->c, 'error' => null, 'approx' => false];
                } catch (\Throwable $e) {
                    // Fell over the cap (or errored) — use the instant approximate
                    // row estimate from table metadata instead of blocking.
                    try {
                        $r = DB::connection($conn)->selectOne(
                            'SELECT table_rows c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                            [$table],
                        );
                        $out[$label] = ['count' => (int) ($r->c ?? 0), 'error' => null, 'approx' => true];
                    } catch (\Throwable $e2) {
                        $out[$label] = ['count' => null, 'error' => 'unavailable', 'approx' => false];
                    }
                }
            }
            return $out;
        });

        $first = trim((string) $request->query('first_name', ''));
        $last  = trim((string) $request->query('last_name', ''));
        $searched = $request->has('first_name') || $request->has('last_name');

        $results = collect();
        $error = null;

        if ($searched && ($first !== '' || $last !== '')) {
            try {
                $results = GpProfile::byName($first, $last)->limit(500)->get();
            } catch (\Throwable $e) {
                $error = 'gp-cami query failed: ' . $e->getMessage();
            }
        }

        return view('dashboard', compact('stats', 'first', 'last', 'searched', 'results', 'error'));
    }

    /** Full details for one identity (right-side panel, loaded on name click). */
    public function show($identity)
    {
        $profile = GpProfile::findOrFail($identity);

        // AJAX (from the search panel) gets the bare partial; a direct visit to
        // the URL gets the full page with the shared nav/header wrapper.
        if (request()->ajax() || request()->wantsJson()) {
            return view('partials.profile-detail', compact('profile'));
        }

        return view('profile', compact('profile'));
    }
}
