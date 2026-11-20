<?php declare(strict_types=1);

namespace App\Enums;

use App\Livewire\NotificationSettings;
use App\Livewire\StatusPanel;
use App\Models\Post;

/** A settings screen that renders one Livewire component per tab, picked at runtime. */
enum SettingsTab: string
{
    case STATUS = 'status';
    case NOTIFICATIONS = 'notifications';

    public function component(): string
    {
        return match ($this) {
            self::STATUS => StatusPanel::class,
            self::NOTIFICATIONS => NotificationSettings::class,
        };
    }

    /** Names a class too, but no Livewire component: a view that calls it links nothing more. */
    public function title(): string
    {
        return class_basename(Post::class) . " {$this->value}";
    }
}
