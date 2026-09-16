@csrf

@if ($badge->exists)
    @method('PUT')
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="key">Key (unique, snake_case):</label>
            <input type="text" class="form-control" id="key" name="key"
                value="{{ old('key', $badge->key) }}" pattern="[a-z0-9_]+" required
                {{ $badge->exists ? 'readonly' : '' }}>
        </div>

        <div class="form-group mt-3">
            <label for="name">Name:</label>
            <input type="text" class="form-control" id="name" name="name"
                value="{{ old('name', $badge->name) }}" required>
        </div>

        <div class="form-group mt-3">
            <label for="description">Description:</label>
            <textarea class="form-control" id="description" name="description" rows="3" required>{{ old('description', $badge->description) }}</textarea>
        </div>

        <div class="row mt-3">
            <div class="col">
                <label for="category">Category:</label>
                <select class="form-select" id="category" name="category" required>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" {{ old('category', $badge->category) === $value ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col">
                <label for="tier">Tier:</label>
                <select class="form-select" id="tier" name="tier" required>
                    @foreach ($tiers as $value => $label)
                        <option value="{{ $value }}" {{ old('tier', $badge->tier) === $value ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col">
                <label for="points">Points:</label>
                <input type="number" class="form-control" id="points" name="points" min="0"
                    value="{{ old('points', $badge->points ?? 0) }}" required>
            </div>
            <div class="col">
                <label for="sort_order">Sort order:</label>
                <input type="number" class="form-control" id="sort_order" name="sort_order" min="0"
                    value="{{ old('sort_order', $badge->sort_order ?? 0) }}">
            </div>
        </div>

        <div class="form-check mt-3">
            <input type="checkbox" class="form-check-input" id="is_repeatable" name="is_repeatable" value="1"
                {{ old('is_repeatable', $badge->is_repeatable) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_repeatable">Repeatable (can be earned more than once)</label>
        </div>

        <div class="form-group mt-3">
            <label for="icon">Emoji fallback icon:</label>
            <input type="text" class="form-control" id="icon" name="icon" maxlength="16"
                value="{{ old('icon', $badge->icon) }}" placeholder="🏆">
        </div>

        <div class="form-group mt-3">
            <label for="image_file">Badge image (PNG/JPG/WEBP, max 512KB):</label>
            <input type="file" class="form-control" id="image_file" name="image_file" accept=".png,.jpg,.jpeg,.webp">
            @if ($badge->image_url)
                <div class="mt-2">
                    <img src="{{ $badge->image_url }}" alt="{{ $badge->name }}" style="width: 64px; height: 64px;">
                    <small class="text-muted d-block">Current image — upload a new file to replace it.</small>
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-6">
        <label class="d-block">Rules (all conditions in the top group must match, unless set to "Match OR"):</label>
        <div data-rule-builder
            data-criteria-types="{{ json_encode($criteriaTypes) }}"
            data-operators="{{ json_encode($operators) }}"
            data-initial-criteria="{{ json_encode(old('criteria') ? json_decode(old('criteria'), true) : $badge->criteria) }}"
            data-output-field="criteria-json">
            <div class="rule-builder-tree"></div>
        </div>
        <textarea id="criteria-json" name="criteria" class="d-none"></textarea>
    </div>
</div>

<button type="submit" class="btn btn-primary mt-4">{{ $badge->exists ? 'Save Changes' : 'Create Badge' }}</button>
<a href="{{ route('badges.index') }}" class="btn btn-secondary mt-4">Cancel</a>

@vite(['resources/js/admin/badges/rule-builder.js'])
