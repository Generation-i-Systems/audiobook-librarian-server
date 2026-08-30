<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BadgeController extends Controller
{
    protected const IMAGE_DISK = 'public';
    protected const IMAGE_DIRECTORY = 'badges';

    public function index()
    {
        $badges = Badge::orderBy('category')->get()->sort(function ($a, $b) {
            // Sort by category first if not already handled
            if ($a->category !== $b->category) {
                return strcmp($a->category, $b->category);
            }
            if ($a->sort_order !== $b->sort_order) {
                return $a->sort_order <=> $b->sort_order;
            }
            // Tier weight
            $tiers = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4, 'diamond' => 5];
            $weightA = $tiers[$a->tier] ?? 99;
            $weightB = $tiers[$b->tier] ?? 99;
            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }
            return strcmp($a->name, $b->name);
        });

        $badgesWithIcons = $badges->map(function ($badge) {
            $iconPath = "images/badges/{$badge->key}.svg";
            $hasIconFile = file_exists(public_path($iconPath));

            // Create a new property dynamically or transform into array if preferred
            $badge->icon_path = $badge->image_url ?: ($hasIconFile ? "/{$iconPath}" : null);
            $badge->can_force_delete = ! $badge->userBadges()->exists();
            return $badge;
        });

        $badgesByCategory = $badgesWithIcons->groupBy('category');

        return view('admin.badges.index', [
            'badgesByCategory' => $badgesByCategory,
        ]);
    }

    public function create()
    {
        return view('admin.badges.create', [
            'badge' => new Badge(['criteria' => ['version' => 2, 'logic' => 'AND', 'conditions' => []]]),
            'categories' => Badge::CATEGORIES,
            'tiers' => Badge::TIERS,
            'criteriaTypes' => Badge::CRITERIA_TYPES,
            'operators' => Badge::RULE_OPERATORS,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $this->validateBadge($request);
            $criteria = $this->decodeCriteria($request);

            $data = [
                'key' => $validated['key'],
                'name' => $validated['name'],
                'description' => $validated['description'],
                'category' => $validated['category'],
                'tier' => $validated['tier'],
                'points' => $validated['points'],
                'is_repeatable' => $request->boolean('is_repeatable'),
                'sort_order' => $validated['sort_order'] ?? 0,
                'criteria' => $criteria,
                'created_by' => $this->resolveAdminIdentifier($request),
                'updated_by' => $this->resolveAdminIdentifier($request),
            ];

            $data['image_url'] = $this->storeUploadedImage($request, $validated['key']);
            $data['icon'] = $validated['icon'] ?? null;

            Badge::create($data);

            return redirect()->route('admin.badges.index')->with('success', 'Badge created successfully!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create badge: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create badge. Please try again.')->withInput();
        }
    }

    public function edit(Badge $badge)
    {
        return view('admin.badges.edit', [
            'badge' => $badge,
            'categories' => Badge::CATEGORIES,
            'tiers' => Badge::TIERS,
            'criteriaTypes' => Badge::CRITERIA_TYPES,
            'operators' => Badge::RULE_OPERATORS,
        ]);
    }

    public function update(Request $request, Badge $badge)
    {
        try {
            $validated = $this->validateBadge($request, $badge);
            $criteria = $this->decodeCriteria($request);

            $data = [
                'key' => $validated['key'],
                'name' => $validated['name'],
                'description' => $validated['description'],
                'category' => $validated['category'],
                'tier' => $validated['tier'],
                'points' => $validated['points'],
                'is_repeatable' => $request->boolean('is_repeatable'),
                'sort_order' => $validated['sort_order'] ?? 0,
                'criteria' => $criteria,
                'icon' => $validated['icon'] ?? null,
                'updated_by' => $this->resolveAdminIdentifier($request),
            ];

            if ($request->hasFile('image_file')) {
                $data['image_url'] = $this->storeUploadedImage($request, $validated['key']);
            }

            $badge->update($data);

            return redirect()->route('admin.badges.index')->with('success', 'Badge updated successfully!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update badge: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update badge. Please try again.')->withInput();
        }
    }

    /**
     * "Remove" a badge — deactivate only. Never hard-deletes, since user_badges history
     * (earned records, points already awarded) must never silently disappear.
     */
    public function destroy(Badge $badge)
    {
        $badge->update(['is_active' => false]);

        return redirect()->route('admin.badges.index')->with('success', 'Badge deactivated.');
    }

    public function activate(Badge $badge)
    {
        $badge->update(['is_active' => true]);

        return redirect()->route('admin.badges.index')->with('success', 'Badge activated.');
    }

    /**
     * Permanently delete a badge — only allowed when no user has ever earned it,
     * since deletion cascades to user_badges and would erase earned-badge history.
     */
    public function forceDestroy(Badge $badge)
    {
        if ($badge->userBadges()->exists()) {
            return redirect()->route('admin.badges.index')->with(
                'error',
                'Cannot permanently delete "' . $badge->name . '" — it has already been earned by users. Deactivate it instead.'
            );
        }

        $badge->delete();

        return redirect()->route('admin.badges.index')->with('success', 'Badge permanently deleted.');
    }

    protected function resolveAdminIdentifier(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            return 'admin';
        }

        return $user->email ?? $user->name ?? 'admin';
    }

    protected function validateBadge(Request $request, ?Badge $badge = null): array
    {
        return $request->validate([
            'key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('badges', 'key')->ignore($badge?->id),
            ],
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'icon' => 'nullable|string|max:16',
            'category' => ['required', 'string', Rule::in(array_keys(Badge::CATEGORIES))],
            'tier' => ['required', 'string', Rule::in(array_keys(Badge::TIERS))],
            'points' => 'required|integer|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_repeatable' => 'nullable|boolean',
            'criteria' => 'required|string',
            'image_file' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:512',
        ]);
    }

    /**
     * Decode and validate the rule-builder JSON submitted from the admin form. Rejects
     * unknown stat keys and operators so the stored criteria always match what
     * BadgeService/Badge::evaluateCriteria() can actually evaluate.
     */
    protected function decodeCriteria(Request $request): array
    {
        $raw = $request->input('criteria');
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'criteria' => 'The submitted rules are not valid JSON.',
            ]);
        }

        if (isset($decoded['conditions'])) {
            $this->assertValidConditionGroup($decoded);
            return $decoded;
        }

        // Legacy flat-map format: {stat_key: threshold_or_range}
        foreach ($decoded as $stat => $requirement) {
            if (! array_key_exists($stat, Badge::CRITERIA_TYPES)) {
                throw ValidationException::withMessages([
                    'criteria' => "Unknown stat key: {$stat}",
                ]);
            }
        }

        return $decoded;
    }

    protected function assertValidConditionGroup(array $node): void
    {
        if (isset($node['conditions'])) {
            if (! is_array($node['conditions'])) {
                throw ValidationException::withMessages(['criteria' => 'Invalid rule group.']);
            }

            foreach ($node['conditions'] as $child) {
                if (! is_array($child)) {
                    throw ValidationException::withMessages(['criteria' => 'Invalid rule condition.']);
                }
                $this->assertValidConditionGroup($child);
            }

            return;
        }

        $stat = $node['stat'] ?? null;
        $operator = $node['operator'] ?? null;

        if (! is_string($stat) || ! array_key_exists($stat, Badge::CRITERIA_TYPES)) {
            throw ValidationException::withMessages([
                'criteria' => 'Unknown stat key: ' . (is_string($stat) ? $stat : '(missing)'),
            ]);
        }

        if (! is_string($operator) || ! array_key_exists($operator, Badge::RULE_OPERATORS)) {
            throw ValidationException::withMessages([
                'criteria' => 'Unknown operator: ' . (is_string($operator) ? $operator : '(missing)'),
            ]);
        }

        if (! array_key_exists('value', $node)) {
            throw ValidationException::withMessages([
                'criteria' => "Condition for {$stat} is missing a value.",
            ]);
        }
    }

    /**
     * Store an uploaded badge image on the public disk. PNG/JPG/WEBP only — SVG uploads are
     * intentionally not accepted here to avoid the XSS risk of user-uploaded SVG markup;
     * artisan-generated SVGs at public/images/badges remain the only SVG source.
     */
    protected function storeUploadedImage(Request $request, string $key): ?string
    {
        if (! $request->hasFile('image_file')) {
            return $request->input('image_url') ?: null;
        }

        $file = $request->file('image_file');
        $filename = $key . '-' . Str::random(8) . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs(self::IMAGE_DIRECTORY, $filename, self::IMAGE_DISK);

        return Storage::disk(self::IMAGE_DISK)->url($path);
    }
}
