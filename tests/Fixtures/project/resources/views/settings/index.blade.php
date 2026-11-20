@foreach (App\Enums\SettingsTab::cases() as $tab)
    <h2>{{ $tab->title() }}</h2>
    <livewire:dynamic-component :is="$tab->component()" :key="$tab->value" />
@endforeach
