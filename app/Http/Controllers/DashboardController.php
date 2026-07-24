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
                    $out[$label] = ['count' => DB::connection($conn)->table($table)->count(), 'error' => null];
                } catch (\Throwable $e) {
                    $out[$label] = ['count' => null, 'error' => 'unavailable'];
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
        return view('partials.profile-detail', compact('profile'));
    }
}
