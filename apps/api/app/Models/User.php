<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Role flags (is_influencer/is_restaurant_owner/is_admin) and stripe columns are
 * deliberately NOT fillable — they are granted by the system, never mass-assigned.
 *
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $stripe_connect_onboarded_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'avatar_path', 'bio', 'is_public', 'preferred_analysis_model'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Gate the Filament admin panel to admins — enforced in EVERY environment
     * (the panel is session-authed and separate from the API's Sanctum tokens).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'stripe_connect_onboarded_at' => 'datetime',
            'password' => 'hashed',
            'is_influencer' => 'boolean',
            'is_restaurant_owner' => 'boolean',
            'is_admin' => 'boolean',
            'is_public' => 'boolean',
        ];
    }
}
