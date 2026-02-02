<?php

namespace App\Http\Middleware;

use App\Helpers\CartSession;
use App\Models\AdminTask;
use App\Models\Application;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Log;
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
public function share(Request $request): array
{
    $parent = parent::share($request);
    $user   = $request->user();                   // may be null
    $orgId  = null;
    $org    = null;

    if ($user) {
        $orgId = $user->role === 'super_admin' && $request->filled('organization_id')
            ? (int) $request->organization_id
            : $user->current_organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
    }

    // Which children to expose?
    $children = collect();

    if ($user) {
        if ($user->role === 'admin') {
            // admin sees all children, grouped with their parent info
            $children = \App\Models\User::with('children')->get()
                ->flatMap(fn ($u) =>
                    $u->children->map(fn ($c) => [
                        'id'       => $c->id,
                        'name'     => $c->child_name,
                        'userId'   => $u->id,
                        'userName' => $u->name,
                    ])
                );
        } else {
            // regular parent sees only their own
            $children = $user->children()
                ->select('id', 'child_name')
                ->get()
                ->map(fn ($c) => [
                    'id'       => $c->id,
                    'name'     => $c->child_name,
                    'userId'   => $user->id,
                    'userName' => $user->name,
                ]);
        }
    }

    // Load organization branding
    $orgBranding = null;
    
    // 🐛 DEBUG: Log user's organization ID
    // Log::info('🔍 Checking organization branding', [
    //     'user_id' => $user?->id,
    //     'current_organization_id' => $user?->current_organization_id,
    // ]);
    
    if ($org) {
        
        // Log::info('🏢 Found organization', [
        //     'org_id' => $org?->id,
        //     'org_name' => $org?->name,
        //     'settings' => $org?->settings, // 🐛 Log the entire settings array
        // ]);
        
        if ($org) {
            // 🐛 DEBUG: Log individual settings retrieval
            $contactPhone = $org->getSetting('contact.phone');
            $contactEmail = $org->getSetting('contact.email');
            $contactAddress = $org->getSetting('contact.address');
            $contactHours = $org->getSetting('contact.business_hours');
            
            // Log::info('📞 Contact settings extracted', [
            //     'phone' => $contactPhone,
            //     'email' => $contactEmail,
            //     'address' => $contactAddress,
            //     'hours' => $contactHours,
            // ]);
            
            $orgBranding = [
                // Brand Identity
                'name' => $org->getSetting('branding.organization_name'),
                'tagline' => $org->getSetting('branding.tagline'),
                'description' => $org->getSetting('branding.description'),
                'logo_url' => $org->getSetting('branding.logo_url'),
                'logo_dark_url' => $org->getSetting('branding.logo_dark_url'),
                'favicon_url' => $org->getSetting('branding.favicon_url'),
                
                // Theme Colors
                'colors' => $org->getSetting('theme.colors', [
                    'primary' => '#411183',
                    'primary_50' => '#F8F6FF',
                    'primary_100' => '#F0EBFF',
                    'primary_200' => '#E1D6FF',
                    'primary_300' => '#C9B8FF',
                    'primary_400' => '#A688FF',
                    'primary_500' => '#8B5CF6',
                    'primary_600' => '#7C3AED',
                    'primary_700' => '#6D28D9',
                    'primary_800' => '#5B21B6',
                    'primary_900' => '#411183',
                    'primary_950' => '#2E0F5C',
                    
                    'accent' => '#1F6DF2',
                    'accent_50' => '#EFF6FF',
                    'accent_100' => '#DBEAFE',
                    'accent_200' => '#BFDBFE',
                    'accent_300' => '#93C5FD',
                    'accent_400' => '#60A5FA',
                    'accent_500' => '#3B82F6',
                    'accent_600' => '#2563EB',
                    'accent_700' => '#1D4ED8',
                    'accent_800' => '#1E40AF',
                    'accent_900' => '#1F6DF2',
                    'accent_950' => '#172554',
                    
                    'accent_soft' => '#f77052',
                    'accent_soft_50' => '#FFF7F5',
                    'accent_soft_100' => '#FFEDE8',
                    'accent_soft_200' => '#FFD9D0',
                    'accent_soft_300' => '#FFBAA8',
                    'accent_soft_400' => '#FF9580',
                    'accent_soft_500' => '#FFA996',
                    'accent_soft_600' => '#FF6B47',
                    'accent_soft_700' => '#F04A23',
                    'accent_soft_800' => '#C73E1D',
                    'accent_soft_900' => '#A3341A',
                    
                    'secondary' => '#B4C8E8',
                    'heavy' => '#1F6DF2',
                ]),
                
                // Contact Information
                'contact' => [
                    'phone' => $contactPhone,
                    'email' => $contactEmail,
                    'address' => $contactAddress,
                    'business_hours' => $contactHours,
                ],
                
                // Social Media
                'social' => $org->getSetting('social_media', []),
                
                // Custom CSS
                'custom_css' => $org->getSetting('theme.custom_css'),
            ];
            
            // 🐛 DEBUG: Log the final branding array
            // Log::info('🎨 Final organization branding', [
            //     'branding' => $orgBranding,
            // ]);
        }
    }

    $pendingTasksQuery = AdminTask::query()
        ->where('status', 'Pending')
        ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
        ->where(function ($q) use ($user) {
            $q->whereNull('assigned_to');
            if ($user) {
                $q->orWhere('assigned_to', $user->id);
            }
        });

    $pendingTasksCount = (clone $pendingTasksQuery)->count();
    $pendingTaskItems = (clone $pendingTasksQuery)
        ->with('admin')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get(['id', 'task_type', 'created_at']);

    $pendingApplicationsCount = Application::where('application_status', 'pending')
        ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
        ->count();

    // Merge feature toggles: defaults -> org settings -> overrides
     $featureFlags = function () use ($org) {
        $defaults = config('features.defaults', []);
        $overrides = config('features.overrides', []);
        $systemOverrides = SystemSetting::getValue('feature_overrides', []);
        $orgFeatures = $org?->settings['features'] ?? [];

        return array_replace_recursive(
            $defaults,
            $orgFeatures,
            $systemOverrides,
            $overrides,
        );
    };

        return array_merge($parent, [
        'cartItemCount' => CartSession::current()?->items()->sum('quantity') ?? 0,
        'allChildren'   => $children,
        'selectedChild' => $request->get('child', 'all'),
        'organizationBranding' => $orgBranding,
        'auth'          => [
            'user' => $user
                ? ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'current_organization_id' => $user->current_organization_id]
                : null,
        ],
         'features' => fn () =>
            $request->user()
                ? $request->user()->subscriptions            // Collection
                    ->flatMap->features                      // merge feature-arrays
                    ->filter(fn ($v) => $v === true)         // keep flags set to true
                    ->keys()                                 // ["ai_analysis", …]
                : [],
            'featureFlags' => $featureFlags,
            'adminTasks' => [
                'count' => $pendingTasksCount,
                'items' => $pendingTaskItems->map(fn($t) => [
                    'id'   => $t->id,
                    'text' => $t->task_type,
                    'time' => $t->created_at->diffForHumans(),
                ]),
            ],
            'counts' => [
            'applications' => $pendingApplicationsCount,
            'tasks'        => $pendingTasksCount,
            ],
        ]);
    }
}
