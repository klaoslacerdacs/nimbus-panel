@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-shell-content">
        <div class="auth-card">
            <div class="auth-card-heading">
                <x-nimbus-brand size="lg" class="mb-4" />
                <h1>{{ $title }}</h1>
                @if ($description)
                    <p>{{ $description }}</p>
                @endif
            </div>

            <div class="auth-card-body">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="auth-card-footer">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</section>
